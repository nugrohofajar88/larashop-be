<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_qris', function (Blueprint $table): void {
            $table->id();
            $table->string('qris_id')->unique();      // ID dari QRISLY (int sandbox / uuid produksi)
            $table->string('name', 100);              // nama/label QRIS
            $table->string('merchant_name')->nullable();
            $table->string('provider', 50)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_qris');
    }
};
