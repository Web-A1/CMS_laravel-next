<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SUBJECT_ORDER = [
        'anatomiya' => 10,
        'gistologiya' => 20,
        'latinskij-yazyk' => 30,
        'bioorganicheskaya-himiya' => 40,
        'mikrobiologiya' => 50,
        'fiziologiya' => 60,
        'biohimiya' => 70,
        'patologicheskaya-anatomiya' => 80,
        'patofiziologiya' => 90,
        'farmakologiya' => 100,
        'propedevtika-vnutrennih-boleznej' => 110,
        'hirurgiya' => 120,
        'nevrologiya' => 130,
        'luchevaya-diagnostika' => 140,
    ];

    private const COURSE_ORDER = [
        'anatomiya' => [
            'osteologiya' => 10,
            'artrologiya' => 20,
            'miologiya' => 30,
            'pishchevaritelnaya-sistema' => 40,
            'intensiv-po-dyhatelnoj-sisteme' => 50,
            'intensiv-po-mochepolovoj-sisteme' => 60,
            'ehndokrinnaya-sistema' => 70,
            'intensiv-po-serdechno-sosudistoj-sisteme' => 80,
            'intensiv-po-tsentralnoj-nervnoj-sisteme' => 90,
            'intensiv-po-perifericheskoj-nervnoj-sisteme' => 100,
            'immunnaya-i-limfaticheskaya-sistema' => 110,
        ],
        'bioorganicheskaya-himiya' => [
            'bioorganicheskaya-himiya' => 10,
        ],
        'biohimiya' => [
            'ehnzimologiya' => 10,
            'intensiv-po-azotistomu-obmenu' => 20,
            'nutriomika' => 30,
            'intensiv-po-uglevodnomu-i-ehnergeticheskomu-obmenu' => 40,
            'uglevodnyj-i-ehnergeticheskij-obmen-2-0' => 50,
            'lipidnyj-obmen' => 60,
            'gormonalnaya-regulyatsiya-metabolizma' => 70,
            'osobennosti-metabolizma-v-raznyh-tkanyah' => 80,
        ],
        'gistologiya' => [
            'tsitologiya' => 10,
            'obshchaya-gistologiya-tkani' => 20,
            'nervnaya-sistema-i-organy-chuvstv' => 30,
            'intensiv-po-gistologii-pishchevaritelnoj-sistemy' => 40,
            'intensiv-gistologiya-dyhatelnoj-sistemy' => 50,
            'gistologiya-mochepolovoj-sistemy' => 60,
            'gistologiya-ehndokrinnoj-sistemy' => 70,
            'gistologiya-serdechno-sosudistoj-sistemy' => 80,
            'intensiv-po-krovetvoreniyu-i-krovetvornym-organam' => 90,
        ],
        'latinskij-yazyk' => [
            'latinskij-yazyk' => 10,
        ],
        'luchevaya-diagnostika' => [
            'luchevaya-diagnostika' => 10,
        ],
        'mikrobiologiya' => [
            'obshchaya-mikrobiologiya' => 10,
            'immunologiya' => 20,
            'kishechnye-infektsii' => 30,
            'respiratornye-infektsii' => 40,
        ],
        'nevrologiya' => [
            'dvigatelnye-narusheniya' => 10,
            'onmk-i-pnmk' => 20,
        ],
        'fiziologiya' => [
            'fiziologiya-vozbudimyh-tkanej' => 10,
            'intensiv-po-fiziologii-tsentralnoj-nervnoj-sistemy' => 20,
            'fiziologiya-pishchevaritelnoj-sistemy' => 30,
            'fiziologiya-serdechno-sosudistoj-sistemy' => 40,
        ],
        'patologicheskaya-anatomiya' => [
            'intensiv-vnutrikletochnye-nakopleniya' => 10,
            'patologiya-sistemy-krovi' => 30,
            'patologiya-mochevydelitelnoj-sistemy' => 40,
        ],
        'patofiziologiya' => [
            'patologiya-i-povrezhdenie-kletki' => 10,
            'patologiya-vospaleniya' => 20,
            'narusheniya-teplovogo-obmena' => 30,
            'narusheniya-kislotno-shchelochnogo-ravnovesiya' => 40,
            'patofiziologiya-immunnoj-sistemy' => 50,
            'intensiv-patofiziologiya-krovi' => 60,
        ],
        'propedevtika-vnutrennih-boleznej' => [
            'obshchie-ponyatiya-propedevtiki' => 10,
            'propedevtika-pishchevaritelnoj-sistemy' => 20,
            'propedevtika-dyhatelnoj-sistemy' => 30,
            'propedevtika-mochepolovoj-sistemy' => 40,
            'intensiv-ehkg' => 50,
        ],
        'farmakologiya' => [
            'preparaty-dejstvuyushchie-na-pns' => 10,
            'preparaty-vliyayushchie-na-tsns' => 20,
            'preparaty-dlya-lecheniya-serdechno-sosudistyh-zabolevanij' => 30,
            'preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij' => 40,
            'sredstva-vliyayushchie-na-immunitet-npvs-gks' => 50,
            'intensiv-preparaty-dlya-razlichnyh-sistem' => 60,
            'himioterapevticheskie-preparaty' => 70,
        ],
        'hirurgiya' => [
            'obshchaya-hirurgiya' => 20,
        ],
    ];

    public function up(): void
    {
        foreach (self::SUBJECT_ORDER as $slug => $sortOrder) {
            DB::table('subjects')
                ->where('slug', $slug)
                ->update(['sort_order' => $sortOrder]);
        }

        $subjectIdsBySlug = DB::table('subjects')
            ->whereIn('slug', array_keys(self::COURSE_ORDER))
            ->pluck('id', 'slug');

        foreach (self::COURSE_ORDER as $subjectSlug => $courses) {
            $subjectId = $subjectIdsBySlug->get($subjectSlug);

            if ($subjectId === null) {
                continue;
            }

            foreach ($courses as $courseSlug => $sortOrder) {
                DB::table('courses')
                    ->where('subject_id', $subjectId)
                    ->where('slug', $courseSlug)
                    ->update(['sort_order' => $sortOrder]);
            }

            $nextSortOrder = max($courses) + 10;
            $unlistedCourseIds = DB::table('courses')
                ->where('subject_id', $subjectId)
                ->where('is_active', true)
                ->whereNotIn('slug', array_keys($courses))
                ->orderBy('sort_order')
                ->orderBy('title')
                ->orderBy('id')
                ->pluck('id');

            foreach ($unlistedCourseIds as $index => $courseId) {
                DB::table('courses')
                    ->where('id', $courseId)
                    ->update(['sort_order' => $nextSortOrder + ($index * 10)]);
            }
        }
    }

    public function down(): void
    {
        // No rollback: previous operational ordering may include manual changes.
    }
};
