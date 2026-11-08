<?php

namespace Tests\Feature;

use App\Jobs\GenerateProtectedFileDeliveryJob;
use App\Models\Guide;
use App\Models\LessonMaterialFile;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\SellableEntity;
use App\Models\Subject;
use App\Models\User;
use App\Services\EntitlementGrantService;
use App\Services\OrderSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuidePurchaseSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_guide_sku_order_settlement_reuses_existing_delivery_pipeline(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $guide = Guide::factory()->create(['is_active' => true]);
        $material = $guide->materials()->create(['kind' => 'pdf', 'title' => 'PDF', 'is_published' => true]);
        LessonMaterialFile::query()->create(['lesson_material_id' => $material->id, 'source_object_key' => 'protected-sources/guide.pdf', 'source_sha256' => str_repeat('a', 64), 'source_size_bytes' => 1024, 'source_mime_type' => 'application/pdf', 'download_filename' => 'guide.pdf']);
        $offer = $this->offer('guide', $guide->id, 'legacy-product-318', [['kind' => 'guide', 'id' => $guide->id]]);
        $this->getJson('/api/v1/catalog/sellables')->assertOk()->assertJsonPath('data.0.sku', $offer->sku);
        Sanctum::actingAs($user);
        $order = $this->checkout($offer);
        $url = '/api/v1/learning/guides/'.$guide->slug.'/file-deliveries';
        $this->postJson($url, ['materialId' => $material->id])->assertForbidden();
        $this->getJson('/api/v1/learning/guides')->assertExactJson(['data' => []]);
        $order->update(['payment_status' => 'pending_payment']);
        $this->settle($order);
        $this->getJson('/api/v1/payments/orders/'.$order->id)->assertOk()->assertJsonPath('items.0.sellable_sku', 'legacy-product-318')->assertJsonPath('items.0.sellable_kind', 'guide')->assertJsonPath('items.0.purchased_entity_type', 'guide')->assertJsonPath('items.0.purchased_entity_id', $guide->id)->assertJsonPath('items.0.access_status', 'granted');
        $this->getJson('/api/v1/learning/guides')->assertJsonPath('data.0.id', $guide->id);
        $this->postJson($url, ['materialId' => $material->id])->assertAccepted();
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_mixed_one_price_snapshot_freezes_version_and_refund_preserves_independent_guide(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        Sanctum::actingAs($user);
        $guide = Guide::factory()->create(['is_active' => true]);
        $subject = Subject::query()->create(['slug' => 'subject', 'title' => 'Subject']);
        $course = $subject->courses()->create(['slug' => 'course', 'title' => 'Course', 'price_cents' => 100]);
        $lesson = $course->lessons()->create(['slug' => 'old', 'title' => 'Old']);
        $newLesson = $course->lessons()->create(['slug' => 'new', 'title' => 'New']);
        $atoms = [['kind' => 'guide', 'id' => $guide->id], ['kind' => 'lesson', 'id' => $lesson->id]];
        $offer = $this->offer('box', null, 'legacy-product-183', $atoms);
        $order = $this->checkout($offer);
        $item = $order->items()->sole();
        $snapshot = $item->grant_snapshot;
        $this->assertSame($atoms, $snapshot['atoms']);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(10000, $item->final_amount);
        $offer->grantRules()->create(['atom_kind' => 'guide', 'atom_id' => $guide->id, 'grant_snapshot_version' => 2, 'sort_order' => 0]);
        $offer->grantRules()->create(['atom_kind' => 'lesson', 'atom_id' => $newLesson->id, 'grant_snapshot_version' => 2, 'sort_order' => 1]);
        $order->update(['payment_status' => 'pending_payment']);
        $this->settle($order);
        $this->settle($order->fresh());
        $this->assertDatabaseCount('user_entitlement_grants', 2);
        $this->assertSame($snapshot, $item->fresh()->grant_snapshot);
        $this->assertDatabaseMissing('user_entitlement_grants', ['atom_kind' => 'lesson', 'atom_id' => $newLesson->id]);
        $independent = $this->checkout($this->offer('guide', $guide->id, 'legacy-product-283', [['kind' => 'guide', 'id' => $guide->id]]));
        $independent->update(['payment_status' => 'pending_payment']);
        $this->settle($independent);
        app(EntitlementGrantService::class)->revokeOrderGrants($user->id, $order->id, [$item->id], 'refund fixture');
        $this->getJson('/api/v1/learning/guides')->assertJsonPath('data.0.id', $guide->id);
        $this->assertDatabaseHas('user_entitlement_grants', ['order_id' => $independent->id, 'atom_kind' => 'guide', 'status' => 'active']);
        $this->assertDatabaseHas('user_entitlement_grants', ['order_id' => $order->id, 'atom_kind' => 'lesson', 'status' => 'revoked']);
    }

    private function checkout(SellableEntity $offer): Order
    {
        $id = $this->postJson('/api/v1/payments/orders', ['sellable_sku' => $offer->sku])->assertCreated()->json('order_id');

        return Order::query()->findOrFail($id);
    }

    private function settle(Order $order): void
    {
        app(OrderSettlementService::class)->confirmPaid($order, ['idempotency_key' => 'guide-order-'.$order->id, 'amount' => 10000, 'currency' => 'RUB', 'settlement_type' => OrderSettlement::TYPE_BACKEND_CONFIRMATION]);
    }

    private function offer(string $kind, ?int $id, string $sku, array $atoms): SellableEntity
    {
        $offer = SellableEntity::query()->create(['sellable_kind' => $kind, 'entity_type' => $kind, 'entity_id' => $id, 'sku' => $sku, 'title' => 'Offer', 'currency' => 'RUB', 'price_cents' => 10000, 'price_version' => 1, 'status' => 'active']);
        foreach ($atoms as $index => $atom) {
            $offer->grantRules()->create(['atom_kind' => $atom['kind'], 'atom_id' => $atom['id'], 'grant_snapshot_version' => 1, 'sort_order' => $index]);
        }

        return $offer;
    }
}
