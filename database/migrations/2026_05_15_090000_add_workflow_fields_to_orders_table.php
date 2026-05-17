<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('status');
            $table->string('payment_status')->nullable()->after('payment_method');
            $table->string('awb')->nullable()->after('shipping_estimate_days');
            $table->text('shipment_note')->nullable()->after('awb');
            $table->timestamp('paid_at')->nullable()->after('shipment_note');
            $table->timestamp('shipped_at')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_status',
                'awb',
                'shipment_note',
                'paid_at',
                'shipped_at',
            ]);
        });
    }
};
