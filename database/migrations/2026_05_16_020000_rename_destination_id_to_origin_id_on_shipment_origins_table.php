<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_origins', function (Blueprint $table): void {
            $table->renameColumn('destination_id', 'origin_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_origins', function (Blueprint $table): void {
            $table->renameColumn('origin_id', 'destination_id');
        });
    }
};
