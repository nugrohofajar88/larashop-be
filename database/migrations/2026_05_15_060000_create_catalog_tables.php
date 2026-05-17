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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('short_description')->nullable();
            $table->text('description');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->string('weight_label')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->enum('public_status', ['draft', 'active', 'inactive', 'preorder'])->default('draft');
            $table->enum('catalog_status', ['available', 'limited', 'preorder', 'sold_out'])->default('available');
            $table->string('badge_label')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->json('highlights')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
