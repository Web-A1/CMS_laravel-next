<?php

namespace Tests\Unit;

use App\Services\LegacyMigrationDenylist;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyMigrationDenylistTest extends TestCase
{
    public function test_allows_safe_catalog_sources_and_routes(): void
    {
        $denylist = new LegacyMigrationDenylist;

        $this->assertTrue($denylist->isAllowedSource('products', ['id', 'title', 'price']));
        $this->assertTrue($denylist->isAllowedSource('course_pages', ['id', 'slug', 'body']));
        $this->assertTrue($denylist->isAllowedRouteMapping('/platform/course/anatomy/lesson-1'));
        $this->assertTrue($denylist->isAllowedRouteMapping('https://christmedschool.com/about'));
    }

    public function test_blocks_sensitive_legacy_tables(): void
    {
        $denylist = new LegacyMigrationDenylist;

        $this->assertFalse($denylist->isAllowedSource('member_sessions', ['id', 'member_id']));
        $this->assertFalse($denylist->isAllowedSource('payment_tokens', ['id', 'provider']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy source is denied');

        $denylist->assertAllowedSource('cms_password_resets', ['email']);
    }

    public function test_blocks_sensitive_legacy_fields(): void
    {
        $denylist = new LegacyMigrationDenylist;

        $this->assertFalse($denylist->isAllowedSource('members', ['id', 'password_hash']));
        $this->assertFalse($denylist->isAllowedSource('materials', ['id', 'file_hash']));
        $this->assertFalse($denylist->isAllowedSource('members', ['id', 'remember_token']));
    }

    public function test_blocks_side_effect_routes(): void
    {
        $denylist = new LegacyMigrationDenylist;

        $this->assertFalse($denylist->isAllowedRouteMapping('/api/payment/webhook'));
        $this->assertFalse($denylist->isAllowedRouteMapping('/master/orders/delete/123'));
        $this->assertFalse($denylist->isAllowedRouteMapping('https://christmedschool.com/payment/callback?order=1'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy route is denied');

        $denylist->assertAllowedRouteMapping('/lk/profile/change-password');
    }
}
