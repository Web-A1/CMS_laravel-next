<?php

$supportStorageDriver = env('SUPPORT_STORAGE_DRIVER', 'local');

$publicMediaS3 = [
    'driver' => 's3',
    // Keep the previous env names readable during a staged configuration rollout.
    'key' => env('PUBLIC_MEDIA_S3_ACCESS_KEY_ID', env('REVIEW_MEDIA_S3_ACCESS_KEY_ID')),
    'secret' => env('PUBLIC_MEDIA_S3_SECRET_ACCESS_KEY', env('REVIEW_MEDIA_S3_SECRET_ACCESS_KEY')),
    'region' => env('PUBLIC_MEDIA_S3_REGION', env('REVIEW_MEDIA_S3_REGION')),
    'bucket' => env('PUBLIC_MEDIA_S3_BUCKET', env('REVIEW_MEDIA_S3_BUCKET')),
    'url' => env('PUBLIC_MEDIA_S3_URL', env('REVIEW_MEDIA_S3_URL')),
    'endpoint' => env('PUBLIC_MEDIA_S3_ENDPOINT', env('REVIEW_MEDIA_S3_ENDPOINT')),
    'use_path_style_endpoint' => env('PUBLIC_MEDIA_S3_USE_PATH_STYLE_ENDPOINT', env('REVIEW_MEDIA_S3_USE_PATH_STYLE_ENDPOINT', false)),
    'visibility' => 'public',
    'throw' => true,
    'report' => false,
];

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

        'support-private' => [
            'driver' => $supportStorageDriver,
            'root' => $supportStorageDriver === 'local' ? storage_path('app/support-private') : trim((string) env('SUPPORT_S3_PREFIX', ''), '/'),
            'key' => env('SUPPORT_S3_ACCESS_KEY_ID'),
            'secret' => env('SUPPORT_S3_SECRET_ACCESS_KEY'),
            'region' => env('SUPPORT_S3_REGION'),
            'bucket' => env('SUPPORT_S3_BUCKET'),
            'endpoint' => env('SUPPORT_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('SUPPORT_S3_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

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

        // Only existing review working files can be retired from this frontend directory.
        'review-media-legacy' => [
            'driver' => 'local',
            'root' => env('REVIEW_MEDIA_FRONTEND_PUBLIC', base_path('../frontend/public')),
            'url' => '/',
            'throw' => true,
        ],

        // URL references for development fixtures. Local cleanup never deletes shared staging objects.
        'review-media-published' => [
            'driver' => 'local',
            'root' => storage_path('app/published-review-references'),
            'url' => env('PUBLISHED_REVIEW_MEDIA_URL', 'https://media.example.invalid'),
            'visibility' => 'public',
            'throw' => true,
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

        // Stable domain disk names are persisted in their respective asset registries.
        'review-media-s3' => $publicMediaS3,
        'article-media-s3' => $publicMediaS3,

        'protected-source-s3' => [
            'driver' => 's3',
            'key' => env('PROTECTED_SOURCE_S3_ACCESS_KEY_ID'),
            'secret' => env('PROTECTED_SOURCE_S3_SECRET_ACCESS_KEY'),
            'region' => env('PROTECTED_SOURCE_S3_REGION'),
            'bucket' => env('PROTECTED_SOURCE_S3_BUCKET'),
            'endpoint' => env('PROTECTED_SOURCE_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('PROTECTED_SOURCE_S3_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'protected-output-s3' => [
            'driver' => 's3',
            'key' => env('PROTECTED_OUTPUT_S3_ACCESS_KEY_ID'),
            'secret' => env('PROTECTED_OUTPUT_S3_SECRET_ACCESS_KEY'),
            'region' => env('PROTECTED_OUTPUT_S3_REGION'),
            'bucket' => env('PROTECTED_OUTPUT_S3_BUCKET'),
            'endpoint' => env('PROTECTED_OUTPUT_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('PROTECTED_OUTPUT_S3_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
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
        public_path('storage') => storage_path('app/public'),
    ],

];
