<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_unique_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->enum('type', ['paid', 'used']);
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['ref_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_unique_codes');
    }
};
