<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('privacy_policy_version', 80);
            $table->string('offer_version', 80);
            $table->timestamp('privacy_policy_accepted_at');
            $table->timestamp('offer_accepted_at');
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_accepted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_consents');
    }
};
