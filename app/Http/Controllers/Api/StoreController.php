<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    /** Info publik toko (dipakai storefront, mis. tombol WhatsApp melayang). */
    public function info(): JsonResponse
    {
        return response()->json([
            'data' => [
                'brand' => Setting::get('store_brand', 'Akar Tani Kimia'),
                'whatsapp' => Setting::get('store_whatsapp', ''),
            ],
        ]);
    }
}
