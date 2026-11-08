<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCurriculumOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seed_subjects_follow_curriculum_order(): void
    {
        $payload = $this->catalogPayload();

        $actualSlugs = collect($payload['subjects'])
            ->sortBy([
                ['sort_order', 'asc'],
                ['title', 'asc'],
            ])
            ->pluck('slug')
            ->values()
            ->all();

        $this->assertSame([
            'anatomiya',
            'gistologiya',
            'latinskij-yazyk',
            'bioorganicheskaya-himiya',
            'mikrobiologiya',
            'fiziologiya',
            'biohimiya',
            'patologicheskaya-anatomiya',
            'patofiziologiya',
            'farmakologiya',
            'propedevtika-vnutrennih-boleznej',
            'hirurgiya',
            'nevrologiya',
            'luchevaya-diagnostika',
        ], $actualSlugs);
    }

    public function test_catalog_seed_courses_follow_curriculum_order_inside_subjects(): void
    {
        $subjectsBySlug = collect($this->catalogPayload()['subjects'])->keyBy('slug');
        $expectedCourseSlugsBySubject = [
            'anatomiya' => [
                'osteologiya',
                'artrologiya',
                'miologiya',
                'pishchevaritelnaya-sistema',
                'intensiv-po-dyhatelnoj-sisteme',
                'intensiv-po-mochepolovoj-sisteme',
                'ehndokrinnaya-sistema',
                'intensiv-po-serdechno-sosudistoj-sisteme',
                'intensiv-po-tsentralnoj-nervnoj-sisteme',
                'intensiv-po-perifericheskoj-nervnoj-sisteme',
                'immunnaya-i-limfaticheskaya-sistema',
            ],
            'gistologiya' => [
                'tsitologiya',
                'obshchaya-gistologiya-tkani',
                'nervnaya-sistema-i-organy-chuvstv',
                'intensiv-po-gistologii-pishchevaritelnoj-sistemy',
                'intensiv-gistologiya-dyhatelnoj-sistemy',
                'gistologiya-mochepolovoj-sistemy',
                'gistologiya-ehndokrinnoj-sistemy',
                'gistologiya-serdechno-sosudistoj-sistemy',
                'intensiv-po-krovetvoreniyu-i-krovetvornym-organam',
                'ehmbriologiya',
            ],
            'mikrobiologiya' => [
                'obshchaya-mikrobiologiya',
                'immunologiya',
                'kishechnye-infektsii',
                'respiratornye-infektsii',
            ],
            'fiziologiya' => [
                'fiziologiya-vozbudimyh-tkanej',
                'intensiv-po-fiziologii-tsentralnoj-nervnoj-sistemy',
                'fiziologiya-pishchevaritelnoj-sistemy',
                'fiziologiya-serdechno-sosudistoj-sistemy',
            ],
            'biohimiya' => [
                'ehnzimologiya',
                'intensiv-po-azotistomu-obmenu',
                'nutriomika',
                'intensiv-po-uglevodnomu-i-ehnergeticheskomu-obmenu',
                'uglevodnyj-i-ehnergeticheskij-obmen-2-0',
                'lipidnyj-obmen',
                'gormonalnaya-regulyatsiya-metabolizma',
                'osobennosti-metabolizma-v-raznyh-tkanyah',
            ],
            'patologicheskaya-anatomiya' => [
                'intensiv-vnutrikletochnye-nakopleniya',
                'patologiya-sistemy-krovi',
                'patologiya-mochevydelitelnoj-sistemy',
            ],
            'patofiziologiya' => [
                'patologiya-i-povrezhdenie-kletki',
                'patologiya-vospaleniya',
                'narusheniya-teplovogo-obmena',
                'narusheniya-kislotno-shchelochnogo-ravnovesiya',
                'patofiziologiya-immunnoj-sistemy',
                'intensiv-patofiziologiya-krovi',
            ],
            'farmakologiya' => [
                'preparaty-dejstvuyushchie-na-pns',
                'preparaty-vliyayushchie-na-tsns',
                'preparaty-dlya-lecheniya-serdechno-sosudistyh-zabolevanij',
                'preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij',
                'sredstva-vliyayushchie-na-immunitet-npvs-gks',
                'intensiv-preparaty-dlya-razlichnyh-sistem',
                'himioterapevticheskie-preparaty',
            ],
            'propedevtika-vnutrennih-boleznej' => [
                'obshchie-ponyatiya-propedevtiki',
                'propedevtika-pishchevaritelnoj-sistemy',
                'propedevtika-dyhatelnoj-sistemy',
                'propedevtika-mochepolovoj-sistemy',
                'intensiv-ehkg',
            ],
        ];

        foreach ($expectedCourseSlugsBySubject as $subjectSlug => $expectedCourseSlugs) {
            $subject = $subjectsBySlug->get($subjectSlug);
            $this->assertNotNull($subject, "Subject [{$subjectSlug}] is missing from catalog seed.");

            $actualCourseSlugs = collect($subject['courses'])
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['title', 'asc'],
                ])
                ->pluck('slug')
                ->values()
                ->all();

            $this->assertSame($expectedCourseSlugs, $actualCourseSlugs, "Unexpected course order for [{$subjectSlug}].");
        }
    }

    public function test_curriculum_sort_migration_places_unlisted_active_courses_after_explicit_courses(): void
    {
        $subject = Subject::query()->create([
            'slug' => 'anatomiya',
            'title' => 'Анатомия',
            'sort_order' => 999,
            'is_active' => true,
        ]);
        Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'local-extra-course',
            'title' => 'Локальный дополнительный курс',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'osteologiya',
            'title' => 'Остеология',
            'sort_order' => 90,
            'is_active' => true,
        ]);
        Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'inactive-extra-course',
            'title' => 'Неактивный дополнительный курс',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $migration = require database_path('migrations/2026_07_28_120000_set_catalog_curriculum_sort_order.php');
        $migration->up();

        $this->assertSame(10, $subject->refresh()->sort_order);
        $this->assertSame([
            'osteologiya',
            'local-extra-course',
        ], Course::query()
            ->where('subject_id', $subject->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->pluck('slug')
            ->all());
        $this->assertGreaterThan(
            Course::query()
                ->where('subject_id', $subject->id)
                ->where('slug', 'osteologiya')
                ->value('sort_order'),
            Course::query()
                ->where('subject_id', $subject->id)
                ->where('slug', 'local-extra-course')
                ->value('sort_order'),
        );
    }

    /**
     * @return array{subjects: array<int, array<string, mixed>>}
     */
    private function catalogPayload(): array
    {
        return json_decode(
            file_get_contents(database_path('seeders/data/catalog_subjects_courses.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
