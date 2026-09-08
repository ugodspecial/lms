<?php

declare(strict_types=1);

use App\Domain\Administration\Enums\SettingType;
use App\Support\Enums\EnumSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `settings` — database-driven, non-secret configuration (§60, §95).
 *
 * The rule this table exists to enforce: no business value is hard-coded. An
 * academic year, a currency, a grading scale, a platform fee percentage, the age
 * of majority, a retention window — all of these belong here, because all of
 * them change without a release and an operator must be able to change them
 * without one.
 *
 * Two flags do real security work:
 *
 * • `is_secret` — never returned to a browser, never written to an audit log in
 *   clear, never included in a settings export. SettingsService enforces this on
 *   read rather than leaving it to each caller (§77). Secrets that are
 *   credentials (Paystack keys, OAuth client secrets) still belong in `.env`,
 *   not here; this flag covers operator-set values that must not leak into a
 *   rendered page.
 *
 * • `is_public` — safe to expose to an unauthenticated visitor, e.g. the
 *   organisation name on the landing page.
 *
 * `value` is JSON so one table holds every scalar shape, and `type` records how
 * to cast it back. Storing the type rather than inferring it is what keeps `"0"`,
 * `0` and `false` distinguishable — inferring would turn a configured `"0"`
 * answer string into a boolean and nobody would notice until a report was wrong.
 *
 * `key` is a reserved word in MySQL. The schema builder quotes it, and so does
 * Eloquent, but any raw SQL against this table must quote it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // organization | academic | commerce | payments | video |
            // notifications | security. Indexed because the admin settings screen
            // is tabbed by group and loads one group at a time.
            $table->string('group', 40)->index();

            $table->string('key', 120)->unique();

            $table->json('value')->nullable();
            $table->string('type', 20)->default(SettingType::Text->value);

            $table->boolean('is_secret')->default(false);
            $table->boolean('is_public')->default(false);

            // Shown next to the field in the admin UI, so an operator can tell
            // what a setting does without reading the code.
            $table->string('description')->nullable();

            // For `type = enum`: the allowed values, so the admin UI renders a
            // select instead of a free-text box that can be filled with anything.
            $table->json('allowed_values')->nullable();

            $table->timestamps();
        });

        DB::statement('ALTER TABLE settings ADD '.EnumSchema::check('settings', 'type', SettingType::class));

        // A secret setting that is also public is a contradiction, and the
        // resolution would depend on which flag a given code path checks first.
        // Make it unrepresentable instead.
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_secret_not_public CHECK (NOT (is_secret = 1 AND is_public = 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
