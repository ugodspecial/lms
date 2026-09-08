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

            // firstOrNew + save() rather than updateOrCreate, so seeding twice
            // cannot fail on the unique email index — `migrate:fresh --seed` runs
            // repeatedly in CI, and a developer reseeding a dirty database should
            // not have to think about whether these rows already exist.
            $user = User::query()->firstOrNew(['email' => $email]);

            // Attributes are assigned directly, not mass-assigned. The User model's
            // fillable list is name/email/password and `email_verified_at` is
            // deliberately NOT in it: a request able to mass-assign that column
            // would let anyone mark their own account verified. Mass assignment
            // discards non-fillable keys silently, so passing it to
            // updateOrCreate() here produced six unverified demo logins — seeded,
            // visible in the roster, and then refused by email verification. That
            // is a dead demo account (§63), and nothing about it looked broken.
            $user->name = (string) ($account['name'] ?? $email);
            $user->password = $password;
            $user->email_verified_at = now();

            $user->save();
        }
    }
}
