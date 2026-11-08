<?php

namespace Tests\Feature;

use App\Models\Guide;
use App\Models\SellableEntity;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSellableApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_guide_and_mixed_catalog_projects_only_safe_confirmed_destinations(): void
    {
        $guide = Guide::factory()->create(['is_active' => true]);
        $subject = Subject::query()->create(['slug' => 'anatomy', 'title' => 'Anatomy']);
        $course = $subject->courses()->create(['slug' => 'bones', 'title' => 'Bones', 'price_cents' => 100]);
        $lesson = $course->lessons()->create(['slug' => 'lesson', 'title' => 'Lesson']);
        $this->sellable('guide', $guide->id, 'legacy-product-318', [['kind' => 'guide', 'id' => $guide->id]]);
        $box = $this->sellable('box', null, 'legacy-product-183', [['kind' => 'guide', 'id' => $guide->id], ['kind' => 'lesson', 'id' => $lesson->id]]);
        $response = $this->getJson('/api/v1/catalog/sellables')->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.accessPath', '/lk/guides/'.$guide->slug)->assertJsonPath('data.0.tariff', null)
            ->assertJsonPath('data.1.entityId', $box->id)->assertJsonPath('data.1.price', 10000)
            ->assertJsonPath('data.1.atoms.1.accessPath', '/lk/learning/anatomy/bones/lesson');
        $this->assertSame(['sku', 'kind', 'entityType', 'entityId', 'title', 'price', 'currency', 'priceVersion', 'publicPath', 'accessPath', 'tariff', 'atoms'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('never-project', $response->getContent());
    }

    public function test_inactive_missing_invalid_source_mapping_and_malformed_terms_are_not_offers(): void
    {
        $guide = Guide::factory()->create(['is_active' => true]);
        $offer = $this->sellable('guide', $guide->id, 'legacy-product-318', [['kind' => 'guide', 'id' => $guide->id]]);
        $offer->update(['metadata' => ['tariff' => ['code' => 'unknown']]]);
        $this->getJson('/api/v1/catalog/sellables')->assertExactJson(['data' => []]);
        $offer->update(['metadata' => []]);
        $guide->update(['is_active' => false]);
        $this->getJson('/api/v1/catalog/sellables')->assertExactJson(['data' => []]);
        $guide->update(['is_active' => true]);
        $offer->grantRules()->update(['atom_id' => 999999]);
        $this->getJson('/api/v1/catalog/sellables')->assertExactJson(['data' => []]);
        $offer->update(['status' => 'draft']);
        $this->getJson('/api/v1/catalog/sellables')->assertExactJson(['data' => []]);
    }

    public function test_tariff_offers_keep_equal_atoms_and_distinct_terms_and_prices(): void
    {
        $subject = Subject::query()->create(['slug' => 'anatomy', 'title' => 'Anatomy']);
        $course = $subject->courses()->create(['slug' => 'bones', 'title' => 'Bones', 'price_cents' => 100]);
        $lesson = $course->lessons()->create(['slug' => 'lesson', 'title' => 'Lesson']);
        foreach (['ordinary' => [211, 459000, 'Обычный'], 'extended' => [213, 859000, 'Расширенный']] as $code => [$id, $price, $label]) {
            $offer = $this->sellable('course', $course->id, 'legacy-product-'.$id, [['kind' => 'lesson', 'id' => $lesson->id]]);
            $offer->update(['price_cents' => $price, 'metadata' => ['tariff' => ['code' => $code, 'label' => $label, 'supportDescription' => $label.' объём личной работы преподавателя.']]]);
        }
        $response = $this->getJson('/api/v1/catalog/sellables')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price', 459000)->assertJsonPath('data.1.price', 859000)
            ->assertJsonPath('data.0.tariff.code', 'ordinary')->assertJsonPath('data.1.tariff.code', 'extended');
        $this->assertSame($response->json('data.0.atoms'), $response->json('data.1.atoms'));
    }

    private function sellable(string $kind, ?int $id, string $sku, array $atoms): SellableEntity
    {
        $offer = SellableEntity::query()->create(['sellable_kind' => $kind, 'entity_type' => $kind, 'entity_id' => $id, 'sku' => $sku, 'title' => 'Offer', 'currency' => 'RUB', 'price_cents' => 10000, 'price_version' => 1, 'status' => 'active', 'metadata' => ['secret' => 'never-project']]);
        foreach ($atoms as $index => $atom) {
            $offer->grantRules()->create(['atom_kind' => $atom['kind'], 'atom_id' => $atom['id'], 'grant_snapshot_version' => 1, 'sort_order' => $index]);
        }

        return $offer;
    }
}
