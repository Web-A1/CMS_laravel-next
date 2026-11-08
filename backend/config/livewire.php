<?php

return [
    // Transport ceiling; individual fields still enforce their own smaller limits.
    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:25600'],
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma', 'avif'],
    ],
];
