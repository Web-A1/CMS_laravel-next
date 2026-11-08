<?php

return [
    'disk' => env('REVIEW_MEDIA_DISK', 'public'),
    'frontend_public' => env('REVIEW_MEDIA_FRONTEND_PUBLIC', base_path('../frontend/public')),
    'binary' => env('REVIEW_IMAGE_BINARY'),
    'max_bytes' => 25 * 1024 * 1024,
    'max_pixels' => 60000000,
    'max_edge' => 20000,
    'timeout' => 60,
    'temporary_hours' => 24,
];
