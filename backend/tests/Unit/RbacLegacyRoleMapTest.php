<?php

namespace Tests\Unit;

use App\Support\Rbac;
use Tests\TestCase;

class RbacLegacyRoleMapTest extends TestCase
{
    public function test_maps_known_legacy_roles_to_target_roles(): void
    {
        $this->assertSame('admin_full', Rbac::mapLegacyRole('admin'));
        $this->assertSame('admin_full', Rbac::mapLegacyRole('SuperAdmin'));
        $this->assertSame('admin_limited', Rbac::mapLegacyRole('marketolog'));
        $this->assertSame('admin_limited', Rbac::mapLegacyRole('operator'));
        $this->assertSame('manager', Rbac::mapLegacyRole('manager'));
        $this->assertSame('manager', Rbac::mapLegacyRole('manager-head'));
        $this->assertSame('teacher', Rbac::mapLegacyRole('curator'));
        $this->assertSame('user_with_purchase', Rbac::mapLegacyRole('buyer'));
        $this->assertSame('user_no_purchase', Rbac::mapLegacyRole('user'));
    }

    public function test_falls_back_to_user_no_purchase_for_unknown_or_empty_role(): void
    {
        $this->assertSame('user_no_purchase', Rbac::mapLegacyRole('unknown_legacy_role'));
        $this->assertSame('user_no_purchase', Rbac::mapLegacyRole(''));
        $this->assertSame('user_no_purchase', Rbac::mapLegacyRole(null));
    }
}
