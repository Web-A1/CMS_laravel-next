<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_registration_verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('verification_id')->unique();
            $table->string('channel', 16); // email|phone
            $table->string('target', 255); // normalized email or phone
            $table->string('code_hash')->nullable(); // email code only
            $table->unsignedTinyInteger('attempts_left')->default(5);
            $table->string('status', 20)->default('pending'); // pending|confirmed|failed
            $table->string('provider_event_id', 120)->nullable()->unique();
            $table->json('provider_payload')->nullable();
            $table->string('verification_token_hash')->nullable();
            $table->timestamp('verification_token_expires_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'target']);
            $table->index(['channel', 'target', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_registration_verifications');
    }
};
