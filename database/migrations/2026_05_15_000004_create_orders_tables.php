<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'pending_payment', 'paid', 'processing', 'shipped', 'completed', 'cancelled'])->default('draft');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedBigInteger('items_total')->default(0);
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('shipping_cashback')->default(0);
            $table->unsignedBigInteger('unique_code')->default(0);
            $table->unsignedBigInteger('used_unique_code')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->string('shipping_service_name')->nullable();
            $table->string('shipping_courier_code', 30)->nullable();
            $table->string('shipping_service_code', 30)->nullable();
            $table->string('shipping_estimate_days')->nullable();
            $table->string('awb')->nullable();
            $table->string('komerce_order_no')->nullable();
            $table->unsignedBigInteger('komerce_order_id')->nullable();
            $table->text('shipment_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('address_label')->nullable();
            $table->text('address_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->string('variant_label')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('subtotal');
            $table->boolean('is_selected')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
