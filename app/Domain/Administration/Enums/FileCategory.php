<?php

declare(strict_types=1);

namespace App\Domain\Administration\Enums;

/**
 * What a file *is*, which drives retention, permitted MIME types and who may
 * download it (§40, §57, §59). A certificate and a course resource are both
 * files; treating them alike is how a protected product ends up publicly served.
 */
enum FileCategory: string
{
    case ProfilePhoto = 'profile_photo';
    case Cv = 'cv';
    case Certification = 'certification';
    case StudentDocument = 'student_document';
    case CourseResource = 'course_resource';
    case AssignmentSubmission = 'assignment_submission';
    case DigitalProduct = 'digital_product';
    case Certificate = 'certificate';
    case Invoice = 'invoice';
    case Report = 'report';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::ProfilePhoto => 'Profile photo',
            self::Cv => 'Curriculum vitae',
            self::Certification => 'Certification',
            self::StudentDocument => 'Student document',
            self::CourseResource => 'Course resource',
            self::AssignmentSubmission => 'Assignment submission',
            self::DigitalProduct => 'Digital product',
            self::Certificate => 'Certificate',
            self::Invoice => 'Invoice',
            self::Report => 'Report',
        };
    }

    /**
     * The least protective tier this category may be stored under (§57, §58, §59).
     *
     * The rule lives on the category rather than at each upload site because the
     * category is what a file IS, and that does not change with who is uploading
     * it: a student document is a minor's record whether an administrator, a
     * parent or an import attached it. A dropdown in a Livewire component offering
     * "Public" for a paid product file is the exact mistake this prevents, and it
     * is the mistake the enum's own docblock warns about — treating a certificate
     * and a course resource alike is how a protected product ends up served by the
     * web server.
     *
     * A row may always be stored MORE protectively than this. FileService refuses
     * the other direction.
     */
    public function minimumVisibility(): FileVisibility
    {
        return match ($this) {
            // A face and a name in a directory the platform publishes.
            self::ProfilePhoto => FileVisibility::IsPublic,

            // Course material: any signed-in user, which is what makes a lesson
            // resource usable without a per-file grant (docs/06, row 98).
            self::CourseResource => FileVisibility::IsAuthenticated,

            // One person's documents about one person. The owner and staff with a
            // reason — not every signed-in user. A certificate PDF is here rather
            // than above it because public verification happens through the
            // verification URL and its QR code, not by making the PDF readable.
            self::Cv, self::Certification, self::Certificate, self::Invoice => FileVisibility::IsPrivate,

            // A paid product file, a minor's record, graded work. Each is the
            // subject of an explicit authorization decision per request (§40, §58).
            self::DigitalProduct, self::StudentDocument, self::AssignmentSubmission, self::Report => FileVisibility::IsRestricted,
        };
    }
}
