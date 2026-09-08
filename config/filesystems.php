<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Visibility tiers (§59, ADR-10)
        |----------------------------------------------------------------------
        |
        | Four physical disks, one per tier declared in config('platform.files').
        | The tier is stored PER FILE ROW, not inferred from the path, so a file
        | can be reclassified (a draft made public, a public asset withdrawn)
        | without moving it and without a window where it is reachable under both
        | rules.
        |
        | The three non-public tiers are deliberately identical in shape and
        | differ only in which policies may read them. What they share:
        |
        |   • `serve => false` — Laravel's built-in file-serving route is off, so
        |     there is no URL that returns these bytes. Access is only ever via a
        |     controller that checks auth → purchase → payment → entitlement, and
        |     records the download.
        |   • `visibility => 'private'` — no object is ever written world-readable,
        |     which matters once the root is moved to S3.
        |   • `throw => true` — a failed write must be loud. Silently failing to
        |     store a signed certificate or a paid product file is data loss the
        |     user will not notice until they need it.
        |
        | On cPanel these roots must sit OUTSIDE public_html (docs/09 §3). If
        | they do not, every protection above is moot: the web server will serve
        | the file directly, whatever PHP says.
        |
        */

        'authenticated' => [
            'driver' => env('FILESYSTEM_DRIVER_AUTHENTICATED', 'local'),
            'root' => env('FILESYSTEM_ROOT_AUTHENTICATED', storage_path('app/authenticated')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],

        'private' => [
            'driver' => env('FILESYSTEM_DRIVER_PRIVATE', 'local'),
            'root' => env('FILESYSTEM_ROOT_PRIVATE', storage_path('app/private')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],

        'restricted' => [
            'driver' => env('FILESYSTEM_DRIVER_RESTRICTED', 'local'),
            'root' => env('FILESYSTEM_ROOT_RESTRICTED', storage_path('app/restricted')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        // ONLY the public tier is ever symlinked into the document root. Adding a
        // second entry here would expose student records and paid product files
        // to anyone who could guess a path (§59).
        public_path('storage') => storage_path('app/public'),
    ],

];
