<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('admin_support_tickets', function (Blueprint $t): void { $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); $t->string('subject', 180); $t->text('description'); $t->string('status', 24)->default('open')->index(); $t->string('priority', 16)->default('normal'); $t->json('metadata')->nullable(); $t->timestamps(); $t->index(['status','created_at']); });
        Schema::create('admin_tasks', function (Blueprint $t): void { $t->id(); $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->string('title', 180); $t->text('description')->nullable(); $t->string('status', 24)->default('open')->index(); $t->string('priority', 16)->default('normal'); $t->timestamp('due_at')->nullable(); $t->json('metadata')->nullable(); $t->timestamps(); });
        Schema::create('admin_short_links', function (Blueprint $t): void { $t->id(); $t->string('slug', 80)->unique(); $t->string('target_path', 512); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->boolean('is_active')->default(true)->index(); $t->unsignedBigInteger('clicks')->default(0); $t->timestamps(); });
        Schema::create('admin_operation_events', function (Blueprint $t): void { $t->id(); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->string('operation', 32); $t->string('resource', 64); $t->unsignedBigInteger('resource_id')->nullable(); $t->string('idempotency_key', 128)->unique(); $t->json('payload')->nullable(); $t->timestamps(); $t->index(['resource', 'resource_id', 'created_at']); });
    }
    public function down(): void { Schema::dropIfExists('admin_operation_events'); Schema::dropIfExists('admin_short_links'); Schema::dropIfExists('admin_tasks'); Schema::dropIfExists('admin_support_tickets'); }
};
