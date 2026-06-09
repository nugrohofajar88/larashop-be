<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_origins', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->string('selected_courier')->default('jnt');
            $table->string('province');
            $table->string('city');
            $table->string('district');
            $table->string('subdistrict');
            $table->string('postal_code', 10);
            $table->text('address_line');
            $table->string('pin_point')->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_default')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_origins');
    }
};
