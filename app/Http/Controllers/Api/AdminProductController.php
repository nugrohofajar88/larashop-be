<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()->with(['category', 'images', 'variants']);
        $search = trim($request->string('search')->toString());

        if ($search !== '') {
            $products->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%');
            });
        }

        $items = $products->orderBy('name')->get();

        return response()->json([
            'data' => $items->map(fn (Product $product) => ApiData::adminProduct($product))->values()->all(),
            'meta' => ['count' => $items->count()],
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'images', 'variants']);

        return response()->json([
            'data' => ApiData::adminProduct($product),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateProduct($request);

        $product = DB::transaction(function () use ($validated) {
            $category = Category::query()->where('slug', $validated['category_slug'])->firstOrFail();
            $imagePaths = $validated['image_paths'] ?? [];
            $variants = $this->resolvedVariants($validated);
            $defaultVariant = $this->defaultVariant($variants);

            $product = Product::create([
                'category_id' => $category->id,
                'sku' => $validated['sku'],
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'],
                'price' => $defaultVariant['price'],
                'compare_at_price' => $defaultVariant['compare_at_price'],
                'weight_label' => $defaultVariant['label'],
                'weight_grams' => $defaultVariant['weight_grams'],
                'length_cm' => $defaultVariant['length_cm'],
                'width_cm' => $defaultVariant['width_cm'],
                'height_cm' => $defaultVariant['height_cm'],
                'stock' => $this->totalVariantStock($variants),
                'public_status' => $validated['public_status'],
                'catalog_status' => $validated['catalog_status'],
                'badge_label' => $validated['badge_label'] ?? null,
                'sold_count' => $validated['sold_count'] ?? 0,
                'highlights' => $validated['highlights'] ?? [],
                'is_featured' => $validated['is_featured'] ?? false,
                'published_at' => $validated['published_at'] ?? null,
            ]);

            $this->syncImages($product, $imagePaths);
            $this->syncVariants($product, $variants);

            return $product->load(['category', 'images', 'variants']);
        });

        return response()->json([
            'data' => ApiData::adminProduct($product),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $this->validateProduct($request, $product);

        DB::transaction(function () use ($validated, $product): void {
            $category = Category::query()->where('slug', $validated['category_slug'])->firstOrFail();
            $imagePaths = $validated['image_paths'] ?? null;
            $variants = $this->resolvedVariants($validated);
            $defaultVariant = $this->defaultVariant($variants);

            $product->update([
                'category_id' => $category->id,
                'sku' => $validated['sku'],
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'],
                'price' => $defaultVariant['price'],
                'compare_at_price' => $defaultVariant['compare_at_price'],
                'weight_label' => $defaultVariant['label'],
                'weight_grams' => $defaultVariant['weight_grams'],
                'length_cm' => $defaultVariant['length_cm'],
                'width_cm' => $defaultVariant['width_cm'],
                'height_cm' => $defaultVariant['height_cm'],
                'stock' => $this->totalVariantStock($variants),
                'public_status' => $validated['public_status'],
                'catalog_status' => $validated['catalog_status'],
                'badge_label' => $validated['badge_label'] ?? null,
                'sold_count' => $validated['sold_count'] ?? 0,
                'highlights' => $validated['highlights'] ?? [],
                'is_featured' => $validated['is_featured'] ?? false,
                'published_at' => $validated['published_at'] ?? null,
            ]);

            if ($imagePaths !== null) {
                $this->syncImages($product, $imagePaths);
            }

            $this->syncVariants($product, $variants);
        });

        $product->load(['category', 'images', 'variants']);

        return response()->json([
            'data' => ApiData::adminProduct($product),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'message' => 'Produk berhasil dihapus.',
        ]);
    }

    protected function validateProduct(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'slug' => ['required', 'string', 'max:150', Rule::unique('products', 'slug')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'category_slug' => ['required', 'string', Rule::exists('categories', 'slug')],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'variants' => ['nullable', 'array', 'min:1'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.label' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.price' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.compare_at_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0'],
            'variants.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'variants.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'variants.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_default' => ['nullable', 'boolean'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'price' => ['nullable', 'integer', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'weight_label' => ['nullable', 'string', 'max:100'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'public_status' => ['required', Rule::in(['draft', 'active', 'inactive', 'preorder'])],
            'catalog_status' => ['required', Rule::in(['available', 'limited', 'preorder', 'sold_out'])],
            'badge_label' => ['nullable', 'string', 'max:50'],
            'sold_count' => ['nullable', 'integer', 'min:0'],
            'highlights' => ['nullable', 'array'],
            'highlights.*' => ['string', 'max:120'],
            'image_paths' => ['nullable', 'array', 'min:1'],
            'image_paths.*' => ['string', 'max:255'],
            'is_featured' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);
    }

    protected function resolvedVariants(array $validated): array
    {
        if (! empty($validated['variants'])) {
            return $this->normalizedVariants($validated['variants']);
        }

        return [[
            'sku' => strtoupper(trim((string) $validated['sku'])).'-1',
            'label' => trim((string) ($validated['weight_label'] ?? 'Default')),
            'price' => (int) ($validated['price'] ?? 0),
            'compare_at_price' => null,
            'stock' => (int) ($validated['stock'] ?? 0),
            'weight_grams' => isset($validated['weight_grams']) ? (int) $validated['weight_grams'] : null,
            'length_cm' => isset($validated['length_cm']) ? (float) $validated['length_cm'] : null,
            'width_cm' => isset($validated['width_cm']) ? (float) $validated['width_cm'] : null,
            'height_cm' => isset($validated['height_cm']) ? (float) $validated['height_cm'] : null,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]];
    }

    protected function normalizedVariants(array $variants): array
    {
        $normalized = collect($variants)
            ->values()
            ->map(function (array $variant, int $index): array {
                return [
                    'sku' => strtoupper(trim((string) $variant['sku'])),
                    'label' => trim((string) $variant['label']),
                    'price' => (int) $variant['price'],
                    'compare_at_price' => isset($variant['compare_at_price']) && $variant['compare_at_price'] !== null && $variant['compare_at_price'] !== ''
                        ? (int) $variant['compare_at_price']
                        : null,
                    'stock' => (int) $variant['stock'],
                    'weight_grams' => isset($variant['weight_grams']) && $variant['weight_grams'] !== null && $variant['weight_grams'] !== ''
                        ? (int) $variant['weight_grams']
                        : null,
                    'length_cm' => isset($variant['length_cm']) && $variant['length_cm'] !== null && $variant['length_cm'] !== ''
                        ? (float) $variant['length_cm']
                        : null,
                    'width_cm' => isset($variant['width_cm']) && $variant['width_cm'] !== null && $variant['width_cm'] !== ''
                        ? (float) $variant['width_cm']
                        : null,
                    'height_cm' => isset($variant['height_cm']) && $variant['height_cm'] !== null && $variant['height_cm'] !== ''
                        ? (float) $variant['height_cm']
                        : null,
                    'is_default' => (bool) ($variant['is_default'] ?? false),
                    'is_active' => array_key_exists('is_active', $variant) ? (bool) $variant['is_active'] : true,
                    'sort_order' => $index + 1,
                ];
            })
            ->values();

        if (! $normalized->contains(fn (array $variant) => $variant['is_default'])) {
            $normalized[0]['is_default'] = true;
        }

        $defaultIndex = $normalized->search(fn (array $variant) => $variant['is_default']);

        return $normalized
            ->map(function (array $variant, int $index) use ($defaultIndex): array {
                $variant['is_default'] = $index === $defaultIndex;
                return $variant;
            })
            ->all();
    }

    protected function defaultVariant(array $variants): array
    {
        return collect($variants)->firstWhere('is_default', true) ?? $variants[0];
    }

    protected function totalVariantStock(array $variants): int
    {
        return (int) collect($variants)
            ->filter(fn (array $variant) => $variant['is_active'])
            ->sum('stock');
    }

    protected function syncImages(Product $product, array $imagePaths): void
    {
        $product->images()->delete();

        foreach (array_values($imagePaths) as $index => $path) {
            $product->images()->create([
                'path' => $path,
                'alt' => $product->name,
                'sort_order' => $index + 1,
                'is_primary' => $index === 0,
            ]);
        }
    }

    protected function syncVariants(Product $product, array $variants): void
    {
        $product->variants()->delete();

        foreach ($variants as $variant) {
            $product->variants()->create($variant);
        }
    }
}
