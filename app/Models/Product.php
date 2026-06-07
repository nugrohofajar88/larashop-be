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
        'price',
        'compare_at_price',
        'weight_label',
        'weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'stock',
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
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'weight_grams' => 'integer',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'stock' => 'integer',
            'sold_count' => 'integer',
            'highlights' => 'array',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
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
