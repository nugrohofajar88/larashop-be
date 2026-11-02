<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            // nullOnDelete + snapshot (order_code, order_items_total, commission_type/rate) -
            // pola yang sama dengan order_items: riwayat komisi aman walau order dihapus.
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->string('order_code');
            $table->unsignedInteger('order_items_total')->default(0);
            $table->enum('commission_type', ['percentage', 'fixed']);
            $table->unsignedInteger('commission_rate')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_commissions');
    }
};
