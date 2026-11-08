<?php

return [
    'source_database' => 'main_school_db',
    'mapping_version' => 1,
    'chunk_size' => 500,
    'max_page_size' => 50,
    'allowed_targets' => [
        'local' => ['host' => '127.0.0.1', 'port' => 55433, 'database' => 'cms_migration_rehearsal'],
        'staging' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'cms_staging'],
    ],
];
