<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom harga/stok/berat/dimensi di products adalah cermin (denormalisasi) dari
     * ProductVariant. Dihapus supaya tidak membingungkan — sumber kebenaran = varian.
     * products.price/stock/dll kini disediakan via accessor turunan di model Product.
     */
    private array $columns = [
        'price', 'compare_at_price', 'weight_label', 'weight_grams',
        'length_cm', 'width_cm', 'height_cm', 'stock',
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'price')) {
                $table->unsignedBigInteger('price')->default(0);
            }
            if (! Schema::hasColumn('products', 'compare_at_price')) {
                $table->unsignedBigInteger('compare_at_price')->nullable();
            }
            if (! Schema::hasColumn('products', 'weight_label')) {
                $table->string('weight_label')->nullable();
            }
            if (! Schema::hasColumn('products', 'weight_grams')) {
                $table->unsignedInteger('weight_grams')->nullable();
            }
            if (! Schema::hasColumn('products', 'length_cm')) {
                $table->decimal('length_cm', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('products', 'width_cm')) {
                $table->decimal('width_cm', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('products', 'height_cm')) {
                $table->decimal('height_cm', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('products', 'stock')) {
                $table->unsignedInteger('stock')->default(0);
            }
        });
    }
};
