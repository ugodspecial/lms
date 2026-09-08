<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_logs` — who did what, to which record, with before/after (§56).
 *
 * APPEND-ONLY: `created_at` and no `updated_at`. An audit trail that can be
 * edited is not an audit trail. There is no update path in the model and no
 * route that reaches one; retention is handled by pruning old rows on a
 * schedule, which is a deletion policy rather than a mutation of history.
 *
 * `user_id` is nullable because system actions — a scheduled job marking a
 * subscription expired, a webhook completing an order — are exactly the events
 * most worth having a record of, and they have no actor.
 *
 * `old_values` / `new_values` are REDACTED before they are written, by
 * SensitiveDataScrubber. This table is readable by administrators, so a password
 * hash, a 2FA secret or a full card reference landing in `new_values` would turn
 * the audit log into the most valuable table in the database. Redaction happens
 * on write, not on render: a log that stores secrets and hides them in the UI is
 * one SQL injection away from not hiding them at all (§56, §77).
 *
 * `auditable_*` is polymorphic with no foreign key, by the same reasoning as
 * `files.fileable_*`: one generic trail beats a per-entity log table.
 *
 * Indexes follow docs/04 §1 exactly. `IX(created_at)` exists purely so the
 * retention pruner can delete an old range without a full scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // RESTRICT: an audit row must not lose its actor because a user row
            // was removed. Users are soft-deleted, so in practice this never
            // fires — which is the point of making it explicit.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // A dotted permission-shaped string: `tutors.approved`,
            // `orders.refunded`, `users.impersonation.started`. Dotted so a
            // prefix query can list every event in a domain.
            $table->string('event', 80);

            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Free-form comma-separated tags for filtering (`security`,
            // `finance`, `gdpr`) without needing another table.
            $table->string('tags')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
