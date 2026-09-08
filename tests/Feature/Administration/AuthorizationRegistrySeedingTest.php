<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The registry only means something once it is in the database, and the failure
 * mode worth testing is the one that produces no error at all.
 *
 * A deploy that runs `migrate --force` and forgets `db:seed --force` leaves the
 * permission table behind the code. Nothing throws. A permission the registry
 * declares but the table lacks simply denies everybody, so a feature shipped that
 * morning looks broken to the administrators meant to use it — and a permission
 * the registry retired keeps its role grants and keeps passing `can()` for a
 * capability no policy implements any more, which reads as working.
 *
 * These tests cover the sync, its deliberate refusal to delete, and the gate:
 * `platform:doctor` has to report both kinds of mismatch, because that is the only
 * place an operator will see either.
 */
final class AuthorizationRegistrySeedingTest extends TestCase
{
    use RefreshDatabase;

    /** @var bool */
    protected $seed = true;

    /** @var array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>}|null */
    private ?array $cachedReport = null;

    public function test_seeding_registers_the_whole_registry(): void
    {
        $names = Permission::query()->orderBy('id')->pluck('name')->map(static fn ($n): string => (string) $n)->all();

        $this->assertCount(214, $names);

        // Insertion order equals registry order on a clean database, which is what
        // makes the admin UI's grouping stable without a sort column.
        $this->assertSame(Permissions::all(), $names);

        $this->assertSame(11, Role::count());

        foreach (Roles::ALL as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $before = Permission::query()->orderBy('id')->pluck('name')->all();
        $rolesBefore = Role::query()->orderBy('id')->pluck('name')->all();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->assertSame($before, Permission::query()->orderBy('id')->pluck('name')->all(), 'Re-seeding duplicated or reordered permissions.');
        $this->assertSame($rolesBefore, Role::query()->orderBy('id')->pluck('name')->all(), 'Re-seeding duplicated roles.');

        // Idempotence is not only "same row count": a second run must leave the
        // bundles intact, because syncPermissions() detaches before attaching and
        // a failure part-way through would silently empty a role.
        $this->assertSame(
            $this->sorted(Roles::permissionsFor(Roles::SUPER_ADMIN)),
            $this->sorted($this->bundleFor(Roles::SUPER_ADMIN)),
        );
    }

    public function test_each_role_bundle_in_the_database_matches_the_registry(): void
    {
        foreach (Roles::ALL as $role) {
            $this->assertSame(
                $this->sorted(Roles::permissionsFor($role)),
                $this->sorted($this->bundleFor($role)),
                "{$role}'s seeded bundle differs from the docs/05 matrix."
            );
        }

        // The one permission that belongs to no bundle must not have leaked into
        // one: it guards an unauthenticated endpoint, and granting it would imply
        // a logged-in user needs it.
        foreach (Permissions::PUBLIC_CAPABILITIES as $permission) {
            $this->assertSame(0, Role::query()->whereHas('permissions', static fn ($q) => $q->where('name', $permission))->count());
        }
    }

    public function test_a_permission_absent_from_the_registry_is_left_in_place(): void
    {
        // docs/05 §5: orphans are reported, not removed. A seeder that deleted on
        // an incomplete registry would strip 26 permissions from every Finance
        // Officer because one group constant was dropped in a merge — turning a
        // typo into a company that cannot see its own invoices.
        Permission::create(['name' => 'students.teleport', 'guard_name' => 'web']);

        $this->seed(PermissionSeeder::class);

        $this->assertSame(215, Permission::count());
        $this->assertDatabaseHas('permissions', ['name' => 'students.teleport']);
    }

    public function test_an_orphan_keeps_the_grants_it_already_had(): void
    {
        $rogue = Permission::create(['name' => 'students.teleport', 'guard_name' => 'web']);

        Role::findByName(Roles::ADMINISTRATOR, 'web')->givePermissionTo($rogue);

        $this->seed(PermissionSeeder::class);

        // Non-destructive by design. The seeder cannot tell a deliberate
        // retirement from a broken registry, so it does not guess; the doctor
        // reports it and a human decides.
        $this->assertDatabaseHas('role_has_permissions', ['permission_id' => $rogue->getKey()]);
    }

    public function test_a_role_absent_from_the_registry_is_left_in_place(): void
    {
        Role::create(['name' => 'Ghost', 'guard_name' => 'web']);

        $this->seed(RoleSeeder::class);

        $this->assertSame(12, Role::count());
        $this->assertDatabaseHas('roles', ['name' => 'Ghost']);

        // Bundles still sync. Correcting what a role can do is reversible and
        // touches no identity; deleting the role would take it off real people.
        $this->assertSame(
            $this->sorted(Roles::permissionsFor(Roles::ADMINISTRATOR)),
            $this->sorted($this->bundleFor(Roles::ADMINISTRATOR)),
        );
    }

    public function test_the_doctor_warns_about_rows_the_registry_does_not_declare(): void
    {
        Permission::create(['name' => 'students.teleport', 'guard_name' => 'web']);
        Role::create(['name' => 'Ghost', 'guard_name' => 'web']);

        // A warning, not a failure, and named: an orphan is worth knowing about
        // but is not safe to act on automatically, because the registry that
        // arrived may itself be the thing that is wrong.
        $this->assertSame('warn', $this->doctorStatus('permission orphans'));
        $this->assertSame('warn', $this->doctorStatus('role orphans'));
        $this->assertStringContainsString('students.teleport', $this->doctorDetail('permission orphans'));
        $this->assertStringContainsString('Ghost', $this->doctorDetail('role orphans'));

        // Everything the registry does declare is still present, so the primary
        // checks stay green: an orphan is not a broken deploy.
        $this->assertSame('pass', $this->doctorStatus('permissions'));
        $this->assertSame('pass', $this->doctorStatus('roles'));
    }

    public function test_a_super_admin_is_seeded_with_the_matrix_not_with_everything(): void
    {
        $bundle = $this->bundleFor(Roles::SUPER_ADMIN);

        $this->assertCount(211, $bundle);

        foreach (Roles::PARTICIPANT_ONLY as $permission) {
            $this->assertNotContains(
                $permission,
                $bundle,
                "{$permission} records that somebody took part in something. Seeding it onto Super Admin would make Gate::before's exception list the only thing standing between an administrator and a fabricated review."
            );
        }
    }

    public function test_the_doctor_passes_a_database_that_matches_the_registry(): void
    {
        $this->assertSame('pass', $this->doctorStatus('permissions'));
        $this->assertSame('pass', $this->doctorStatus('roles'));
    }

    public function test_the_doctor_fails_a_deploy_that_migrated_without_seeding(): void
    {
        // Exactly what an unseeded deploy looks like: one permission the code
        // declares is absent, and one role is absent.
        Permission::query()->where('name', 'users.view')->delete();
        Role::query()->where('name', Roles::FINANCE_OFFICER)->delete();

        // Both are reported from a single run. An operator should not have to fix
        // one, re-run, and then discover the second.
        $this->assertSame('fail', $this->doctorStatus('permissions'));
        $this->assertSame('fail', $this->doctorStatus('roles'));

        // Named, not just counted: "3 of 214 missing" does not tell an operator
        // whether the gap is the permission gating what they just deployed.
        $this->assertStringContainsString('users.view', $this->doctorDetail('permissions'));
        $this->assertStringContainsString(Roles::FINANCE_OFFICER, $this->doctorDetail('roles'));
        $this->assertStringContainsString('db:seed', $this->doctorDetail('permissions'), 'A failure without the command that fixes it is only half a diagnosis.');
    }

    /**
     * @return list<string>
     */
    private function bundleFor(string $role): array
    {
        return Role::findByName($role, 'web')
            ->permissions
            ->pluck('name')
            ->map(static fn ($n): string => (string) $n)
            ->all();
    }

    /**
     * Sorted because a bundle is a set. spatie returns the relation in whatever
     * order the database chooses, and asserting order would make these tests fail
     * for a reason that is not a defect.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function sorted(array $permissions): array
    {
        $sorted = array_values($permissions);
        sort($sorted);

        return $sorted;
    }

    private function doctorStatus(string $label): string
    {
        return (string) ($this->doctorCheck($label)['status'] ?? '');
    }

    private function doctorDetail(string $label): string
    {
        return (string) ($this->doctorCheck($label)['detail'] ?? '');
    }

    /**
     * @return array{group: string, label: string, status: string, detail: string}
     */
    private function doctorCheck(string $label): array
    {
        foreach ($this->report()['checks'] as $check) {
            if ($check['label'] === $label) {
                return $check;
            }
        }

        $this->fail("platform:doctor reported no check labelled [{$label}].");
    }

    /**
     * Runs the doctor with database checks ON, which is the only mode in which the
     * authorization registry is examined at all.
     *
     * Artisan::output() is destructive — BufferedOutput::fetch() empties the
     * buffer — so the report is parsed once and reused. Asking for two details
     * without caching returns '' for the second.
     *
     * @return array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>}
     */
    private function report(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        Artisan::call('platform:doctor', ['--json' => true]);

        $output = trim(Artisan::output());

        /** @var array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>}|null $report */
        $report = json_decode($output, true);

        $this->assertIsArray($report, "platform:doctor --json did not emit parseable JSON:\n{$output}");

        return $this->cachedReport = $report;
    }
}
