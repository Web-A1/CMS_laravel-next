<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('sellable_kind', 32);
            $table->unsignedBigInteger('sellable_id');
            $table->string('sellable_sku')->nullable();
            $table->string('purchased_entity_type', 32);
            $table->unsignedBigInteger('purchased_entity_id');
            $table->string('purchased_entity_slug')->nullable();
            $table->string('display_title');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('currency', 3)->default('RUB');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('final_amount');
            $table->unsignedBigInteger('price_id')->nullable();
            $table->unsignedInteger('price_version')->nullable();
            $table->char('snapshot_hash', 64);
            $table->json('catalog_snapshot')->nullable();
            $table->json('price_snapshot')->nullable();
            $table->json('benefit_snapshot')->nullable();
            $table->json('grant_snapshot');
            $table->string('access_status', 32)->default('not_granted');
            $table->timestamps();

            $table->index(['order_id', 'access_status']);
            $table->index(['sellable_kind', 'sellable_id']);
            $table->index(['purchased_entity_type', 'purchased_entity_id'], 'order_items_purchased_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
