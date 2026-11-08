<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_operation_events', function (Blueprint $t): void {
            $t->foreignId('actor_id')->nullable()->change();
        });
        Schema::table('admin_support_tickets', function (Blueprint $t): void {
            $t->string('contact_email')->nullable();
            $t->string('public_number', 40)->nullable()->unique();
            $t->string('source', 16)->default('admin');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('last_message_at')->nullable();
            $t->string('submission_key', 128)->nullable()->unique();
            $t->string('submission_hash', 64)->nullable();
            $t->json('submission_result')->nullable();
            $t->index(['status', 'last_message_at']);
        });
        Schema::create('support_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ticket_id')->constrained('admin_support_tickets')->restrictOnDelete();
            $t->string('direction', 20);
            $t->text('body');
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('sender')->nullable();
            $t->json('warnings')->nullable();
            $t->json('headers')->nullable();
            $t->string('logical_key', 180)->nullable()->unique();
            $t->string('payload_hash', 64)->nullable();
            $t->string('message_id')->nullable()->unique();
            $t->string('delivery_status', 24)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->unsignedInteger('ticket_revision')->nullable();
            $t->boolean('notify_failures')->default(true);
            $t->timestamps();
            $t->index(['ticket_id', 'id']);
            $t->index(['delivery_status', 'next_attempt_at']);
        });
        Schema::create('support_attachments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('message_id')->constrained('support_messages')->restrictOnDelete();
            $t->string('status', 16)->default('pending')->index();
            $t->string('disk', 64);
            $t->string('source_key')->nullable();
            $t->string('preview_key')->nullable();
            $t->string('thumbnail_key')->nullable();
            $t->string('mime', 64);
            $t->unsignedBigInteger('bytes');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('support_mail_receipts', function (Blueprint $t): void {
            $t->id();
            $t->string('mailbox', 160);
            $t->string('folder', 160);
            $t->unsignedBigInteger('uidvalidity');
            $t->unsignedBigInteger('uid');
            $t->string('message_id')->nullable()->index();
            $t->string('fingerprint', 64)->nullable()->index();
            $t->string('identity_hash', 64)->nullable()->unique();
            $t->string('disk', 64)->nullable();
            $t->string('original_key')->nullable();
            $t->string('outcome', 32)->default('pending')->index();
            $t->string('reason', 64)->nullable();
            $t->foreignId('support_message_id')->nullable()->constrained('support_messages')->restrictOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->unique(['mailbox', 'folder', 'uidvalidity', 'uid'], 'support_receipt_uid_unique');
        });
        Schema::create('support_mailbox_states', function (Blueprint $t): void {
            $t->id();
            $t->string('mailbox', 160);
            $t->string('folder', 160);
            $t->string('status', 32)->default('inactive');
            $t->timestamp('activated_at')->nullable();
            $t->unsignedBigInteger('baseline_uid')->nullable();
            $t->unsignedBigInteger('baseline_uidvalidity')->nullable();
            $t->unsignedBigInteger('uidvalidity')->nullable();
            $t->unsignedBigInteger('cursor')->default(0);
            $t->unsignedBigInteger('recovery_epoch')->nullable();
            $t->unsignedBigInteger('recovery_cursor')->default(0);
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->timestamps();
            $t->unique(['mailbox', 'folder']);
        });
        DB::table('admin_support_tickets')->orderBy('id')->each(function ($ticket): void {
            DB::table('admin_support_tickets')->where('id', $ticket->id)->update([
                'public_number' => 'SUP-'.Str::upper(Str::random(20)),
                'status' => match ($ticket->status) {
                    'open' => 'new', 'pending' => 'waiting', default => 'closed'
                },
                'last_message_at' => $ticket->created_at,
            ]);
            DB::table('support_messages')->insertOrIgnore([
                'ticket_id' => $ticket->id, 'direction' => 'inbound', 'body' => $ticket->description,
                'logical_key' => 'backfill:'.$ticket->id, 'created_at' => $ticket->created_at, 'updated_at' => $ticket->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_mailbox_states');
        Schema::dropIfExists('support_mail_receipts');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::table('admin_support_tickets', function (Blueprint $t): void {
            $t->dropIndex(['status', 'last_message_at']);
            $t->dropColumn(['contact_email', 'public_number', 'source', 'revision', 'last_message_at', 'submission_key', 'submission_hash', 'submission_result']);
        });
    }
};
