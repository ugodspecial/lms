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
}
