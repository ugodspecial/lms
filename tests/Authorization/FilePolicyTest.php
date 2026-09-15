<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Policies\FilePolicy;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Who may read a stored file, tier by tier (§40, §57, §58, §59, ADR-10).
 *
 * The four tiers are four different questions with four different answers, and the
 * interesting failures are all in the middle two:
 *
 *   public        anybody, including a guest — a guest has to be able to reach it,
 *                 or the tier is a lie and every avatar needs an account
 *   authenticated any signed-in user — course material, usable without a per-file
 *                 grant and still not indexable by the world
 *   private       the uploader, the person it describes, or an administrator
 *   restricted    an explicit decision per request
 *
 * The `restricted` tier is where this test spends most of its attention, because
 * docs/05 §3.9 grants `files.view_restricted` to Super Admin and Administrator
 * outright and to Academic Admin and Finance Officer *scoped*, and a scoped grant
 * with no defined scope has to fail closed. A Finance Officer who can read every
 * restricted file on the platform is not a bug anybody would notice in review; it
 * is a bug somebody would notice in an audit.
 *
 * The rule a scoped holder reaches nothing beyond their own is asserted here so
 * that the day a domain defines the scope — Phase 2 for the guardian link, Phase 8
 * for the entitlement — the change is a deliberate edit to a test that says why it
 * was narrow, rather than a silent widening nobody wrote down.
 *
 * Every check runs through the Gate rather than against the policy object, so
 * `Gate::before`, the policy registration and spatie are all on the path being
 * tested. The two abilities that are false for everyone are the exception, and the
 * reason is documented where they are asserted.
 */
final class FilePolicyTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    /** A class from a module that is not installed yet, which is the normal case. */
    private const FUTURE_OWNER = 'App\\Domain\\Education\\Models\\Student';

    public function test_a_guest_may_download_a_public_file(): void
    {
        $file = $this->file(FileVisibility::IsPublic, FileCategory::ProfilePhoto);

        // Not actingAs() anybody: this is the anonymous request for an avatar or a
        // published brochure, and it has to work without an account.
        $this->assertTrue(Gate::allows('download', $file));
        $this->assertTrue(Gate::allows('view', $file));
    }

    public function test_a_guest_may_not_download_any_tier_above_public(): void
    {
        foreach ($this->tiersAbovePublic() as $tier) {
            $file = $this->file($tier);

            $this->assertTrue(Gate::denies('download', $file), $tier->value);
            $this->assertTrue(Gate::denies('view', $file), $tier->value);
        }
    }

    public function test_every_signed_in_user_may_download_the_authenticated_tier(): void
    {
        $file = $this->file(FileVisibility::IsAuthenticated, FileCategory::CourseResource);

        foreach ($this->participantRoles() as $role) {
            $this->assertTrue($this->user($role)->can('download', $file), $role);
        }
    }

    public function test_the_private_tier_reaches_the_uploader_and_the_person_it_describes(): void
    {
        $tutor = $this->user(Roles::TUTOR);
        $student = $this->user(Roles::STUDENT);

        // A tutor's own CV: they uploaded it.
        $cv = $this->file(FileVisibility::IsPrivate, FileCategory::Cv, $tutor);
        $this->assertTrue($tutor->can('download', $cv));

        // A student's document, uploaded by an administrator on their behalf. The
        // uploader and the subject are different people and both may read it.
        $administrator = $this->user(Roles::ADMINISTRATOR);
        $document = $this->file(FileVisibility::IsPrivate, FileCategory::StudentDocument, $administrator, $student);

        $this->assertTrue($student->can('download', $document), 'the person it describes');
        $this->assertTrue($administrator->can('download', $document), 'the person who put it there');
    }

    public function test_a_signed_in_user_may_not_download_somebody_elses_private_file(): void
    {
        $owner = $this->user(Roles::STUDENT);
        $stranger = $this->user(Roles::STUDENT);

        $file = $this->file(FileVisibility::IsPrivate, FileCategory::StudentDocument, $owner, $owner);

        $this->assertTrue($stranger->cannot('download', $file));
        $this->assertTrue($stranger->cannot('view', $file));
    }

    public function test_unscoped_staff_reach_a_private_file_they_did_not_upload(): void
    {
        $owner = $this->user(Roles::STUDENT);
        $file = $this->file(FileVisibility::IsPrivate, FileCategory::Invoice, $owner, $owner);

        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR] as $role) {
            $this->assertTrue($this->user($role)->can('download', $file), $role);
        }

        // Every other role is refused, including the ones that hold
        // files.view_restricted with a scope.
        foreach ([Roles::ACADEMIC_ADMIN, Roles::FINANCE_OFFICER, Roles::OPERATIONS_OFFICER] as $role) {
            $this->assertTrue($this->user($role)->cannot('download', $file), $role);
        }
    }

    public function test_a_restricted_file_is_theirs_or_an_unscoped_staff_grant(): void
    {
        $student = $this->user(Roles::STUDENT);
        $other = $this->user(Roles::STUDENT);

        $submission = $this->file(
            FileVisibility::IsRestricted,
            FileCategory::AssignmentSubmission,
            $student,
            $student,
        );

        $this->assertTrue($student->can('download', $submission), 'their own submission');

        // A student who cannot download their own work would be the first thing
        // broken by this tier, and the first "fix" would be to grant everybody the
        // staff permission — which is why ownership is checked before permission.
        $this->assertTrue($other->cannot('download', $submission), 'another student');

        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR] as $role) {
            $this->assertTrue($this->user($role)->can('download', $submission), $role);
        }
    }

    public function test_a_scoped_holder_of_view_restricted_reaches_nothing_beyond_their_own(): void
    {
        $student = $this->user(Roles::STUDENT);
        $file = $this->file(FileVisibility::IsRestricted, FileCategory::StudentDocument, $student, $student);

        foreach ([Roles::ACADEMIC_ADMIN, Roles::FINANCE_OFFICER] as $role) {
            $scoped = $this->user($role);

            // They hold the permission — that is what makes this case interesting.
            $this->assertTrue($scoped->can('files.view_restricted'), $role);
            $this->assertTrue($scoped->cannot('download', $file), $role.' reads a minor\'s record');

            // Their own upload under the same tier is still theirs.
            $own = $this->file(FileVisibility::IsRestricted, FileCategory::Report, $scoped, $scoped);
            $this->assertTrue($scoped->can('download', $own), $role.' reads their own');
        }

        // A role that does not hold the permission at all is refused too, for the
        // obvious reason, so the two failures cannot be confused.
        $this->assertTrue($this->user(Roles::OPERATIONS_OFFICER)->cannot('download', $file));
        $this->assertTrue($this->user(Roles::OPERATIONS_OFFICER)->cannot('files.view_restricted'));
    }

    public function test_a_parent_does_not_reach_a_childs_restricted_file_until_a_domain_says_so(): void
    {
        $parent = $this->user(Roles::PARENT);
        $child = $this->user(Roles::STUDENT);
        $administrator = $this->user(Roles::ADMINISTRATOR);

        // A progress report about the child, filed by an administrator.
        $report = $this->file(
            FileVisibility::IsRestricted,
            FileCategory::Report,
            $administrator,
            $child,
        );

        // Phase 2 turns this into a yes, through the guardian link and its consent
        // record — and that is a rule somebody will write and review. Until then
        // the answer is no, which is the direction a mistake should fall in when
        // the record belongs to a minor (§58).
        $this->assertTrue($parent->cannot('download', $report));
        $this->assertTrue($child->can('download', $report), 'the subject of the report');
    }

    public function test_the_registry_row_is_no_more_readable_than_the_bytes(): void
    {
        $stranger = $this->user(Roles::STUDENT);
        $administrator = $this->user(Roles::ADMINISTRATOR);

        foreach (FileVisibility::cases() as $tier) {
            $file = $this->file($tier);

            $this->assertSame(
                Gate::allows('download', $file),
                Gate::allows('view', $file),
                'guest / '.$tier->value,
            );
            $this->assertSame(
                $stranger->can('download', $file),
                $stranger->can('view', $file),
                'student / '.$tier->value,
            );
            $this->assertSame(
                $administrator->can('download', $file),
                $administrator->can('view', $file),
                'administrator / '.$tier->value,
            );
        }
    }

    public function test_a_file_owned_by_a_record_from_a_module_that_is_not_installed_yet_is_not_readable(): void
    {
        $student = $this->user(Roles::STUDENT);

        // The owner's class does not exist. `fileable` must never be loaded to
        // answer this, or an authorization check would break because a module was
        // not deployed — and it would break in the direction of an exception on a
        // request that was going to be refused anyway.
        $file = $this->makeFile(null, null, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsRestricted,
            'fileable_type' => self::FUTURE_OWNER,
            'fileable_id' => 99,
        ]);

        $this->assertTrue($student->cannot('download', $file));
        $this->assertTrue($this->user(Roles::ADMINISTRATOR)->can('download', $file));
    }

    public function test_the_registry_may_be_browsed_only_by_holders_of_view_restricted(): void
    {
        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR, Roles::ACADEMIC_ADMIN, Roles::FINANCE_OFFICER] as $role) {
            $this->assertTrue($this->user($role)->can('viewAny', File::class), $role);
        }

        foreach ([
            Roles::OPERATIONS_OFFICER,
            Roles::TUTOR,
            Roles::PARENT,
            Roles::STUDENT,
            Roles::EVALUATOR,
        ] as $role) {
            $this->assertTrue($this->user($role)->cannot('viewAny', File::class), $role);
        }

        $this->assertTrue(Gate::denies('viewAny', File::class), 'a guest');
    }

    public function test_uploading_needs_the_upload_permission(): void
    {
        foreach ($this->participantRoles() as $role) {
            $this->assertTrue($this->user($role)->can('create', File::class), $role);
        }

        // An Evaluator records judgements; they are not a participant in the
        // platform's own records and hold no file permissions at all (docs/05 §3.9).
        $this->assertTrue($this->user(Roles::EVALUATOR)->cannot('create', File::class));
        $this->assertTrue($this->user(Roles::EVALUATOR)->cannot('files.upload'));
    }

    public function test_deleting_is_own_uploads_unless_the_grant_is_unscoped(): void
    {
        $student = $this->user(Roles::STUDENT);
        $file = $this->file(FileVisibility::IsRestricted, FileCategory::StudentDocument, $student, $student);

        // Unscoped: Super Admin and Administrator.
        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR] as $role) {
            $this->assertTrue($this->user($role)->can('delete', $file), $role);
        }

        // Scoped to "own uploads" for everybody else that holds it at all.
        foreach ([
            Roles::ACADEMIC_ADMIN,
            Roles::FINANCE_OFFICER,
            Roles::OPERATIONS_OFFICER,
            Roles::TUTOR,
            Roles::PARENT,
        ] as $role) {
            $user = $this->user($role);

            $this->assertTrue($user->can('files.delete'), $role.' holds the permission');
            $this->assertTrue($user->cannot('delete', $file), $role.' deletes a student\'s submission');

            $own = $this->file(FileVisibility::IsPrivate, FileCategory::Cv, $user, $user);
            $this->assertTrue($user->can('delete', $own), $role.' deletes their own upload');
        }

        $this->assertTrue($student->can('delete', $file), 'the student deletes their own');
    }

    public function test_a_role_without_the_delete_permission_cannot_delete_even_a_file_it_put_there(): void
    {
        $evaluator = $this->user(Roles::EVALUATOR);

        // The row says they uploaded it; the registry says they may not delete
        // anything. The permission is the answer, not the ownership.
        $file = $this->file(FileVisibility::IsPrivate, FileCategory::Report, $evaluator, $evaluator);

        $this->assertTrue($evaluator->cannot('files.delete'));
        $this->assertTrue($evaluator->cannot('delete', $file));
    }

    public function test_restoring_follows_the_same_rule_as_deleting(): void
    {
        $student = $this->user(Roles::STUDENT);
        $administrator = $this->user(Roles::ADMINISTRATOR);

        $theirs = $this->file(FileVisibility::IsPrivate, FileCategory::StudentDocument, $student, $student);
        $somebodyElses = $this->file(FileVisibility::IsPrivate, FileCategory::Invoice, $administrator, $administrator);

        $this->assertTrue($student->can('restore', $theirs));
        $this->assertTrue($student->cannot('restore', $somebodyElses));
        $this->assertTrue($administrator->can('restore', $somebodyElses));
    }

    public function test_no_role_may_edit_a_files_assertions_through_the_policy(): void
    {
        $policy = new FilePolicy;
        $file = $this->file(FileVisibility::IsRestricted, FileCategory::DigitalProduct);

        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR, Roles::ACADEMIC_ADMIN, Roles::STUDENT] as $role) {
            $this->assertFalse($policy->update($this->user($role), $file), $role);
        }

        // The Super Admin bypass in AuthServiceProvider answers `true` before any
        // policy is consulted, so the Gate disagrees with the policy for that one
        // role. Asserted rather than left as a surprise: what actually prevents a
        // reclassification is that nothing performs one — there is no update route
        // (GuessableUrlTest pins the file routes), no update service, and no mass
        // assignment on the model.
        $this->assertTrue($this->user(Roles::SUPER_ADMIN)->can('update', $file));
    }

    public function test_erasure_is_not_available_through_the_policy(): void
    {
        $policy = new FilePolicy;
        $file = $this->file(FileVisibility::IsPrivate, FileCategory::Invoice);

        // Removing the bytes has to decide what happens to the record that refers to
        // them — an invoice on a paid order, a submission on a graded assessment —
        // and in that order (§58). A `forceDelete` here would be a way to destroy
        // evidence that leaves the trail saying nothing about why.
        foreach ([Roles::SUPER_ADMIN, Roles::ADMINISTRATOR, Roles::STUDENT] as $role) {
            $this->assertFalse($policy->forceDelete($this->user($role), $file), $role);
        }
    }

    public function test_a_role_that_inherits_another_roles_grants_also_inherits_its_qualifier(): void
    {
        $student = $this->user(Roles::STUDENT);
        $theirs = $this->file(FileVisibility::IsPrivate, FileCategory::StudentDocument, $student, $student);

        $instructor = $this->user(Roles::INSTRUCTOR);

        // Instructor is not a column in the matrix: Roles::permissionsFor() gives it
        // the Tutor permission shape, and Permissions::SCOPED writes 'own uploads'
        // against 'Tutor'. Read literally, an Instructor would look like a role the
        // registry qualifies nowhere — which is how a policy reads "no scope".
        $this->assertTrue($instructor->can('files.delete'), 'Instructor carries the Tutor permission shape');
        $this->assertTrue($instructor->cannot('delete', $theirs), 'own uploads, exactly like a Tutor');

        $own = $this->file(FileVisibility::IsPrivate, FileCategory::Cv, $instructor, $instructor);
        $this->assertTrue($instructor->can('delete', $own), 'their own upload');

        // And the same shape for reading: no Instructor reaches a restricted file
        // they are not connected to, because they do not hold the staff permission.
        $restricted = $this->file(FileVisibility::IsRestricted, FileCategory::StudentDocument, $student, $student);
        $this->assertTrue($instructor->cannot('download', $restricted));
    }

    public function test_the_policy_is_registered_so_the_gate_consults_it(): void
    {
        // An unregistered policy fails the quiet way: every check returns false for
        // everybody, including everybody who should be allowed, and nothing throws.
        $this->assertInstanceOf(FilePolicy::class, Gate::getPolicyFor(new File));
        $this->assertInstanceOf(FilePolicy::class, Gate::getPolicyFor(File::class));
    }

    /**
     * @return list<FileVisibility>
     */
    private function tiersAbovePublic(): array
    {
        return array_values(array_filter(
            FileVisibility::cases(),
            static fn (FileVisibility $tier): bool => $tier !== FileVisibility::IsPublic,
        ));
    }

    /**
     * The roles docs/05 §3.9 grants `files.upload` to — every participant.
     *
     * @return list<string>
     */
    private function participantRoles(): array
    {
        return [
            Roles::SUPER_ADMIN,
            Roles::ADMINISTRATOR,
            Roles::ACADEMIC_ADMIN,
            Roles::FINANCE_OFFICER,
            Roles::OPERATIONS_OFFICER,
            Roles::TUTOR,
            Roles::PARENT,
            Roles::STUDENT,
        ];
    }

    private function file(
        FileVisibility $visibility,
        FileCategory $category = FileCategory::StudentDocument,
        ?User $uploader = null,
        ?User $owner = null,
    ): File {
        return $this->makeFile($uploader, $owner, [
            'category' => $category,
            'visibility' => $visibility,
        ]);
    }

    /**
     * A user holding every role given. Assigned rather than simulated, so the check
     * runs the whole way through spatie, `Gate::before` and the policy.
     */
    private function user(string ...$roles): User
    {
        $user = $this->makeUser();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}
