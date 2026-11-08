<?php

return [
    'dev_profiles' => [
        'database' => [
            'database' => 'cms',
            'hosts' => ['127.0.0.1', 'localhost'],
        ],

        'profiles' => [
            'all' => [
                'email' => 'all@test.test',
                'name' => 'Demo Full Access',
                'phone' => '+79990000001',
                'role' => 'user_with_purchase',
            ],
            'partial' => [
                'email' => 'partial@test.test',
                'name' => 'Demo Partial Buyer',
                'phone' => '+79990000002',
                'role' => 'user_with_purchase',
            ],
            'empty' => [
                'email' => 'empty@test.test',
                'name' => 'Demo Registered Only',
                'phone' => '+79990000003',
                'role' => 'user_no_purchase',
            ],
            'reviewer' => [
                'email' => 'reviewer@test.test',
                'name' => 'Demo Assessment Reviewer',
                'phone' => '+79990000004',
                'role' => 'teacher',
            ],
        ],

        'partial_access' => [
            'full_courses' => [
                ['subject' => 'anatomiya', 'course' => 'osteologiya'],
                ['subject' => 'anatomiya', 'course' => 'miologiya'],
                ['subject' => 'anatomiya', 'course' => 'intensiv-po-dyhatelnoj-sisteme'],
                ['subject' => 'anatomiya', 'course' => 'intensiv-po-mochepolovoj-sisteme'],
                ['subject' => 'anatomiya', 'course' => 'intensiv-po-perifericheskoj-nervnoj-sisteme'],
                ['subject' => 'anatomiya', 'course' => 'intensiv-po-serdechno-sosudistoj-sisteme'],
                ['subject' => 'anatomiya', 'course' => 'intensiv-po-tsentralnoj-nervnoj-sisteme'],
                ['subject' => 'anatomiya', 'course' => 'pishchevaritelnaya-sistema'],
                ['subject' => 'anatomiya', 'course' => 'ehndokrinnaya-sistema'],
                ['subject' => 'anatomiya', 'course' => 'immunnaya-i-limfaticheskaya-sistema'],
                ['subject' => 'bioorganicheskaya-himiya', 'course' => 'bioorganicheskaya-himiya'],
                ['subject' => 'biohimiya', 'course' => 'gormonalnaya-regulyatsiya-metabolizma'],
                ['subject' => 'biohimiya', 'course' => 'intensiv-po-azotistomu-obmenu'],
                ['subject' => 'biohimiya', 'course' => 'intensiv-po-uglevodnomu-i-ehnergeticheskomu-obmenu'],
                ['subject' => 'biohimiya', 'course' => 'lipidnyj-obmen'],
                ['subject' => 'biohimiya', 'course' => 'nutriomika'],
                ['subject' => 'biohimiya', 'course' => 'osobennosti-metabolizma-v-raznyh-tkanyah'],
                ['subject' => 'mikrobiologiya', 'course' => 'respiratornye-infektsii'],
                ['subject' => 'nevrologiya', 'course' => 'onmk-i-pnmk'],
                ['subject' => 'fiziologiya', 'course' => 'fiziologiya-vozbudimyh-tkanej'],
                ['subject' => 'fiziologiya', 'course' => 'fiziologiya-serdechno-sosudistoj-sistemy'],
                ['subject' => 'patologicheskaya-anatomiya', 'course' => 'patologiya-mochevydelitelnoj-sistemy'],
                ['subject' => 'patofiziologiya', 'course' => 'intensiv-patofiziologiya-krovi'],
                ['subject' => 'patofiziologiya', 'course' => 'patologiya-vospaleniya'],
                ['subject' => 'patofiziologiya', 'course' => 'patofiziologiya-immunnoj-sistemy'],
                ['subject' => 'propedevtika-vnutrennih-boleznej', 'course' => 'obshchie-ponyatiya-propedevtiki'],
                ['subject' => 'propedevtika-vnutrennih-boleznej', 'course' => 'propedevtika-mochepolovoj-sistemy'],
                ['subject' => 'farmakologiya', 'course' => 'intensiv-preparaty-dlya-razlichnyh-sistem'],
                ['subject' => 'farmakologiya', 'course' => 'preparaty-dejstvuyushchie-na-pns'],
                ['subject' => 'farmakologiya', 'course' => 'himioterapevticheskie-preparaty'],
            ],
            'individual_lessons' => [
                [
                    'subject' => 'farmakologiya',
                    'course' => 'preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij',
                    'lesson' => 'preparaty-gormonov-gipofiza-i-gipotalamus',
                ],
                [
                    'subject' => 'farmakologiya',
                    'course' => 'preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij',
                    'lesson' => 'preparaty-gormonov-parashchitovidnyh-zhelez',
                ],
                [
                    'subject' => 'farmakologiya',
                    'course' => 'preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij',
                    'lesson' => 'preparaty-gormonov-shchitovidnoj-zhelezy-i-ih-antagonistov',
                ],
            ],
        ],
    ],
];
