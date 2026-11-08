<?php

namespace Database\Seeders;

use App\Models\ContentAuthor;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Course;
use App\Models\Review;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use LogicException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('demo.enabled') || config('database.default') !== 'sqlite') {
            throw new LogicException('Demo seeding requires the isolated SQLite showcase.');
        }

        // Reviewed public fields only. No original seeders, accounts or lesson bodies.
        $snapshot = json_decode(file_get_contents(database_path('demo/public-homepage.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['subjects'] as $position => $item) {
            $subject = Subject::updateOrCreate(['slug' => $item['slug']], [
                'title' => $item['title'], 'is_active' => true, 'sort_order' => $position + 1,
            ]);
            foreach ($item['courses'] as $order => $course) {
                Course::updateOrCreate(['subject_id' => $subject->id, 'slug' => $course['slug']], [
                    'title' => $course['title'], 'short_title' => $course['shortTitle'],
                    'price_cents' => $course['price'], 'is_active' => true, 'sort_order' => $order + 1,
                ]);
            }
        }
        foreach ($snapshot['teachers'] as $position => $teacher) {
            ContentAuthor::updateOrCreate(['slug' => $teacher['slug']], [
                'name' => $teacher['name'], 'home_role_text' => $teacher['homeRoleText'],
                'home_cutout_key' => $teacher['cutoutKey'], 'home_sort_order' => $position + 1,
            ]);
        }
        foreach ($snapshot['reviews'] as $item) {
            $reviewSubject = $item['subjectSlug'] ? Subject::where('slug', $item['subjectSlug'])->firstOrFail() : null;
            $course = $item['courseSlug'] && $reviewSubject
                ? Course::where('subject_id', $reviewSubject->id)->where('slug', $item['courseSlug'])->first() : null;
            $photo = $item['photo'] ? 'showcase/'.$item['photo'] : null;
            if ($photo) {
                Storage::disk('public')->put($photo, file_get_contents(database_path('demo-media/'.$item['photo'])));
            }
            Review::updateOrCreate(['source_ref' => $item['sourcePage']], [
                'display_name' => $item['displayName'], 'text' => $item['text'],
                'subject_id' => $reviewSubject?->id, 'course_id' => $course?->id,
                'author_photo_path' => $photo, 'review_written_at' => $item['reviewWrittenAt'],
                'published_at' => $item['reviewWrittenAt'], 'show_on_home' => true,
                'status' => Review::STATUS_PUBLISHED, 'source' => 'dev_seed',
                'moderation_status' => Review::MODERATION_APPROVED,
                'publication_status' => Review::PUBLICATION_PUBLISHED,
                'dedupe_status' => Review::DEDUPE_UNIQUE, 'origin_type' => Review::ORIGIN_DEV_SEED,
            ]);
        }
        $articles = json_decode(file_get_contents(database_path('demo/public-articles.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($articles['assets'] as $asset) {
            $contents = file_get_contents(base_path('../'.$asset['path']));
            if (hash('sha256', $contents) !== $asset['sha256'] || strlen($contents) !== $asset['bytes']) {
                throw new LogicException('Demo article media does not match the public snapshot.');
            }
            Storage::disk('public')->put($asset['storagePath'], $contents);
        }
        foreach ($articles['articles'] as $position => $item) {
            $author = ContentAuthor::updateOrCreate(['slug' => $item['author']['slug']], [
                'name' => $item['author']['name'], 'avatar_path' => $item['author']['avatarPath'],
                'photo_path' => $item['author']['photoPath'],
            ]);
            $subject = $item['subjectSlug'] ? Subject::where('slug', $item['subjectSlug'])->firstOrFail() : null;
            $entry = ContentEntry::updateOrCreate(['content_type' => 'article', 'slug' => $item['slug']], [
                'title' => $item['title'], 'body' => $item['body'], 'body_format' => $item['bodyFormat'],
                'excerpt' => $item['excerpt'], 'seo_title' => $item['seoTitle'], 'seo_description' => $item['seoDescription'],
                'status' => 'published', 'published_at' => $item['publishedAt'],
                'content_updated_at' => $item['updatedAt'], 'content_author_id' => $author->id,
                'cover_path' => $item['cover']['path'], 'cover_alt' => $item['cover']['alt'],
                'cover_width' => $item['cover']['width'], 'cover_height' => $item['cover']['height'],
                'subject_id' => $subject?->id, 'payload' => [], 'tags' => [],
            ]);
            ContentPlacement::updateOrCreate(['content_entry_id' => $entry->id,
                'placement' => ContentPlacement::ARTICLES_INDEX], ['is_enabled' => true, 'sort_order' => $position + 1]);
        }

        foreach ($snapshot['faqs'] as $position => $faq) {
            $category = $faq['category'] ? ContentCategory::updateOrCreate([
                'content_type' => 'faq', 'slug' => $faq['category']['slug'],
            ], ['title' => $faq['category']['title'], 'is_active' => true]) : null;
            $entry = ContentEntry::updateOrCreate(['content_type' => 'faq', 'slug' => $faq['slug']], [
                'title' => $faq['question'], 'body' => $faq['answer'], 'body_format' => 'plain',
                'content_category_id' => $category?->id,
                'status' => 'published', 'published_at' => '2026-01-01 12:00:00', 'payload' => [],
            ]);
            foreach ([ContentPlacement::FAQ_INDEX, ContentPlacement::HOME_FAQ] as $placement) {
                ContentPlacement::updateOrCreate(['content_entry_id' => $entry->id, 'placement' => $placement],
                    ['is_enabled' => true, 'sort_order' => $position + 1]);
            }
        }
    }
}

