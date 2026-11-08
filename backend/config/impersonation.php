<?php

return [
    'session_key' => 'admin_impersonation',
    'version' => 1,
    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),
    'actor_roles' => ['admin_full', 'polina'],
    'subject_roles' => ['user_with_purchase', 'user_no_purchase'],
    'default_return_path' => '/admin/users',
];
