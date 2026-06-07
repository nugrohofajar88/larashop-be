<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private readonly ShippingService $shipping,
    ) {
    }

    /**
     * Cari wilayah tujuan (publik) — untuk autocomplete di widget cek ongkir.
     */
    public function destinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->shipping->searchDestinations($validated['search']),
        ]);
    }

    /**
     * Hitung ongkir ke sebuah destination (publik). Berat opsional, default 1 kg.
     */
    public function cost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_id' => ['required', 'integer'],
            'weight' => ['nullable', 'integer', 'min:1'],
        ]);

        $weight = max((int) ($validated['weight'] ?? ShippingService::DEFAULT_WEIGHT), ShippingService::DEFAULT_WEIGHT);
        $options = $this->shipping->costOptions($validated['destination_id'], $weight);

        return response()->json([
            'data' => $options,
            'meta' => [
                'weight_grams' => $weight,
                'note' => 'Estimasi ongkir. Berat final mengikuti produk saat checkout.',
            ],
        ]);
    }
}
