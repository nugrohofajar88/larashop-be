<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (Category $c) => $this->present($c))->values()->all(),
            'meta' => ['count' => $categories->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = Category::create($this->validateData($request));

        return response()->json(['data' => $this->present($category->loadCount('products'))], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category->update($this->validateData($request, $category));

        return response()->json(['data' => $this->present($category->fresh()->loadCount('products'))]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $count = $category->products()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'category' => "Kategori masih dipakai {$count} produk. Pindahkan/kosongkan produknya dulu sebelum menghapus kategori.",
            ]);
        }

        $category->delete();

        return response()->json(['message' => 'Kategori berhasil dihapus.']);
    }

    protected function validateData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Slug: dari input kalau diisi, kalau tidak dari nama; lalu dijamin unik.
        $base = trim((string) ($data['slug'] ?? '')) !== '' ? $data['slug'] : $data['name'];
        $data['slug'] = $this->uniqueSlug(Str::slug($base), $category?->id);
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['description'] = trim((string) ($data['description'] ?? '')) ?: null;

        return $data;
    }

    protected function uniqueSlug(string $slug, ?int $ignoreId): string
    {
        $base = $slug !== '' ? $slug : 'kategori';
        $candidate = $base;
        $i = 2;

        while (
            Category::query()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    protected function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'products_count' => $category->products_count ?? $category->products()->count(),
        ];
    }
}
