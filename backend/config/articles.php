<?php

return [
    'media_disk' => env('ARTICLE_MEDIA_DISK', 'public'),
    'upload_ttl_hours' => 24,
    'image_binary' => env('ARTICLE_IMAGE_BINARY'),
    'image_timeout' => 90,
    'image_max_pixels' => 60000000,
    'image_max_edge' => 20000,
    'image_widths' => [64, 128, 192, 320, 640, 960, 1280, 1920],
];
