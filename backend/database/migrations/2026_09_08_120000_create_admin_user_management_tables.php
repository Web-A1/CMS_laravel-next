<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->date('birth_date')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('school')->nullable();
            $table->string('study_year', 80)->nullable();
            $table->unsignedSmallInteger('enrollment_year')->nullable();
            $table->string('study_direction')->nullable();
            $table->string('profession')->nullable();
            $table->text('interests')->nullable();
            $table->string('referral_source')->nullable();
            $table->string('preferred_contact', 80)->nullable();
            $table->string('contact_details')->nullable();
            $table->string('vk_profile')->nullable();
            $table->timestamps();
        });
        Schema::create('user_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });
        Schema::create('user_tag', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'user_tag_id']);
        });
        Schema::create('admin_user_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('type', 40);
            $table->text('comment')->nullable();
            $table->json('changes')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at', 'id']);
            $table->unique(['user_id', 'actor_id', 'idempotency_key'], 'admin_user_event_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_events');
        Schema::dropIfExists('user_tag');
        Schema::dropIfExists('user_tags');
        Schema::dropIfExists('user_profiles');
    }
};
