<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\PromoCode;
use App\Models\PromoReservation;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\Bonuses\OrderDiscountService;
use App\Services\Bonuses\PromoOrderPricingService;
use App\Services\Bonuses\PromoReservationService;
use DomainException;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PromoDeletionFixtures;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class PromoOwnDeletionTest extends TestCase
{
    use PromoDeletionFixtures, SupportDatabase;

    public function test_only_current_creator_can_delete_and_tombstone_keeps_unique_hash_audit_and_no_ownership_forgery(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $other = User::factory()->create(['role' => 'polina']);
        $admin = User::factory()->create(['role' => 'admin_full']);
        $promo = $this->ownedPromo($owner);
        $foreign = $this->ownedPromo($other, 'FOREIGN');
        $automatic = PromoCode::create(['code_hash' => hash('sha256', 'AUTO'), 'status' => 'active', 'discount_kind' => 'fixed_minor',
            'discount_minor' => 100, 'currency' => 'RUB', 'expires_at' => now()->addDay(),
            'activation_limit' => 1, 'per_user_limit' => 1, 'rules_version' => 'auto-v1']);
        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'own-delete')->deleteJson('/api/v1/admin/promos/'.$foreign->id)->assertForbidden();
        $this->deleteJson('/api/v1/admin/promos/'.$automatic->id)->assertForbidden();
        $this->postJson('/api/v1/admin/promos', ['created_by' => $other->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/promos/'.$promo->id, ['created_by' => $owner->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/promos/'.$promo->id)->assertOk();
        $this->assertNull(PromoCode::find($promo->id));
        $this->assertNotNull(PromoCode::withTrashed()->findOrFail($promo->id)->deleted_at);
        $this->getJson('/api/v1/admin/promos')->assertOk()->assertJsonCount(2, 'data.data');
        $this->deleteJson('/api/v1/admin/promos/'.$promo->id)->assertConflict();
        $this->deleteJson('/api/v1/admin/promos/'.$foreign->id)->assertForbidden();
        $this->assertSame(1, AdminOperationEvent::where('operation', 'delete')->count());
        $owner->update(['role' => 'user_no_purchase']);
        $this->deleteJson('/api/v1/admin/promos/'.$promo->id)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/promos/'.$promo->id)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'forged-creator')->patchJson('/api/v1/admin/promos/'.$foreign->id, ['created_by' => $admin->id])->assertUnprocessable();
        $this->assertSame($other->id, $foreign->fresh()->created_by);
        try {
            app(AdminOperationsService::class)->promos($other, 'create', [
                'code' => 'OWN-PROMO', 'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 1500,
                'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 10, 'per_user_limit' => 2,
            ], key: 'duplicate-after-delete');
            $this->fail('Deleted codes cannot be reused.');
        } catch (QueryException) {
            $this->assertSame(3, PromoCode::withTrashed()->count());
        }
    }

    public function test_deleted_code_preserves_existing_priced_order_until_original_ttl_and_can_commit_or_release(): void
    {
        config(['bonuses.checkout_enabled' => true]);
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->create(['role' => 'polina']);
        $promo = $this->ownedPromo($owner);
        $order = $this->promoOrder();
        $order = app(PromoOrderPricingService::class)->apply($order->user_id, $order->id, 'OWN-PROMO', 'reserved');
        $reservation = PromoReservation::query()->sole();
        $proof = $reservation->getRawOriginal();
        $item = $order->items()->sole()->getRawOriginal();
        app(AdminOperationsService::class)->deleteOwnPromo($owner, $promo->id, key: 'delete-reserved');
        $this->assertSame($proof, $reservation->fresh()->getRawOriginal());
        $this->assertSame($item, $order->items()->sole()->getRawOriginal());
        $this->assertSame(8500, (int) $order->fresh()->amount_final);
        $newOrder = $this->promoOrder();
        foreach ([
            fn () => app(PromoReservationService::class)->quote($newOrder->user_id, $newOrder->id, 'OWN-PROMO'),
            fn () => app(PromoReservationService::class)->reserve($newOrder->user_id, $newOrder->id, 'OWN-PROMO', 'new-reserve'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Deleted promo cannot be applied to new orders.');
            } catch (DomainException $exception) {
                $this->assertSame('BONUS_PROMO_INVALID', $exception->getMessage());
            }
        }
        $replay = app(PromoOrderPricingService::class)->apply($order->user_id, $order->id, 'OWN-PROMO', 'reserved');
        $this->assertSame($order->amount_final, $replay->amount_final);
        $this->assertSame($proof, $reservation->fresh()->getRawOriginal());
        app(PromoReservationService::class)->assertReserved($order, $reservation->id);
        $this->assertSame('committed', app(PromoReservationService::class)->commit($order->user_id, $reservation->id)->status);
        $this->assertSame('promo', app(OrderDiscountService::class)->binding($order)['kind']);
        $this->assertSame($item, $order->items()->sole()->getRawOriginal());
        $this->assertDatabaseCount('promo_reservations', 1);
        $this->assertDatabaseCount('order_settlements', 0);

        $second = $this->ownedPromo($owner, 'RELEASE');
        $releaseOrder = $this->promoOrder();
        $release = app(PromoReservationService::class)->reserve($releaseOrder->user_id, $releaseOrder->id, 'RELEASE', 'release');
        app(AdminOperationsService::class)->deleteOwnPromo($owner, $second->id, key: 'delete-release');
        $this->assertSame('released', app(PromoReservationService::class)->release($releaseOrder->user_id, $release->id, 'cancelled')->status);
    }

    public function test_deleted_promo_does_not_extend_expired_reservation(): void
    {
        $owner = User::factory()->create(['role' => 'polina']);
        $promo = $this->ownedPromo($owner);
        $order = $this->promoOrder();
        $reservation = app(PromoReservationService::class)->reserve($order->user_id, $order->id, 'OWN-PROMO', 'expires');
        app(AdminOperationsService::class)->deleteOwnPromo($owner, $promo->id, key: 'delete-expires');
        $this->travelTo($reservation->expires_at);
        try {
            app(PromoReservationService::class)->commit($order->user_id, $reservation->id);
            $this->fail('Original expiry must still apply.');
        } catch (DomainException $exception) {
            $this->assertSame('BONUS_RESERVATION_EXPIRED', $exception->getMessage());
        }
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertSame('released', app(PromoReservationService::class)->release($order->user_id, $reservation->id, 'expired')->status);
    }
}
