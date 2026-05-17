<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_id')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn('destination_id');
        });
    }
};
