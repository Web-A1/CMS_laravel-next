<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_snapshot_is_repeatable_and_contains_no_accounts_or_lessons(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->getJson('/api/v1/catalog/subjects')->assertOk()->assertJsonCount(14, 'data')
            ->assertJsonPath('data.0.title', 'Анатомия')->assertJsonCount(11, 'data.0.courses')
            ->assertJsonCount(0, 'data.0.courses.0.lessons');
        $this->getJson('/api/v1/content-authors?placement=home')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/v1/reviews?placement=home&limit=20')->assertOk()->assertJsonCount(18, 'data');
        $this->getJson('/api/v1/faqs?placement=home.faq')->assertOk()->assertJsonCount(6, 'data');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_public_list_detail_search_and_unpublished_boundary_use_real_api(): void
    {
        $this->seed(DemoSeeder::class);
        $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(3, 'data');
        $snapshot = json_decode(file_get_contents(database_path('demo/public-articles.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['articles'] as $article) {
            $response = $this->getJson('/api/v1/articles/'.$article['slug'])->assertOk()
                ->assertJsonPath('data.author.name', $article['author']['name'])
                ->assertJsonPath('data.title', $article['title'])
                ->assertJsonPath('data.cover.url', '/storage/'.$article['cover']['path'])
                ->assertJsonPath('data.author.photoUrl', '/storage/'.$article['author']['photoPath']);
            $this->assertGreaterThan(2000, mb_strlen(strip_tags($response->json('data.body'))));
            $this->assertStringNotContainsString('selstorage.ru', $response->json('data.body'));
        }
        foreach ($snapshot['assets'] as $asset) {
            $this->assertSame($asset['sha256'], hash_file('sha256', database_path('demo-media/'.$asset['storagePath'])));
            $this->assertSame($asset['sha256'], hash('sha256', \Illuminate\Support\Facades\Storage::disk('public')->get($asset['storagePath'])));
        }
        $this->getJson('/api/v1/articles?'.http_build_query(['q' => 'Развитие сердца']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'razvitie-serdca');
        $this->getJson('/api/v1/articles?q=unknown-demo-text')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/articles/not-published')->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_http_mutations_are_disabled_before_business_logic(): void
    {
        $this->postJson('/api/v1/support-tickets', ['email' => 'demo@example.test'])
            ->assertForbidden();
        $this->postJson('/api/v1/payments/orders', [])->assertForbidden();
    }

    public function test_unfaked_outgoing_laravel_http_is_blocked(): void
    {
        $this->expectException(\Illuminate\Http\Client\StrayRequestException::class);
        Http::get('https://example.invalid/must-not-be-contacted');
    }
}
