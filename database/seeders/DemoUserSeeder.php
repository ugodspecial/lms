<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development logins for `/demo-login` (§74). Never runs in production — see
 * DatabaseSeeder — and only when `platform.demo_accounts.enabled`, which is
 * itself forced false in production by config/platform.php.
 *
 * Everything comes from config: the roster, the emails, the shared password. No
 * credential is a literal in this file, so there is nothing here to copy into a
 * real deployment by accident, and adding a demo persona is a config edit rather
 * than a code change (§95).
 *
 * Roles are NOT attached here. `RoleSeeder` does that in Phase 1, once the
 * permission registry exists; assigning a role name that has not been seeded
 * would throw, and seeding roles from this class would split one responsibility
 * across two files.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('platform.demo_accounts.enabled')) {
            return;
        }

        /** @var array<string, array{email?: string, name?: string}> $accounts */
        $accounts = (array) config('platform.demo_accounts.accounts', []);

        $password = (string) config('platform.demo_accounts.password', 'password');

        foreach ($accounts as $account) {
            $email = $account['email'] ?? null;

            if (! is_string($email) || $email === '') {
                continue;
            }

            // updateOrCreate so seeding twice cannot fail on the unique email
            // index: `migrate:fresh --seed` is run repeatedly in CI, and a
            // developer reseeding a dirty database should not have to think about
            // whether these rows already exist.
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => (string) ($account['name'] ?? $email),
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
