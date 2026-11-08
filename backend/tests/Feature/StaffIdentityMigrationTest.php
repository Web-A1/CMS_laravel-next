<?php

namespace Tests\Feature;

use Tests\Concerns\StaffIdentitySchema;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class StaffIdentityMigrationTest extends TestCase
{
    use StaffIdentitySchema, SupportDatabase;
}
