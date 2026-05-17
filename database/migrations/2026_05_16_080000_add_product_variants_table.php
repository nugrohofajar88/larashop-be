<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('length_cm', 8, 2)->nullable()->after('weight_grams');
            $table->decimal('width_cm', 8, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('label');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        $products = DB::table('products')->get();

        foreach ($products as $product) {
            DB::table('product_variants')->insert([
                'product_id' => $product->id,
                'sku' => Str::limit(strtoupper((string) $product->sku).'-1', 100, ''),
                'label' => trim((string) ($product->weight_label ?: 'Default')),
                'price' => $product->price,
                'compare_at_price' => $product->compare_at_price,
                'stock' => $product->stock,
                'weight_grams' => $product->weight_grams,
                'length_cm' => null,
                'width_cm' => null,
                'height_cm' => null,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });
    }
};
