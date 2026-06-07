<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 30);
            $table->string('direction', 3); // in / out
            $table->string('name')->nullable();
            $table->text('message')->nullable();
            $table->string('type', 30)->default('text');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['phone', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
    }
};
