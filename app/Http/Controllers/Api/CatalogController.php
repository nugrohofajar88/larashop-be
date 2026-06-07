<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['category', 'images', 'variants'])
            ->where('public_status', 'active')
            ->where('stock', '>', 1);
        $search = trim($request->string('search')->toString());
        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();

        if ($category !== '' && $category !== 'all') {
            $products->whereHas('category', fn ($query) => $query->where('name', $category));
        }

        if ($status !== '' && $status !== 'all') {
            $catalogStatus = match ($status) {
                'Tersedia' => 'available',
                'Stok terbatas' => 'limited',
                'Pre-order' => 'preorder',
                'Habis' => 'sold_out',
                default => null,
            };

            if ($catalogStatus !== null) {
                $products->where('catalog_status', $catalogStatus);
            }
        }

        match ($sort) {
            'price_asc' => $products->orderBy('price'),
            'price_desc' => $products->orderByDesc('price'),
            'name_asc' => $products->orderBy('name'),
            default => $products->orderByDesc('is_featured')->orderByDesc('published_at')->orderBy('name'),
        };

        if ($search !== '') {
            $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // Semua kata harus cocok (AND); kalau kosong, longgarkan ke OR.
            $items = (clone $products)->whereSearchTerms($terms, false)->get();

            if ($items->isEmpty()) {
                $items = (clone $products)->whereSearchTerms($terms, true)->get();
            }
        } else {
            $items = $products->get();
        }

        return response()->json([
            'data' => $items->map(fn (Product $product) => ApiData::product($product))->values()->all(),
            'meta' => [
                'count' => $items->count(),
                'categories' => Product::query()
                    ->with('category')
                    ->where('public_status', 'active')
                    ->where('stock', '>', 1)
                    ->get()
                    ->pluck('category.name')
                    ->unique()
                    ->values()
                    ->all(),
                'statuses' => ['Tersedia', 'Stok terbatas', 'Pre-order'],
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'images', 'variants'])
            ->where('public_status', 'active')
            ->where('stock', '>', 1)
            ->where('slug', $slug)
            ->first();

        abort_if($product === null, 404);

        $relatedProducts = Product::query()
            ->with(['category', 'images', 'variants'])
            ->where('public_status', 'active')
            ->where('stock', '>', 1)
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->take(3)
            ->get();

        if ($relatedProducts->count() < 3) {
            $existingIds = $relatedProducts->pluck('id')->push($product->id);
            $fallbackProducts = Product::query()
                ->with(['category', 'images', 'variants'])
                ->where('public_status', 'active')
                ->where('stock', '>', 1)
                ->whereNotIn('id', $existingIds)
                ->take(3 - $relatedProducts->count())
                ->get();

            $relatedProducts = $relatedProducts->concat($fallbackProducts);
        }

        return response()->json([
            'data' => [
                'product' => ApiData::product($product),
                'related_products' => $relatedProducts
                    ->map(fn (Product $relatedProduct) => ApiData::product($relatedProduct))
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
