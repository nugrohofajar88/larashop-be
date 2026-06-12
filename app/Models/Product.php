<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'sku',
        'slug',
        'name',
        'short_description',
        'description',
        'public_status',
        'catalog_status',
        'badge_label',
        'sold_count',
        'highlights',
        'is_featured',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'sold_count' => 'integer',
            'highlights' => 'array',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Varian default (acuan harga/berat/dimensi untuk tampilan ringkas produk).
     * Pakai relasi yang sudah di-load bila ada supaya tidak N+1.
     */
    public function defaultVariant(): ?ProductVariant
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();

        return $variants->firstWhere('is_default', true) ?? $variants->first();
    }

    // ---- Atribut turunan dari VARIAN (kolom product price/stock/berat/dimensi sudah
    // dihapus; sumber kebenaran = ProductVariant). Reads $product->price/->stock/dll
    // tetap berfungsi lewat accessor ini.

    public function getPriceAttribute(): int
    {
        return (int) ($this->defaultVariant()?->price ?? 0);
    }

    public function getCompareAtPriceAttribute(): ?int
    {
        $value = $this->defaultVariant()?->compare_at_price;

        return $value !== null ? (int) $value : null;
    }

    public function getWeightLabelAttribute(): ?string
    {
        return $this->defaultVariant()?->label;
    }

    public function getWeightGramsAttribute(): ?int
    {
        $value = $this->defaultVariant()?->weight_grams;

        return $value !== null ? (int) $value : null;
    }

    public function getLengthCmAttribute()
    {
        return $this->defaultVariant()?->length_cm;
    }

    public function getWidthCmAttribute()
    {
        return $this->defaultVariant()?->width_cm;
    }

    public function getHeightCmAttribute()
    {
        return $this->defaultVariant()?->height_cm;
    }

    /** Stok produk = jumlah stok seluruh varian aktif (live, bukan kolom). */
    public function getStockAttribute(): int
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();

        return (int) $variants->where('is_active', true)->sum('stock');
    }

    /**
     * Produk "tersedia" = punya minimal satu varian aktif yang masih ada stok.
     * Dipakai untuk filter katalog (basis ketersediaan = varian, bukan kolom product).
     */
    public function scopeAvailable($query)
    {
        return $query->whereHas('variants', fn ($q) => $q->where('is_active', true)->where('stock', '>', 0));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderByDesc('is_primary');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Filter produk yang cocok dengan kumpulan kata kunci.
     * Tiap kata dicocokkan (LIKE) ke nama, deskripsi singkat, deskripsi, atau nama kategori.
     *
     * @param  array<int, string>  $terms
     * @param  bool  $matchAny  false = semua kata harus cocok (AND); true = salah satu (OR).
     */
    public function scopeWhereSearchTerms($query, array $terms, bool $matchAny = false)
    {
        if ($terms === []) {
            return $query;
        }

        return $query->where(function ($outer) use ($terms, $matchAny): void {
            foreach ($terms as $term) {
                $clause = function ($sub) use ($term): void {
                    $sub->where('name', 'like', '%'.$term.'%')
                        ->orWhere('short_description', 'like', '%'.$term.'%')
                        ->orWhere('description', 'like', '%'.$term.'%')
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', '%'.$term.'%'));
                };

                $matchAny ? $outer->orWhere($clause) : $outer->where($clause);
            }
        });
    }
}
