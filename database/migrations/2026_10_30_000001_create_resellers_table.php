<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code')->unique();
            $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage');
            $table->unsignedInteger('commission_value')->default(0);
            $table->enum('status', ['pending', 'active', 'rejected', 'suspended'])->default('pending');
            $table->string('rejected_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
