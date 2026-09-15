<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use Tests\TestCase;

/**
 * The two enums that decide where a file may live and how protected it has to be
 * (§57, §58, §59, ADR-10).
 *
 * They are checked together, and against config, because the rule they express is
 * a three-way agreement:
 *
 *   category → the least protective tier it may be stored under
 *   tier     → a disk in config/filesystems.php
 *   disk     → whether the web server can reach it
 *
 * A break in any one of the three is invisible at the layer that broke it. A
 * category whose floor is `public` looks like a one-word edit; a tier pointed at
 * the `public` disk looks like a convenience; and every PHP-level authorization
 * check keeps passing in both cases, because the file is then served by Apache to
 * anybody who can guess a path — which is the whole thing this design exists to
 * prevent (§59: "never expose protected product files through guessable URLs").
 *
 * Pure enum and config reads, so this is in the Unit suite: no database, no HTTP.
 */
final class FileVisibilityRulesTest extends TestCase
{
    public function test_the_tiers_are_ordered_least_to_most_protective(): void
    {
        $ordered = [
            FileVisibility::IsPublic,
            FileVisibility::IsAuthenticated,
            FileVisibility::IsPrivate,
            FileVisibility::IsRestricted,
        ];

        foreach ($ordered as $index => $tier) {
            $this->assertSame($index, $tier->rank(), $tier->value);
        }

        // Strictly increasing: two tiers sharing a rank would make "at least as
        // protective as" ambiguous for the categories that sit between them.
        $ranks = array_map(static fn (FileVisibility $tier): int => $tier->rank(), FileVisibility::cases());

        $this->assertSame($ranks, array_unique($ranks));
    }

    public function test_every_tier_has_a_label_a_person_can_read(): void
    {
        foreach (FileVisibility::cases() as $tier) {
            $this->assertNotSame('', trim($tier->label()), $tier->value);
            $this->assertStringNotContainsString($tier->value, $tier->label(), $tier->value);
        }
    }

    public function test_every_category_declares_a_floor(): void
    {
        // A `match` without a default throws UnhandledMatchError, so a new category
        // added without deciding its floor fails here rather than at the first
        // upload of that kind — in production, by a parent, at 11pm.
        foreach (FileCategory::cases() as $category) {
            $this->assertInstanceOf(
                FileVisibility::class,
                $category->minimumVisibility(),
                $category->value.' has no floor',
            );
        }
    }

    public function test_the_floor_for_each_category_is_the_documented_one(): void
    {
        $expected = [
            // A face in a published directory: the only thing on the platform that
            // is public by design.
            'profile_photo' => FileVisibility::IsPublic,

            // Lesson material: usable by any signed-in user without a per-file
            // grant, and still not indexable by the world.
            'course_resource' => FileVisibility::IsAuthenticated,

            // One person's documents about one person.
            'cv' => FileVisibility::IsPrivate,
            'certification' => FileVisibility::IsPrivate,
            'certificate' => FileVisibility::IsPrivate,
            'invoice' => FileVisibility::IsPrivate,

            // A paid product file, a minor's record, graded work: an explicit
            // decision per request.
            'digital_product' => FileVisibility::IsRestricted,
            'student_document' => FileVisibility::IsRestricted,
            'assignment_submission' => FileVisibility::IsRestricted,
            'report' => FileVisibility::IsRestricted,
        ];

        foreach (FileCategory::cases() as $category) {
            $this->assertArrayHasKey($category->value, $expected, $category->value.' is not covered by this test');
            $this->assertSame($expected[$category->value], $category->minimumVisibility(), $category->value);
        }

        $this->assertCount(count(FileCategory::cases()), $expected);
    }

    public function test_only_a_profile_photo_may_be_stored_publicly(): void
    {
        $public = array_values(array_filter(
            FileCategory::cases(),
            static fn (FileCategory $category): bool => $category->minimumVisibility() === FileVisibility::IsPublic,
        ));

        $this->assertSame([FileCategory::ProfilePhoto], $public);
    }

    public function test_nothing_describing_a_minor_is_storable_below_restricted(): void
    {
        foreach ([
            FileCategory::StudentDocument,
            FileCategory::AssignmentSubmission,
            FileCategory::Report,
            FileCategory::DigitalProduct,
        ] as $category) {
            $this->assertSame(
                FileVisibility::IsRestricted,
                $category->minimumVisibility(),
                $category->value.' is a minor\'s record or a paid product',
            );
        }
    }

    public function test_a_floor_can_be_exceeded_but_never_undercut(): void
    {
        // This is the rule FileService enforces, restated at the enum level so the
        // two halves of it are seen together: a row may be stored more
        // protectively than its category requires, and never less.
        $acceptedCount = [];

        foreach (FileCategory::cases() as $category) {
            $floor = $category->minimumVisibility()->rank();

            $accepted = array_values(array_filter(
                FileVisibility::cases(),
                static fn (FileVisibility $tier): bool => $tier->rank() >= $floor,
            ));

            $acceptedCount[$category->value] = count($accepted);

            $this->assertContains($category->minimumVisibility(), $accepted, $category->value);
        }

        $this->assertSame(4, $acceptedCount['profile_photo']);
        $this->assertSame(3, $acceptedCount['course_resource']);
        $this->assertSame(2, $acceptedCount['invoice']);
        $this->assertSame(1, $acceptedCount['digital_product']);
    }

    public function test_every_tier_has_a_storage_tier_in_config(): void
    {
        foreach (FileVisibility::cases() as $tier) {
            $configured = config('platform.files.tiers.'.$tier->value);

            $this->assertIsArray($configured, $tier->value.' has no storage tier');
            $this->assertArrayHasKey('disk', $configured, $tier->value);
            $this->assertIsString($configured['disk'], $tier->value);
            $this->assertNotSame('', $configured['disk'], $tier->value);
            $this->assertArrayHasKey('served_by_webserver', $configured, $tier->value);
        }
    }

    public function test_only_the_public_tier_is_served_by_the_web_server(): void
    {
        foreach (FileVisibility::cases() as $tier) {
            $served = (bool) config('platform.files.tiers.'.$tier->value.'.served_by_webserver');

            $this->assertSame(
                $tier === FileVisibility::IsPublic,
                $served,
                $tier->value.' must not be reachable by the web server',
            );
        }
    }

    public function test_a_tier_the_web_server_cannot_serve_is_not_on_the_public_disk(): void
    {
        // The failure this catches is the one no authorization test can see: the
        // `public` disk is the single directory symlinked into the document root, so
        // a protected tier pointed at it is served by Apache regardless of what
        // FilePolicy decides.
        foreach (FileVisibility::cases() as $tier) {
            $disk = (string) config('platform.files.tiers.'.$tier->value.'.disk');
            $served = (bool) config('platform.files.tiers.'.$tier->value.'.served_by_webserver');

            if (! $served) {
                $this->assertNotSame('public', $disk, $tier->value.' would be web-reachable');
            }
        }
    }

    public function test_every_tier_names_a_disk_that_is_configured(): void
    {
        foreach (FileVisibility::cases() as $tier) {
            $disk = (string) config('platform.files.tiers.'.$tier->value.'.disk');

            $this->assertIsArray(
                config('filesystems.disks.'.$disk),
                $tier->value.' points at the undeclared disk "'.$disk.'"',
            );
        }
    }

    public function test_the_protected_disks_are_declared_private_and_do_not_serve(): void
    {
        foreach (FileVisibility::cases() as $tier) {
            if ($tier === FileVisibility::IsPublic) {
                continue;
            }

            $disk = (string) config('platform.files.tiers.'.$tier->value.'.disk');

            $this->assertSame('private', (string) config('filesystems.disks.'.$disk.'.visibility'), $disk);
            $this->assertFalse((bool) config('filesystems.disks.'.$disk.'.serve', true), $disk);
        }
    }

    public function test_exactly_one_directory_is_symlinked_into_the_document_root(): void
    {
        $links = (array) config('filesystems.links');

        $this->assertCount(1, $links);

        foreach ($links as $link => $target) {
            $this->assertStringEndsWith('storage', (string) $link);
            $this->assertStringEndsWith('app'.DIRECTORY_SEPARATOR.'public', (string) $target);
        }
    }
}
