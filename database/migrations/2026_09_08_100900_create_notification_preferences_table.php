<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_preferences` — per user × notification key × channel (§82).
 *
 * PULLED FORWARD FROM PHASE 5, and the reason is recorded here rather than
 * absorbed silently (docs/10, working agreement 5): Phase 1 ships a
 * `/settings/notifications` screen. Rendering that screen without storage would
 * make every toggle on it a dead control, which §63 forbids outright. The
 * alternative — deferring the screen — would leave Phase 1's settings area
 * visibly incomplete. One table moved earlier is the smaller deviation.
 *
 * What did NOT move: `message_templates` (docs/04 §8 table 118) stays in
 * Phase 5. Phase 1 notification bodies are Blade views, which docs/02 §3.7
 * already treats as the fallback when a template key is missing — so this is the
 * documented default path, not a shortcut.
 *
 * The ERD in docs/03 draws this as one-to-one with `users`; docs/04 gives the
 * unique key as (user_id, notification_key). docs/04 is followed, because a
 * single row per user cannot express a different choice per notification, which
 * is the entire purpose of the table.
 *
 * `is_transactional` marks keys the user may NOT turn off — a payment receipt, a
 * safeguarding alert, a session cancellation. The flag is denormalised onto the
 * row so the UI can render the toggle as disabled and the service can refuse the
 * change without a second lookup, and §82's rule is enforced server-side in
 * NotificationPreferenceService rather than by the disabled attribute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            // CASCADE, unlike the RESTRICT used elsewhere for `users` references:
            // a preference has no evidential or academic value once the person is
            // gone, and retaining it would keep data about a deleted user alive
            // for no reason (§58).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Matches a key in the code registry of notifications, the same way
            // `permissions.name` matches the Permissions registry: code decides
            // what exists, the database records what this user chose.
            $table->string('notification_key', 80);

            $table->boolean('channel_mail')->default(true);
            $table->boolean('channel_database')->default(true);

            $table->boolean('is_transactional')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'notification_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
