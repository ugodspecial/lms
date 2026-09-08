<?php

declare(strict_types=1);

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Support\Enums\EnumSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `files` — the central file registry (§40, §57, §59, ADR-10).
 *
 * Created first among the Phase 1 tables because `users.avatar_file_id`
 * references it, and because almost every later phase attaches files to
 * something.
 *
 * Two design points that are easy to get wrong:
 *
 * • The row is a REGISTRY ENTRY, not the file. The bytes live on a disk named by
 *   `disk` + `path`. Nothing here is served directly: `visibility` selects a
 *   disk whose `serve` flag is false for everything but `public`, so a guessed
 *   URL 404s and downloads go through DownloadAuthorizer (§59).
 *
 * • `fileable_*` is polymorphic and therefore has NO foreign key. That is a
 *   deliberate trade (docs/04 §0 "No FK (polymorphic)"): one registry for
 *   assignments, certificates, CVs and product files beats a per-owner table
 *   each with its own upload logic. Ownership is enforced in the application
 *   layer, and `category` is CHECK-constrained so a file cannot claim a category
 *   the code does not know how to authorize.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // Referenced in download URLs instead of the sequential id, so the
            // route gives nothing away about volume or ordering (§59).
            $table->uuid('uuid')->unique();

            $table->string('fileable_type')->nullable();
            $table->unsignedBigInteger('fileable_id')->nullable();

            $table->string('category', 40);
            $table->string('visibility', 20);

            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');

            // Indexed for de-duplication: two uploads with the same checksum are
            // the same bytes, which matters for storage quotas on shared hosting.
            $table->char('checksum_sha256', 64)->index();

            // Nullable and SET NULL rather than RESTRICT: unlike academic and
            // financial records, a file's meaning does not depend on remembering
            // who uploaded it, and staff turnover must not block erasure (§58).
            // The actor is still captured in `audit_logs` at upload time.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('meta')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['fileable_type', 'fileable_id']);
            $table->index(['category', 'visibility']);
        });

        // The CHECK lists are generated from the enums, not typed here, so the
        // database and the application cannot disagree about what a category is.
        DB::statement('ALTER TABLE files ADD '.EnumSchema::check('files', 'category', FileCategory::class));
        DB::statement('ALTER TABLE files ADD '.EnumSchema::check('files', 'visibility', FileVisibility::class));
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
