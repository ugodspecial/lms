<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Support\Logging\SensitiveDataScrubber;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The single write path into the generic audit trail (§56, ADR-12).
 *
 * One class owns this for a reason. An audit row is only worth anything if every
 * change produces one, and "every" is not achievable by asking each feature to
 * remember. So the entries are written here, redaction happens on the way in, and
 * the caller cannot opt out of either.
 *
 * Two rules give the trail its value, and both are enforced rather than hoped
 * for:
 *
 * • SAME TRANSACTION. Nothing here commits or catches. Called from inside a
 *   domain action's transaction — which is where every mutating action lives
 *   (ADR-14) — the audit row and the change it describes commit together or
 *   neither does. A change that happened without a record is indistinguishable
 *   from tampering, so that state must be unreachable.
 *
 * • REDACTED ON WRITE, NOT ON RENDER. `audit_logs` is readable by
 *   administrators, so a stored password hash or 2FA secret would make it the
 *   most valuable table in the database, and hiding it in the UI would not
 *   change that (§77). SensitiveDataScrubber runs before the INSERT.
 *
 * Malformed input throws instead of being trimmed or dropped. An event name over
 * 80 characters or a tag containing a comma is a programming error — those are
 * literals in code, not user input — and failing loudly in the enclosing
 * transaction is better than silently writing a truncated compliance record that
 * nobody notices until an investigation needs it.
 *
 * Event names are dotted, `subject.action` (`settings.updated`,
 * `tutors.approved`), so the indexed `event` column can be prefix-matched to
 * list a whole domain's history (see AuditLog::scopeOfEventPrefix()).
 *
 * The `Auditable` trait that fires this from Eloquent events on every auditable
 * model arrives with the first academic models in Phase 2; ADR-12 keeps the two
 * trails separate, and this service is the generic one.
 */
final class AuditLogger
{
    /** `audit_logs.event` is VARCHAR(80). */
    private const MAX_EVENT_LENGTH = 80;

    /** Tags are stored comma-joined in one column; a long tag is a mistake. */
    private const MAX_TAG_LENGTH = 40;

    public function __construct(
        private readonly SensitiveDataScrubber $scrubber,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Write one audit entry and return it.
     *
     * The actor defaults to the authenticated user, which is the right answer
     * inside a request and the wrong one nowhere else: a console or queued caller
     * has no authenticated user, so the row records a null actor — meaning "the
     * platform did this", which is a real and useful thing to record. A caller
     * that acts on somebody's behalf (an administrator editing a student's
     * record, an impersonation session) passes the actor explicitly.
     *
     * @param  array<array-key, mixed>  $oldValues  the before state, empty for a creation
     * @param  array<array-key, mixed>  $newValues  the after state, empty for a deletion
     * @param  array<int, string>  $tags  filter labels, e.g. ['settings', 'payments']
     *
     * @throws InvalidArgumentException if the event name or a tag is malformed
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        array $tags = [],
        ?User $actor = null,
    ): AuditLog {
        $this->assertEventShape($event);

        $tags = $this->normaliseTags($tags);

        [$ipAddress, $userAgent] = $this->requestContext();

        // AuditLog is append-only and guarded, so every attribute is stamped
        // before a single save(): a second save would hit performUpdate() and
        // throw, by design.
        $log = new AuditLog;

        $log->event = $event;
        $log->user_id = ($actor ?? $this->currentActor())?->id;

        if ($subject !== null) {
            $log->auditable_type = $subject::class;
            $log->auditable_id = $subject->getKey();
        }

        // An empty before/after state is stored as NULL rather than as `[]`.
        // "There was no previous value" and "the previous value was an empty
        // set" are different claims, and an investigation reads them differently.
        $log->old_values = $oldValues === [] ? null : $this->scrubber->scrubArray($oldValues);
        $log->new_values = $newValues === [] ? null : $this->scrubber->scrubArray($newValues);

        $log->ip_address = $ipAddress;
        $log->user_agent = $userAgent;
        $log->tags = $tags === [] ? null : implode(',', $tags);

        $log->save();

        return $log;
    }

    /** The authenticated user, or null when there is none (console, queue, guest). */
    private function currentActor(): ?User
    {
        $user = $this->auth->user();

        // Narrowed rather than assumed: the guard is typed Authenticatable, and
        // audit_logs.user_id is a foreign key to users.
        return $user instanceof User ? $user : null;
    }

    /**
     * Where the request came from.
     *
     * Both are nullable in the schema because a scheduled job has neither, and
     * an audit entry from the retention pruner is still worth having.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function requestContext(): array
    {
        // Resolved defensively: in a console or queued context the container may
        // have no request instance at all, and an audit write must not be the
        // thing that fails a scheduled job.
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return [null, null];
        }

        return [$request->ip(), $request->userAgent()];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertEventShape(string $event): void
    {
        $event = trim($event);

        if ($event === '') {
            throw new InvalidArgumentException('An audit event name is required; an unnamed entry cannot be searched for.');
        }

        if (mb_strlen($event) > self::MAX_EVENT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Audit event "%s" is %d characters; the column holds %d.', $event, mb_strlen($event), self::MAX_EVENT_LENGTH)
            );
        }

        // Dotted lowercase is what makes scopeOfEventPrefix() work. Enforcing the
        // shape here keeps 'Settings.Updated' and 'settings.updated' from being
        // two different histories of the same thing.
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $event) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Audit event "%s" must be dotted lowercase subject.action, e.g. "settings.updated".', $event)
            );
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function normaliseTags(array $tags): array
    {
        $normalised = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException('Audit tags must be strings.');
            }

            $tag = trim($tag);

            if ($tag === '') {
                continue;
            }

            // A comma inside a tag would split into two on read, so AuditLog::tagList()
            // would report a tag that was never written.
            if (str_contains($tag, ',')) {
                throw new InvalidArgumentException(sprintf('Audit tag "%s" may not contain a comma; tags are stored comma-joined.', $tag));
            }

            if (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                throw new InvalidArgumentException(sprintf('Audit tag "%s" is longer than %d characters.', $tag, self::MAX_TAG_LENGTH));
            }

            $normalised[] = $tag;
        }

        return array_values(array_unique($normalised));
    }
}
