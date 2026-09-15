<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\ConsentType;
use App\Support\Enums\EnumSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `consents` — privacy, terms and marketing consent records (§58, §83).
 *
 * APPEND-ONLY: there is `recorded_at` and no `updated_at`, and the model sets
 * `UPDATED_AT = null`. Withdrawing consent INSERTS a row with `granted = false`;
 * it never updates the row that granted it. The evidential value of a consent
 * record is that *this* person accepted *that* version of *that* document at
 * *that* time from *that* IP — an UPDATE destroys precisely the fact being
 * preserved, and silently.
 *
 * `version` is the version of the document, not of the row. When the privacy
 * policy changes, existing consents do not migrate; users are re-prompted and a
 * new row appears.
 *
 * `student_id` is nullable because a parent or guardian consents on behalf of a
 * minor (§10, §58). It carries NO foreign key yet: `students` is created in
 * Phase 2. Adding the constraint there keeps this migration honest about the
 * ordering rather than pointing at a table that does not exist.
 *
 * Marketing consent defaults to false and is never bundled with acceptance of
 * the terms — a registration form that pre-ticks it, or that treats signing up
 * as opting in, is the specific behaviour §83 forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('type', 30);
            $table->string('version', 32);
            $table->boolean('granted')->default(false);

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['user_id', 'type']);
            $table->index(['student_id', 'type']);
        });

        DB::statement('ALTER TABLE consents ADD '.EnumSchema::check('consents', 'type', ConsentType::class));
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
