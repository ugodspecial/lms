<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Support\Enums\EnumSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `connected_accounts` — OAuth links and their tokens (§36, §37, ADR-05).
 *
 * The unique key is (user_id, provider, **purpose**), not (user_id, provider).
 * That third column is what makes the design work: one Google identity can be
 * linked once to sign the user in and once to provision Meet rooms, and those
 * are different grants with different scopes, different tokens and different
 * revocation semantics. Collapsing them into one row means either over-scoping
 * the login token or losing the ability to sign in after Meet is disconnected.
 *
 * Tokens are encrypted by an Eloquent cast. A leaked database must not yield
 * working Google or Zoom credentials — for a tutor, that is the ability to join
 * and record their own sessions (§58).
 *
 * Zoom tokens expire after roughly an hour with a rolling 90-day refresh, which
 * is why `expires_at` is indexed: the refresher sweeps rows that are about to
 * expire rather than probing every account on every call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();

            // RESTRICT, per docs/04 §0: rows referencing `users` are not silently
            // destroyed. A connected account is also evidence of what a user
            // authorized, which matters when a payment or a meeting recording is
            // later disputed. Erasure (§58) deletes these explicitly, in order.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('provider', 20);
            $table->string('purpose', 20);

            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            // Space-separated scope list as the provider returned it. Kept as a
            // string rather than JSON because it is compared as a whole.
            $table->text('scopes')->nullable();

            $table->json('meta')->nullable();

            $table->string('status', 20)->default(ConnectedAccountStatus::Connected->value);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'provider', 'purpose']);
            $table->index(['provider', 'status']);
        });

        DB::statement('ALTER TABLE connected_accounts ADD '.EnumSchema::check('connected_accounts', 'provider', ConnectedProvider::class));
        DB::statement('ALTER TABLE connected_accounts ADD '.EnumSchema::check('connected_accounts', 'purpose', ConnectedPurpose::class));
        DB::statement('ALTER TABLE connected_accounts ADD '.EnumSchema::check('connected_accounts', 'status', ConnectedAccountStatus::class));
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
