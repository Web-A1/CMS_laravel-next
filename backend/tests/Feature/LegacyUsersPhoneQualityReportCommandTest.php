<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LegacyUsersPhoneQualityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_quality_report_from_database_source_as_json(): void
    {
        $reportPath = storage_path('app/legacy-phone-quality-report-test.json');
        if (File::exists($reportPath)) {
            File::delete($reportPath);
        }

        User::factory()->create(['phone' => null]);
        User::factory()->create(['phone' => '+1000']);
        User::factory()->create(['phone' => '8 (999) 123-45-67']);
        User::factory()->create(['phone' => '+79990001122']);

        $this->artisan("legacy:users:phone-quality --source=db --format=json --output={$reportPath}")
            ->assertSuccessful();

        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(4, $report['total']);
        $this->assertSame(1, $report['phone_missing']);
        $this->assertSame(1, $report['phone_invalid']);
        $this->assertSame(2, $report['phone_valid']);
        $this->assertSame(50, (int) $report['valid_percent']);

        File::delete($reportPath);
    }
}
