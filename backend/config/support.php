<?php

return [
    'address' => env('SUPPORT_ADDRESS', 'demo@example.test'),
    'disk' => 'support-private',
    'queue' => 'support',
    'max_files' => 5,
    'max_file_bytes' => 10 * 1024 * 1024,
    'voice_max_bytes' => 10 * 1024 * 1024,
    'voice_max_seconds' => 180.25,
    'ffprobe' => env('SUPPORT_FFPROBE', 'ffprobe'),
    'max_mail_bytes' => (int) env('SUPPORT_MAX_MAIL_BYTES', 25 * 1024 * 1024),
    'ip_per_hour' => (int) env('SUPPORT_IP_PER_HOUR', 10),
    'email_per_hour' => (int) env('SUPPORT_EMAIL_PER_HOUR', 5),
    'delivery_attempts' => 4,
    'lease_seconds' => 180,
    'imap' => [
        'enabled' => (bool) env('SUPPORT_IMAP_ENABLED', false),
        'host' => env('SUPPORT_IMAP_HOST'),
        'port' => (int) env('SUPPORT_IMAP_PORT', 993),
        'encryption' => env('SUPPORT_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('SUPPORT_IMAP_USERNAME'),
        'password' => env('SUPPORT_IMAP_PASSWORD'),
        'folder' => env('SUPPORT_IMAP_FOLDER', 'INBOX'),
        'batch' => 25,
    ],
];
