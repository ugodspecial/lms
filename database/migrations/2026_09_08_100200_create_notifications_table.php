<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notifications` — Laravel's database channel (§51 in-app notification centre).
 *
 * Standard Laravel shape. The one addition is the composite index on
 * (notifiable_type, notifiable_id, read_at): the notification centre's default
 * view is "this user's unread notifications", and without read_at in the index
 * that query scans every notification the user has ever received.
 *
 * Marketing and transactional email are separate concerns (§83) and neither goes
 * through this table unless it is also shown in-app; `notification_preferences`
 * decides per user and per key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
