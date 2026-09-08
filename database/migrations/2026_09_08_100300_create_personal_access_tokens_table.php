<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personal_access_tokens` — Laravel Sanctum (docs/04 §1 table 10).
 *
 * Byte-identical to the migration Sanctum publishes, so that re-publishing from
 * the package produces no diff and upstream changes stay visible.
 *
 * Present in Phase 1 even though `/api/v1` carries only a status probe and a
 * `/me` sanity check: `routes/api.php` already guards `/me` with `auth:sanctum`,
 * and a guard whose table does not exist fails at request time rather than at
 * boot, which is the worst time to discover it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
