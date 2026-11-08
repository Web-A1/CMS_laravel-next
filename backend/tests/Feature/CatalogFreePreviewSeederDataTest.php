<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogFreePreviewSeederDataTest extends TestCase
{
    public function test_catalog_seed_models_legacy_platform_front_catalog(): void
    {
        $catalog = json_decode(
            file_get_contents(database_path('seeders/data/catalog_subjects_courses.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $expectedSubjects = [
            'anatomiya' => 'Анатомия',
            'gistologiya' => 'Гистология',
            'latinskij-yazyk' => 'Латинский язык',
            'bioorganicheskaya-himiya' => 'Биоорганическая химия',
            'mikrobiologiya' => 'Микробиология',
            'fiziologiya' => 'Нормальная физиология',
            'biohimiya' => 'Биохимия',
            'patologicheskaya-anatomiya' => 'Патологическая анатомия',
            'patofiziologiya' => 'Патологическая физиология',
            'farmakologiya' => 'Фармакология',
            'propedevtika-vnutrennih-boleznej' => 'Пропедевтика внутренних болезней',
            'hirurgiya' => 'Хирургия',
            'nevrologiya' => 'Неврология',
            'luchevaya-diagnostika' => 'Лучевая диагностика',
        ];

        $subjectsBySlug = collect($catalog['subjects'] ?? [])->keyBy('slug');
        $courses = $subjectsBySlug
            ->flatMap(fn (array $subject): array => array_map(
                fn (array $course): array => [$subject, $course],
                $subject['courses'] ?? [],
            ))
            ->values();
        $lessons = $courses
            ->flatMap(fn (array $pair): array => array_map(
                fn (array $lesson): array => [$pair[0], $pair[1], $lesson],
                $pair[1]['lessons'] ?? [],
            ))
            ->values();

        $this->assertEqualsCanonicalizing(array_keys($expectedSubjects), $subjectsBySlug->keys()->all());
        $this->assertCount(64, $courses);
        $this->assertCount(445, $lessons);

        foreach ($expectedSubjects as $subjectSlug => $subjectTitle) {
            $subject = $subjectsBySlug->get($subjectSlug);

            $this->assertSame($subjectTitle, $subject['title']);
            $this->assertTrue($subject['is_active']);
        }

        $legacyPageIds = $lessons
            ->map(fn (array $pair): int => (int) ($pair[2]['metadata']['legacy_page_id'] ?? 0))
            ->all();

        $this->assertNotContains(0, $legacyPageIds);
        $this->assertCount(445, array_unique($legacyPageIds));

        $paths = $lessons
            ->map(fn (array $pair): string => "{$pair[0]['slug']}/{$pair[1]['slug']}/{$pair[2]['slug']}")
            ->all();

        $this->assertCount(445, array_unique($paths));

        $embryology = $lessons->filter(fn (array $pair): bool => $pair[1]['slug'] === 'ehmbriologiya');
        $this->assertSame(range(519, 531), $embryology
            ->map(fn (array $pair): int => $pair[2]['metadata']['legacy_page_id'])
            ->values()->all());
        $this->assertCount(432, $lessons->diffKeys($embryology));

        foreach ($lessons as [$subject, $course, $lesson]) {
            $this->assertSame(true, $lesson['is_active']);
            $this->assertNotEmpty($lesson['metadata']['legacy_course_page_id'] ?? null);
            $this->assertNotEmpty($lesson['metadata']['legacy_page_title'] ?? null);
            if ($course['slug'] === 'ehmbriologiya') {
                $this->assertSame('gistologiya', $subject['slug']);
                $this->assertSame(1194, $lesson['metadata']['legacy_course_page_id']);
                $this->assertSame('confirmed', $lesson['metadata']['legacy_mapping_status']);
                $this->assertSame('legacy_platform_live_20261012', $lesson['metadata']['legacy_mapping_source']);

                continue;
            }
            $this->assertSame(
                '5df5f8099bb9925c470de020943cf29f7a0aecf19f9c23030b9be99e6af5eaf3',
                $lesson['metadata']['legacy_platform_front_snapshot_sha256'] ?? null,
                "Lesson {$subject['slug']}/{$course['slug']}/{$lesson['slug']} must reference the verified frontend snapshot.",
            );
        }
    }

    public function test_free_preview_lessons_match_legacy_platform_free_flags(): void
    {
        $catalog = json_decode(
            file_get_contents(database_path('seeders/data/catalog_subjects_courses.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $freeLegacyPageIds = collect($catalog['subjects'] ?? [])
            ->flatMap(fn (array $subject): array => $subject['courses'] ?? [])
            ->flatMap(fn (array $course): array => $course['lessons'] ?? [])
            ->filter(fn (array $lesson): bool => ($lesson['is_free_preview'] ?? false) === true)
            ->map(fn (array $lesson): int => (int) ($lesson['metadata']['legacy_page_id'] ?? 0))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            13, 21, 24, 37, 52, 58, 65, 70, 77, 171, 178, 183, 188, 194, 199,
            209, 216, 221, 234, 240, 269, 275, 284, 298, 306, 331, 363, 367,
            368, 381, 390, 397, 415, 420, 444, 466, 471, 480, 486, 487, 499,
            508, 514, 527,
        ], $freeLegacyPageIds);

        $freeLessons = collect($catalog['subjects'] ?? [])
            ->flatMap(fn (array $subject): array => $subject['courses'] ?? [])
            ->flatMap(fn (array $course): array => $course['lessons'] ?? [])
            ->filter(fn (array $lesson): bool => ($lesson['is_free_preview'] ?? false) === true);

        foreach ($freeLessons as $lesson) {
            $metadata = $lesson['metadata'];
            if ($metadata['legacy_page_id'] === 527) {
                $this->assertSame(1194, $metadata['legacy_course_page_id']);
                $this->assertSame('legacy_platform_live_20261012', $metadata['legacy_mapping_source']);
                $this->assertTrue($metadata['legacy_platform_is_free']);
            } else {
                $this->assertSame('legacy_platform_front_is_free', $metadata['legacy_free_preview_source']['kind'] ?? null);
            }
        }
    }

    public function test_legacy_platform_course_shape_matches_new_catalog_structure(): void
    {
        $catalog = json_decode(
            file_get_contents(database_path('seeders/data/catalog_subjects_courses.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $surgery = collect($catalog['subjects'] ?? [])->firstWhere('slug', 'hirurgiya');

        $this->assertSame(['obshchaya-hirurgiya'], array_column($surgery['courses'] ?? [], 'slug'));

        $generalSurgery = $surgery['courses'][0];
        $this->assertSame(
            [
                'krovotechenie-sposoby-ego-ostanovki-gemotransfuziya',
                'osnovy-onkologii',
                'desmurgiya',
                'aseptika-i-antiseptika',
                'metodika-obsledovaniya-hirurgicheskih-bolnyh',
                'perioperatsionnyj-period',
                'rany-ranevoj-protsess',
                'ozhogi-holodovaya-travma-elektrotravma',
                'rany-i-ranevoj-protsess',
                'ostryj-appenditsit',
                'gryzhi',
            ],
            array_column($generalSurgery['lessons'] ?? [], 'slug'),
        );

        $anatomy = collect($catalog['subjects'] ?? [])->firstWhere('slug', 'anatomiya');
        $this->assertContains('immunnaya-i-limfaticheskaya-sistema', array_column($anatomy['courses'] ?? [], 'slug'));

        $pathophysiology = collect($catalog['subjects'] ?? [])->firstWhere('slug', 'patofiziologiya');
        $this->assertContains('narusheniya-teplovogo-obmena', array_column($pathophysiology['courses'] ?? [], 'slug'));

        $propedeutics = collect($catalog['subjects'] ?? [])->firstWhere('slug', 'propedevtika-vnutrennih-boleznej');

        $this->assertSame(
            [
                'intensiv-ehkg' => 5,
                'obshchie-ponyatiya-propedevtiki' => 4,
                'propedevtika-dyhatelnoj-sistemy' => 11,
                'propedevtika-mochepolovoj-sistemy' => 4,
                'propedevtika-pishchevaritelnoj-sistemy' => 5,
            ],
            collect($propedeutics['courses'] ?? [])
                ->filter(fn (array $course): bool => in_array($course['slug'], [
                    'intensiv-ehkg',
                    'obshchie-ponyatiya-propedevtiki',
                    'propedevtika-dyhatelnoj-sistemy',
                    'propedevtika-mochepolovoj-sistemy',
                    'propedevtika-pishchevaritelnoj-sistemy',
                ], true))
                ->mapWithKeys(fn (array $course): array => [$course['slug'] => count($course['lessons'] ?? [])])
                ->all(),
        );
    }
}
