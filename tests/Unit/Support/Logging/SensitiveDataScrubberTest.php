<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Logging;

use App\Support\Logging\SensitiveDataScrubber;
use Illuminate\Support\Carbon;
use stdClass;
use Stringable;
use Tests\TestCase;

/**
 * Redaction has to be structural, and structural means it has to be right about
 * two opposite failure modes (§77, ADR-11 §8, ADR-12).
 *
 * Too little and a plaintext password lands in `audit_logs`, which is readable by
 * administrators and therefore becomes the most valuable table in the database.
 * Too much and the audit trail stops being evidence: an entry that says
 * `grade_code: [REDACTED] → [REDACTED]` records that somebody changed something,
 * and nothing an investigation can use.
 *
 * The second failure is the one that gets missed, because it is invisible until
 * somebody needs the record. That is why the "ordinary business keys" test below
 * is as load-bearing as the secrets one: coupons, subjects, grades and cohorts all
 * have a `code`, countries have a `country_code`, and a redaction list built from
 * good intentions would have blanked every one of them.
 *
 * No database here on purpose. The scrubber is a pure function of its config, so
 * the list can be swapped per test and the matching rules examined directly —
 * which is the only way to test a wildcard without inventing a secret to leak.
 */
final class SensitiveDataScrubberTest extends TestCase
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $patterns
     */
    private function scrubber(array $keys = [], array $patterns = []): SensitiveDataScrubber
    {
        if ($keys !== []) {
            config(['platform.logging.redact_keys' => $keys]);
        }

        if ($patterns !== []) {
            config(['platform.logging.redact_value_patterns' => $patterns]);
        }

        return new SensitiveDataScrubber;
    }

    // ── Key matching ────────────────────────────────────────────────────────

    public function test_the_documented_secret_names_are_sensitive(): void
    {
        $scrubber = $this->scrubber();

        foreach ([
            'password', 'password_confirmation', 'current_password',
            'token', 'access_token', 'refresh_token', 'token_hash',
            'api_key', 'client_secret', 'secret_key', 'private_key',
            'two_factor_secret', 'two_factor_recovery_codes',
            'otp', 'otp_code', 'verification_code',
            'card', 'card_number', 'cvv', 'cvc', 'exp_month',
            'authorization', 'cookie', 'x-paystack-signature',
            // Prefix entries, so a raw provider key under any field name is caught.
            'sk_live_9d8a7b', 'pk_test_1a2b3c',
        ] as $key) {
            $this->assertTrue($scrubber->isSensitiveKey($key), sprintf('"%s" must be treated as sensitive.', $key));
        }
    }

    public function test_ordinary_business_keys_are_not_collateral_damage(): void
    {
        $scrubber = $this->scrubber();

        // Every one of these is a real column on a real Phase-2+ table, and every
        // one of them would be blanked by a bare `code` entry or a `*_code`
        // wildcard. An audit trail that cannot show which grade scale changed is
        // not an audit trail.
        foreach ([
            'grade_code', 'coupon_code', 'subject_code', 'cohort_code',
            'country_code', 'postal_code', 'question_code',
            'score', 'total_amount_minor', 'currency', 'academic_year',
            'platform_fee_percent', 'age_of_majority',
        ] as $key) {
            $this->assertFalse($scrubber->isSensitiveKey($key), sprintf('"%s" is business data and must survive redaction.', $key));
        }
    }

    public function test_matching_ignores_case_and_surrounding_space(): void
    {
        $scrubber = $this->scrubber();

        // Payload keys arrive from request data and from other people's code, and
        // `Password` is the same secret as `password`.
        $this->assertTrue($scrubber->isSensitiveKey('Password'));
        $this->assertTrue($scrubber->isSensitiveKey('ACCESS_TOKEN'));
        $this->assertTrue($scrubber->isSensitiveKey('  client_secret  '));
    }

    public function test_a_wildcard_entry_matches_what_it_describes(): void
    {
        $scrubber = $this->scrubber(keys: ['*_secret', 'card*']);

        $this->assertTrue($scrubber->isSensitiveKey('client_secret'));
        $this->assertTrue($scrubber->isSensitiveKey('zoom_secret'));
        $this->assertTrue($scrubber->isSensitiveKey('card_number'));

        // `*_secret` requires the underscore, and `card*` requires the prefix.
        // A wildcard that matched loosely would be a bare substring search, which
        // is how `secretary_name` and `scorecard_total` get blanked.
        $this->assertFalse($scrubber->isSensitiveKey('secret'));
        $this->assertFalse($scrubber->isSensitiveKey('scorecard'));
    }

    public function test_an_empty_entry_cannot_redact_the_whole_payload(): void
    {
        // str_starts_with($anything, '') is true, so one stray comma in the config
        // array would silently blank every field the platform records.
        $scrubber = $this->scrubber(keys: ['', 'password', '   ']);

        $this->assertFalse($scrubber->isSensitiveKey('grade_code'));
        $this->assertFalse($scrubber->isSensitiveKey('anything_at_all'));
        $this->assertTrue($scrubber->isSensitiveKey('password'));

        $this->assertSame(
            ['grade_code' => 'A1', 'password' => SensitiveDataScrubber::REDACTED],
            $scrubber->scrubArray(['grade_code' => 'A1', 'password' => 'hunter2']),
        );
    }

    // ── Payload scrubbing ───────────────────────────────────────────────────

    public function test_a_nested_payload_is_redacted_at_every_level(): void
    {
        $scrubber = $this->scrubber();

        $this->assertSame([
            'user' => [
                'name' => 'Amaka Obi',
                'password' => SensitiveDataScrubber::REDACTED,
                'roles' => ['Parent', 'Student'],
                'connection' => ['refresh_token' => SensitiveDataScrubber::REDACTED],
            ],
            'changed_at' => '2026-09-10 09:00:00',
        ], $scrubber->scrubArray([
            'user' => [
                'name' => 'Amaka Obi',
                'password' => 'hunter2',
                'roles' => ['Parent', 'Student'],
                'connection' => ['refresh_token' => 'a-real-token'],
            ],
            'changed_at' => '2026-09-10 09:00:00',
        ]));
    }

    public function test_the_key_survives_even_though_the_value_does_not(): void
    {
        $scrubber = $this->scrubber();

        $scrubbed = $scrubber->scrubArray(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        // Which field changed is part of the record. An auditor needs to see that
        // a 2FA secret was written, and only its value is not theirs to have.
        $this->assertArrayHasKey('two_factor_secret', $scrubbed);
        $this->assertSame(SensitiveDataScrubber::REDACTED, $scrubbed['two_factor_secret']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', json_encode($scrubbed, JSON_THROW_ON_ERROR));
    }

    public function test_list_keys_are_values_and_still_get_scrubbed(): void
    {
        $scrubber = $this->scrubber();

        // A payload can be a list of records; the keys are integers there, and the
        // sensitive names are one level down.
        $this->assertSame(
            [['password' => SensitiveDataScrubber::REDACTED], ['password' => SensitiveDataScrubber::REDACTED]],
            $scrubber->scrubArray([['password' => 'one'], ['password' => 'two']]),
        );
    }

    // ── Value patterns ──────────────────────────────────────────────────────

    public function test_a_secret_inside_free_text_is_redacted_by_pattern(): void
    {
        $scrubber = $this->scrubber();

        // The dangerous case is a key that arrives under an innocent field name:
        // pasted into a support note, or captured into an exception message along
        // with the header that carried it. There is no sensitive key to match on,
        // so the value itself has to be recognised.
        $this->assertSame(
            'checkout failed with '.SensitiveDataScrubber::REDACTED.' — retry',
            $scrubber->scrubString('checkout failed with sk_test_9a8b7c6d5e — retry'),
        );

        $this->assertSame(
            'header was '.SensitiveDataScrubber::REDACTED.' and nothing else',
            $scrubber->scrubString('header was Bearer eyJhbGciOi.abc-123_XYZ and nothing else'),
        );
    }

    public function test_a_value_pattern_leaves_ordinary_text_alone(): void
    {
        $scrubber = $this->scrubber();

        foreach ([
            'The parent approved the booking for Primary 5 Mathematics.',
            'Refund of 15000 minor units issued against order #4412.',
            'skate park', // starts like `sk` and is not a key
            'A meeting link was sent to the parent at 09:00 UTC.',
        ] as $text) {
            $this->assertSame($text, $scrubber->scrubString($text));
        }
    }

    public function test_a_malformed_pattern_cannot_break_the_logging_path(): void
    {
        // preg_replace returns null on a bad pattern. Throwing or emitting a
        // warning from inside an audit write would turn a redaction routine into
        // the reason a business operation failed.
        $scrubber = $this->scrubber(patterns: ['/unclosed(', '/\bsk_(test|live)_[A-Za-z0-9]+/']);

        $this->assertSame(
            'key '.SensitiveDataScrubber::REDACTED,
            $scrubber->scrubString('key sk_live_abc123'),
        );
    }

    // ── Non-array values ────────────────────────────────────────────────────

    public function test_scalars_and_null_pass_through_unchanged(): void
    {
        $scrubber = $this->scrubber();

        $this->assertSame(5000, $scrubber->scrub(5000));
        $this->assertSame(7.5, $scrubber->scrub(7.5));
        $this->assertFalse($scrubber->scrub(false));
        $this->assertNull($scrubber->scrub(null));
    }

    public function test_a_stringifiable_object_is_kept_and_an_opaque_one_is_refused(): void
    {
        $scrubber = $this->scrubber();

        // Audit payloads legitimately hold dates, and an Eloquent model is the one
        // thing that must never be serialised into a log by accident: it would
        // write every attribute it has, including the ones `$hidden` keeps off a
        // JSON response.
        $this->assertSame(
            '2026-09-10 09:00:00',
            $scrubber->scrub(Carbon::parse('2026-09-10 09:00:00', 'UTC')),
        );

        $this->assertSame(
            'label: '.SensitiveDataScrubber::REDACTED,
            $scrubber->scrub(new class implements Stringable
            {
                public function __toString(): string
                {
                    return 'label: pk_test_1234abcd';
                }
            }),
        );

        $this->assertSame(SensitiveDataScrubber::REDACTED, $scrubber->scrub(new stdClass));
    }

    public function test_recursion_stops_at_the_depth_limit_without_losing_the_payload(): void
    {
        $scrubber = $this->scrubber();

        $deep = ['level_0' => 'visible'];
        $node = &$deep;

        for ($i = 1; $i <= 20; $i++) {
            $node['level_'.$i] = ['level_'.($i + 1) => 'hidden'];
            $node = &$node['level_'.$i];
        }

        unset($node);

        $scrubbed = $scrubber->scrubArray($deep);

        // The shallow end survives and the deep end is marked, which is the
        // difference an investigator can act on. A distinct marker matters:
        // "[REDACTED]" would claim a secret was present where the truth is that
        // the payload was too deep to inspect.
        $this->assertSame('visible', $scrubbed['level_0']);
        $this->assertStringContainsString(SensitiveDataScrubber::TRUNCATED, json_encode($scrubbed, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('hidden', json_encode($scrubbed, JSON_THROW_ON_ERROR));
    }
}
