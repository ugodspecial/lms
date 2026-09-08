<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform configuration
|--------------------------------------------------------------------------
|
| Business-level configuration that is NOT secret and NOT frequently changed
| by administrators. Anything an admin should be able to change at runtime
| lives in the `settings` table instead (§60, §95).
|
| Secrets belong in .env only. Non-secret runtime configuration belongs in
| the database. This file holds the structural wiring between the two.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity & base currency
    |--------------------------------------------------------------------------
    |
    | `currency` is the default for NEW records only. Every stored amount keeps
    | its own ISO code, so changing this never rewrites history and never
    | converts an existing price (ADR-02). No currency symbol is ever written
    | into business logic — App\Support\Money\MoneyFormatter resolves it from
    | `currencies` below.
    |
    */

    'name' => env('PLATFORM_NAME', env('APP_NAME', 'EduPlatform')),
    'currency' => strtoupper((string) env('PLATFORM_DEFAULT_CURRENCY', 'NGN')),

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */

    'meta' => [
        // A staging deploy that is accidentally indexable would put student
        // records into a search engine. `noindex` is the default everywhere but
        // production, and is overridable because a real marketing site needs the
        // opposite (§6, Phase 11).
        'noindex' => env('APP_ENV') !== 'production'
            || filter_var(env('PLATFORM_META_NOINDEX', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone (§62, ADR-08)
    |--------------------------------------------------------------------------
    |
    | `config('app.timezone')` stays UTC and MUST NOT be changed: it is the
    | storage timezone. The value below is only the default *display* timezone
    | for a viewer who has not chosen one, and it is overridable per user and
    | per request by App\Support\Time\TimezonePresenter.
    |
    */

    'timezone' => [
        'default' => env('PLATFORM_DEFAULT_TIMEZONE', 'Africa/Lagos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal areas (§6, docs/01 §5.1)
    |--------------------------------------------------------------------------
    |
    | Area access is permission-driven, never role-string driven. A user may
    | belong to several areas at once (a tutor who is also a parent), which is
    | why the portal switcher is built from these permissions.
    |
    */

    'areas' => [
        'admin' => [
            'path' => 'admin',
            'home' => 'admin.dashboard',
            'permission_prefixes' => [
                'students.', 'parents.', 'applications.', 'programs.', 'subjects.',
                'academic_', 'courses.', 'assessments.', 'cohorts.', 'enrollments.',
                'attendance.', 'grades.', 'certificates.', 'tutors.', 'products.',
                'orders.', 'payments.', 'invoices.', 'subscriptions.', 'entitlements.',
                'coupons.', 'reports.', 'settings.', 'users.', 'roles.', 'audit.',
                'imports.', 'exports.', 'announcements.', 'holidays.', 'reviews.',
            ],
            'permissions' => ['admin.panel.access'],
        ],
        'parent' => [
            'path' => 'parent',
            'home' => 'parent.dashboard',
            'permissions' => ['parent.portal.access'],
        ],
        'student' => [
            'path' => 'learn',
            'home' => 'student.dashboard',
            'permissions' => ['student.portal.access'],
        ],
        'tutor' => [
            'path' => 'tutor',
            'home' => 'tutor.dashboard',
            'permissions' => ['tutor.portal.access'],
        ],
        'evaluator' => [
            'path' => 'evaluator',
            'home' => 'evaluator.dashboard',
            // §49: an evaluator's area contains no financial permission by design.
            'permissions' => ['evaluator.portal.access'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies (§61, ADR-02)
    |--------------------------------------------------------------------------
    |
    | Money is stored as integer minor units + an ISO-4217 code. The exponent
    | is what converts between the two, and it is NOT always 2 (XOF has 0).
    | Adding a currency is a config change, never a migration.
    |
    | The organisation's active currency is the `currency` setting in the
    | database — this list only defines what is *available*.
    |
    */

    'currencies' => [
        'NGN' => ['name' => 'Nigerian Naira', 'exponent' => 2, 'symbol' => '₦', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'USD' => ['name' => 'US Dollar', 'exponent' => 2, 'symbol' => '$', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'GBP' => ['name' => 'Pound Sterling', 'exponent' => 2, 'symbol' => '£', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'EUR' => ['name' => 'Euro', 'exponent' => 2, 'symbol' => '€', 'symbol_first' => true, 'decimal' => ',', 'thousands' => '.'],
        'GHS' => ['name' => 'Ghanaian Cedi', 'exponent' => 2, 'symbol' => '₵', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'ZAR' => ['name' => 'South African Rand', 'exponent' => 2, 'symbol' => 'R', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'KES' => ['name' => 'Kenyan Shilling', 'exponent' => 2, 'symbol' => 'KSh', 'symbol_first' => true, 'decimal' => '.', 'thousands' => ','],
        'XOF' => ['name' => 'West African CFA franc', 'exponent' => 0, 'symbol' => 'CFA', 'symbol_first' => true, 'decimal' => '', 'thousands' => ' '],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags (§93)
    |--------------------------------------------------------------------------
    |
    | A partially-configured deployment must never show a broken feature. When
    | a flag is false the entry point is not rendered at all, and
    | `platform:doctor` explains what is missing. This is how the platform
    | avoids "buttons that do nothing".
    |
    | These are structural flags. Business toggles live in `settings`.
    |
    */

    'features' => [
        'admissions' => env('PLATFORM_FEATURE_ADMISSIONS', true),
        'lms' => env('PLATFORM_FEATURE_LMS', true),
        'tutoring' => env('PLATFORM_FEATURE_TUTORING', true),
        'commerce' => env('PLATFORM_FEATURE_COMMERCE', true),
        'subscriptions' => env('PLATFORM_FEATURE_SUBSCRIPTIONS', true),
        'public_tutor_directory' => env('PLATFORM_FEATURE_PUBLIC_DIRECTORY', true),
        'reviews' => env('PLATFORM_FEATURE_REVIEWS', true),
        'imports' => env('PLATFORM_FEATURE_IMPORTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo accounts (§74)
    |--------------------------------------------------------------------------
    |
    | Development-only. The /demo-login route is not even registered when this
    | is disabled, and PlatformDoctor refuses a production environment that has
    | it switched on.
    |
    */

    'demo_accounts' => [
        'enabled' => filter_var(env('PLATFORM_DEMO_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN)
            && env('APP_ENV') !== 'production',
        'password' => env('PLATFORM_DEMO_PASSWORD', 'password'),
        'domain' => 'example.test',
        'accounts' => [
            'superadmin' => ['email' => 'superadmin@example.test', 'name' => 'Demo Super Admin'],
            'admin' => ['email' => 'admin@example.test', 'name' => 'Demo Administrator'],
            'evaluator' => ['email' => 'evaluator@example.test', 'name' => 'Demo Evaluator'],
            'tutor' => ['email' => 'tutor@example.test', 'name' => 'Demo Tutor'],
            'parent' => ['email' => 'parent@example.test', 'name' => 'Demo Parent'],
            'student' => ['email' => 'student@example.test', 'name' => 'Demo Student'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File visibility tiers (§40, §59, ADR-10)
    |--------------------------------------------------------------------------
    |
    | Each tier maps to a physical disk. Only `public` is ever symlinked into
    | the document root; `private` and `restricted` live outside the webroot on
    | cPanel (docs/09 §3).
    |
    */

    'files' => [
        'tiers' => [
            'public' => ['disk' => 'public', 'served_by_webserver' => true],
            'authenticated' => ['disk' => 'authenticated', 'served_by_webserver' => false],
            'private' => ['disk' => 'private', 'served_by_webserver' => false],
            'restricted' => ['disk' => 'restricted', 'served_by_webserver' => false],
        ],
        'max_upload_kb' => (int) env('PLATFORM_MAX_UPLOAD_KB', 20480),
        'allowed_mime_types' => [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv',
            'video/mp4', 'audio/mpeg', 'audio/mp4',
            'application/zip',
        ],
        // SVG can carry script; blocked by default (§57).
        'blocked_extensions' => ['svg', 'exe', 'bat', 'sh', 'php', 'phar', 'js', 'htm', 'html', 'jar', 'dll', 'so'],
        'download_rate_limit' => ['max_attempts' => 30, 'decay_minutes' => 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log redaction (§77)
    |--------------------------------------------------------------------------
    |
    | Keys matching these patterns are replaced with '[REDACTED]' before any
    | log write or `integration_logs`/`audit_logs` payload is persisted. This
    | makes "never log secrets" structural rather than a convention.
    |
    */

    'logging' => [
        'redact_keys' => [
            'password', 'password_confirmation', 'current_password', 'new_password',
            'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'apikey',
            'secret', 'client_secret', 'secret_key', 'private_key',
            'two_factor_secret', 'two_factor_recovery_codes', 'otp', 'code',
            'card', 'card_number', 'cvv', 'cvc', 'exp_month', 'exp_year',
            'authorization', 'cookie', 'set_cookie', 'x-paystack-signature',
            'sk_', 'pk_',
        ],
        'redact_value_patterns' => [
            '/\bsk_(test|live)_[A-Za-z0-9]+/',
            '/\bpk_(test|live)_[A-Za-z0-9]+/',
            '/\bBearer\s+[A-Za-z0-9\-_\.]+/i',
        ],
        'retention_days' => [
            'default' => (int) env('LOG_RETENTION_DAYS', 14),
            'payments' => (int) env('PAYMENT_LOG_RETENTION_DAYS', 730),
            'audit' => 365,
            'integration' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video meetings (§14, §36, §37, ADR-05)
    |--------------------------------------------------------------------------
    */

    'meetings' => [
        'default_provider' => env('PLATFORM_DEFAULT_VIDEO_PROVIDER', 'manual'),
        'providers' => [
            'google_meet' => ['enabled' => filter_var(env('GOOGLE_MEET_ENABLED', false), FILTER_VALIDATE_BOOLEAN), 'requires_host_connection' => true],
            'zoom' => ['enabled' => filter_var(env('ZOOM_ENABLED', false), FILTER_VALIDATE_BOOLEAN), 'requires_host_connection' => true],
            // Not a stub: stores a human-supplied join URL and labels the
            // meeting as manual. It never implies Google or Zoom created it.
            'manual' => ['enabled' => true, 'requires_host_connection' => false],
        ],
        'provisioning_max_attempts' => 3,
        'fallback_to_manual' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | External integrations (§14, §36, §37, §41, ADR-05)
    |--------------------------------------------------------------------------
    |
    | The single source of truth for "is this integration usable right now?".
    | `required` lists config keys that must be present and must not still hold
    | the value shipped in .env.example. App\Http\Middleware\
    | EnsureIntegrationConfigured and `php artisan platform:doctor` both read
    | this, so the UI, the route guard and the operator's checklist can never
    | disagree about what is configured (§63: no dead buttons).
    |
    | Adding an integration is a config change plus a driver class — never a
    | change scattered across controllers.
    |
    */

    'integrations' => [
        'paystack' => [
            'label' => 'Paystack payments',
            'enabled' => filter_var(env('PAYSTACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'required' => ['services.paystack.secret', 'services.paystack.public'],
            'docs' => 'SETUP.md#paystack',
            'phase' => 8,
        ],
        'google' => [
            'label' => 'Google sign-in, Calendar & Meet',
            'enabled' => filter_var(env('GOOGLE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'required' => ['services.google.client_id', 'services.google.client_secret'],
            'docs' => 'SETUP.md#google',
            'phase' => 7,
        ],
        'google-meet' => [
            'label' => 'Google Meet provisioning',
            // Meet needs the Calendar scope in addition to sign-in, so it is a
            // separate gate: an org may want Google login without Google Meet.
            'enabled' => filter_var(env('GOOGLE_MEET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'required' => ['services.google.client_id', 'services.google.client_secret'],
            'docs' => 'SETUP.md#google-meet',
            'phase' => 7,
        ],
        'microsoft' => [
            'label' => 'Microsoft sign-in',
            'enabled' => filter_var(env('MICROSOFT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'required' => ['services.microsoft.client_id', 'services.microsoft.client_secret'],
            'docs' => 'SETUP.md#microsoft',
            'phase' => 7,
        ],
        'zoom' => [
            'label' => 'Zoom meetings',
            'enabled' => filter_var(env('ZOOM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'required' => ['services.zoom.client_id', 'services.zoom.client_secret'],
            'docs' => 'SETUP.md#zoom',
            'phase' => 7,
        ],
        'mail' => [
            'label' => 'Transactional email',
            // Mail always has a working transport (log/array in development), so
            // it is gated on the from-address rather than on a credential.
            'enabled' => true,
            'required' => ['mail.from.address'],
            'docs' => 'SETUP.md#email',
            'phase' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Support & contact (§76)
    |--------------------------------------------------------------------------
    */

    'support' => [
        'email' => env('PLATFORM_SUPPORT_EMAIL', 'support@example.test'),
        'name' => env('PLATFORM_SUPPORT_NAME', 'Support'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API (§68)
    |--------------------------------------------------------------------------
    */

    'api' => [
        'version' => 'v1',
        'enabled' => filter_var(env('PLATFORM_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'rate_limit' => env('PLATFORM_API_RATE_LIMIT', '60:1'),
        // Paths that may never become a public profile slug (§68).
        'reserved' => [
            'admin', 'api', 'learn', 'parent', 'tutor', 'evaluator', 'auth', 'login',
            'logout', 'register', 'password', 'verify', 'webhooks', 'up', 'storage',
            'build', 'assets', 'demo', 'demo-login', 'settings', 'account', 'billing',
            'checkout', 'cart', 'orders', 'invoices', 'search', 'status', 'health',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery modules (§91–§92)
    |--------------------------------------------------------------------------
    |
    | Declared here rather than in a view so the build status shown to an
    | operator is data, not markup — and so `platform:doctor` can report which
    | modules are live in *this* deployment. `phase` is the delivery phase that
    | makes the module real; a module below the current phase is not yet wired
    | into navigation and its entry points are not rendered (§63).
    |
    */

    'modules' => [
        'identity' => ['label' => 'Identity & Access', 'phase' => 1, 'area' => 'admin'],
        'education' => ['label' => 'Education Management', 'phase' => 2, 'area' => 'admin'],
        'lms' => ['label' => 'Learning (LMS)', 'phase' => 3, 'area' => 'student'],
        'tutoring' => ['label' => 'Tutors & Booking', 'phase' => 4, 'area' => 'tutor'],
        'assessment' => ['label' => 'Assessment & Grading', 'phase' => 5, 'area' => 'evaluator'],
        'communication' => ['label' => 'Meetings & Messaging', 'phase' => 7, 'area' => 'admin'],
        'commerce' => ['label' => 'Commerce & Payments', 'phase' => 8, 'area' => 'admin'],
        'integration' => ['label' => 'External Integrations', 'phase' => 7, 'area' => 'admin'],
        'administration' => ['label' => 'Administration & Reporting', 'phase' => 10, 'area' => 'admin'],
    ],
];
