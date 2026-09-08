<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\AuthProvider;
use App\Domain\Identity\Enums\UserCreatedBy;
use App\Domain\Identity\Enums\UserStatus;
use App\Support\Enums\EnumSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the skeleton `users` table into the platform's authentication subject
 * (§6, docs/03 §2, ADR-02 in docs/11).
 *
 * Written as an extension of the skeleton migration rather than a rewrite of it,
 * so `git diff` shows exactly what the platform adds on top of a stock Laravel
 * install — which is the useful thing to review.
 *
 * `users` is the ONE identity record. Student, parent, tutor and evaluator are
 * profile tables that may point at a user, not kinds of user; a person who is
 * both a parent and a tutor is one row here and two profiles (docs/02 §1). That
 * distinction is the reason this table has no `role` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Referenced in URLs and by external systems; also what an audit log
            // or export can carry without exposing the sequential id.
            $table->uuid('uuid')->unique()->after('id');

            // Nullable: an OAuth-only account has no password, and an account
            // created by an administrator or an import has one set later. Making
            // it NOT NULL would force a random unusable password into those rows.
            $table->string('password')->nullable()->change();

            $table->string('status', 20)->default(UserStatus::Pending->value)->after('email_verified_at');
            $table->index('status');

            // docs/04 indexes this so "who has not verified" is a cheap query —
            // it drives the reminder job and the admin user list filter.
            $table->index('email_verified_at');

            // NULL means "not chosen yet"; the presentation layer falls back to
            // the platform default from settings. Storing a hard-coded city here
            // would bake a business value into the schema (§95).
            $table->string('timezone', 64)->nullable()->after('status');
            $table->string('locale', 16)->nullable()->after('timezone');

            // FK added below, once `files` is guaranteed to exist.
            $table->unsignedBigInteger('avatar_file_id')->nullable()->after('locale');

            $table->boolean('two_factor_enabled')->default(false)->after('avatar_file_id');

            // Encrypted at rest by an Eloquent cast, not by the database: the
            // column holds a TOTP secret and a set of recovery codes, and a
            // database dump must not be enough to bypass 2FA (§58).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->string('auth_provider', 20)->default(AuthProvider::Password->value)->after('remember_token');
            $table->string('created_by_type', 20)->default(UserCreatedBy::SelfRegistered->value)->after('auth_provider');

            $table->timestamp('last_login_at')->nullable()->after('created_by_type');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            // Soft delete only. Academic and financial records reference `users`
            // with ON DELETE RESTRICT, so the correct end state for a person who
            // leaves is `status = deactivated` + `deleted_at`, never a hard
            // delete (§58). Erasure is an explicit, separate process.
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_file_id')->references('id')->on('files')->nullOnDelete();
        });

        DB::statement('ALTER TABLE users ADD '.EnumSchema::check('users', 'status', UserStatus::class));
        DB::statement('ALTER TABLE users ADD '.EnumSchema::check('users', 'auth_provider', AuthProvider::class));
        DB::statement('ALTER TABLE users ADD '.EnumSchema::check('users', 'created_by_type', UserCreatedBy::class));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_file_id']);

            $table->dropIndex(['status']);
            $table->dropIndex(['email_verified_at']);
            $table->dropUnique(['uuid']);

            $table->dropColumn([
                'uuid', 'status', 'timezone', 'locale', 'avatar_file_id',
                'two_factor_enabled', 'two_factor_secret', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'auth_provider', 'created_by_type',
                'last_login_at', 'last_login_ip', 'deleted_at',
            ]);
        });

        // The CHECK constraints are dropped by name, because MySQL has no
        // "drop all checks on this column" and leaving them behind would make the
        // migration non-reversible.
        foreach (['status', 'auth_provider', 'created_by_type'] as $column) {
            DB::statement("ALTER TABLE users DROP CONSTRAINT users_{$column}_check");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
