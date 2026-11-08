<?php

namespace Tests\Feature;

use App\Models\Guide;
use App\Models\Order;
use App\Models\SellableEntity;
use App\Models\Subject;
use App\Models\User;
use App\Services\OrderItemSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellableTariffSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_staff_read_frozen_terms_after_catalog_edits_and_old_terms_stay_null(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $subject = Subject::query()->create(['slug' => 'anatomy', 'title' => 'Anatomy']);
        $course = $subject->courses()->create(['slug' => 'miologiya', 'title' => 'Miologiya', 'price_cents' => 459000]);
        $lessons = [];
        foreach ([50, 51, 52, 54, 55, 266] as $page) {
            $lessons[] = $course->lessons()->create(['slug' => 'page-'.$page, 'title' => 'Lesson '.$page]);
        }
        $tariff = ['code' => 'ordinary', 'label' => 'Обычный', 'supportDescription' => 'Обычный объём личной работы преподавателя.'];
        $offer = SellableEntity::query()->create(['sellable_kind' => 'course', 'entity_type' => 'course', 'entity_id' => $course->id, 'sku' => 'legacy-product-211', 'title' => 'Frozen offer', 'currency' => 'RUB', 'price_cents' => 459000, 'price_version' => 1, 'status' => 'active', 'metadata' => ['tariff' => $tariff]]);
        foreach ($lessons as $index => $lesson) {
            $offer->grantRules()->create(['atom_kind' => 'lesson', 'atom_id' => $lesson->id, 'grant_snapshot_version' => 1, 'sort_order' => $index]);
        }
        Sanctum::actingAs($user);
        $orderId = $this->postJson('/api/v1/payments/orders', ['sellable_sku' => $offer->sku])->assertCreated()->json('order_id');
        $item = Order::query()->findOrFail($orderId)->items()->sole();
        $hash = $item->snapshot_hash;
        $this->assertCount(6, $item->grant_snapshot['atoms']);
        $this->assertSame(['version' => 1, ...$tariff], $item->catalog_snapshot['tariff']);
        $offer->update(['price_cents' => 859000, 'metadata' => ['tariff' => ['code' => 'extended', 'label' => 'Расширенный', 'supportDescription' => 'Новые условия']]]);
        $this->getJson('/api/v1/payments/orders/'.$orderId)->assertOk()->assertJsonPath('items.0.tariff', $tariff)->assertJsonPath('items.0.sellable_sku', $offer->sku)->assertJsonPath('items.0.final_amount', 459000);
        $this->assertSame($hash, $item->fresh()->snapshot_hash);
        $this->assertSame($hash, OrderItemSnapshotService::snapshotHash($item->fresh()->toArray()));
        $tampered = $item->fresh()->toArray();
        $tampered['catalog_snapshot']['tariff']['supportDescription'] = 'Altered terms';
        $this->assertNotSame($hash, OrderItemSnapshotService::snapshotHash($tampered));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->getJson('/api/v1/admin/users/'.$user->id.'/orders')->assertOk()->assertJsonPath('data.0.items.0.tariff', $tariff)->assertJsonMissingPath('data.0.items.0.catalog_snapshot');
        $snapshot = $item->catalog_snapshot;
        unset($snapshot['tariff']);
        $item->update(['catalog_snapshot' => $snapshot]);
        $this->getJson('/api/v1/admin/users/'.$user->id.'/orders')->assertOk()->assertJsonPath('data.0.items.0.tariff', null);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/payments/orders/'.$orderId)->assertOk()->assertJsonPath('items.0.tariff', null);
    }

    public function test_malformed_current_tariff_cannot_create_order(): void
    {
        $guide = Guide::factory()->create();
        $offer = SellableEntity::query()->create(['sellable_kind' => 'guide', 'entity_type' => 'guide', 'entity_id' => $guide->id, 'sku' => 'invalid-tariff', 'title' => 'Offer', 'currency' => 'RUB', 'price_cents' => 100, 'price_version' => 1, 'status' => 'active', 'metadata' => ['tariff' => ['code' => 'premium']]]);
        $offer->grantRules()->create(['atom_kind' => 'guide', 'atom_id' => $guide->id, 'grant_snapshot_version' => 1, 'sort_order' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'user_no_purchase']));
        $this->postJson('/api/v1/payments/orders', ['sellable_sku' => $offer->sku])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }
}
