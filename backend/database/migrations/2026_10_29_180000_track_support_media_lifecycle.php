<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_media_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('logical_key', 180)->unique();
            $table->string('payload_hash', 64);
            $table->unsignedSmallInteger('protocol')->default(1);
            $table->string('kind', 24);
            $table->foreignId('ticket_id')->nullable()->constrained('admin_support_tickets')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('support_messages')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('support_attachments')->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('support_mail_receipts')->restrictOnDelete();
            $table->json('storage_identity');
            $table->json('files');
            $table->string('work_dir');
            $table->timestamp('associated_at')->nullable();
            $table->unsignedBigInteger('deletion_id')->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('support_media_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('admin_support_tickets')->restrictOnDelete();
            $table->unsignedInteger('closing_revision');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->json('snapshot');
            $table->json('manifest');
            $table->string('coverage', 24)->default('legacy_unknown');
            $table->string('status', 16)->default('pending')->index();
            $table->json('inventory_evidence')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('admin_support_tickets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('media_protocol')->nullable();
            $table->uuid('birth_reservation_id')->nullable();
        });
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('media_deletion_id')->nullable()->index();
        });
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->uuid('media_reservation_id')->nullable()->index();
            $table->unsignedBigInteger('media_deletion_id')->nullable()->index();
        });
        Schema::table('support_mail_receipts', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->nullable()->constrained('admin_support_tickets')->restrictOnDelete();
            $table->uuid('media_reservation_id')->nullable()->index();
            $table->unsignedBigInteger('media_deletion_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('support_mail_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('ticket_id'));
        foreach (['support_mail_receipts', 'support_attachments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['media_reservation_id']);
                $table->dropIndex(['media_deletion_id']);
                $table->dropColumn(['media_reservation_id', 'media_deletion_id']);
            });
        }
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->dropIndex(['media_deletion_id']);
            $table->dropColumn('media_deletion_id');
        });
        Schema::table('admin_support_tickets', fn (Blueprint $table) => $table->dropColumn(['media_protocol', 'birth_reservation_id']));
        Schema::dropIfExists('support_media_deletions');
        Schema::dropIfExists('support_media_reservations');
    }
};
