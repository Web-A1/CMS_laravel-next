<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_mail_receipts', function (Blueprint $table): void {
            $table->string('intake_status', 24)->default('unclassified')->index();
        });
        Schema::table('support_mailbox_states', function (Blueprint $table): void {
            $table->timestamp('activation_window_started_at', 6)->nullable();
            $table->timestamp('activation_window_finished_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_mail_receipts', function (Blueprint $table): void {
            $table->dropColumn('intake_status');
        });
        Schema::table('support_mailbox_states', function (Blueprint $table): void {
            $table->dropColumn(['activation_window_started_at', 'activation_window_finished_at']);
        });
    }
};
