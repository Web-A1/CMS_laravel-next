<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleReadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('cache.default'));
    }

    public function test_exhausting_public_author_reads_does_not_block_the_first_auth_requests(): void
    {
        for ($request = 0; $request < 120; $request++) {
            $this->getJson('/api/v1/content-authors?placement=home')->assertOk();
        }

        $this->getJson('/api/v1/content-authors?placement=home')->assertTooManyRequests();
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/forgot', [])->assertUnprocessable();
        $this->getJson('/api/v1/articles')->assertOk();
    }

    public function test_auth_throttling_does_not_consume_the_public_author_budget(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [])->assertTooManyRequests();
        $this->getJson('/api/v1/content-authors?placement=home')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '119');
    }

    public function test_public_author_limit_still_expires_and_separates_anonymous_ips(): void
    {
        for ($request = 0; $request < 120; $request++) {
            $this->getJson('/api/v1/content-authors?placement=home')->assertOk();
        }

        $this->getJson('/api/v1/content-authors?placement=home')->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson('/api/v1/content-authors?placement=home')
            ->assertOk();

        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/v1/content-authors?placement=home')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '119');
    }
}
