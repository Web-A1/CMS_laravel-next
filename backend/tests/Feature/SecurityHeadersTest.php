<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_added_to_web_responses(): void
    {
        $this->assertSecurityHeaders($this->getJson('/health')->assertOk());
    }

    public function test_security_headers_are_added_to_api_error_responses(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/v1/auth/me')->assertUnauthorized());
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'")
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
