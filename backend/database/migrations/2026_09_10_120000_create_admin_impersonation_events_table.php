<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_impersonation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('context_id')->nullable();
            $table->string('event', 32);
            $table->string('state', 32)->nullable();
            $table->string('reason', 120)->nullable();
            $table->string('return_path', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['actor_id', 'created_at', 'id']);
            $table->index(['subject_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonation_events');
    }
};
