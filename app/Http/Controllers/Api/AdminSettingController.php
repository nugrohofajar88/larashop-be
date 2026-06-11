<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'store_whatsapp' => Setting::get('store_whatsapp', ''),
                'store_brand' => Setting::get('store_brand', ''),
                'store_email' => Setting::get('store_email', ''),
                'unique_code_enabled' => Setting::uniqueCodeEnabled(),
                'payment_transfer_enabled' => Setting::paymentTransferEnabled(),
                'payment_qris_enabled' => Setting::paymentQrisEnabled(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_whatsapp' => ['nullable', 'string', 'max:20'],
            'store_brand' => ['nullable', 'string', 'max:100'],
            'store_email' => ['nullable', 'email', 'max:100'],
            'unique_code_enabled' => ['nullable', 'boolean'],
            'payment_transfer_enabled' => ['nullable', 'boolean'],
            'payment_qris_enabled' => ['nullable', 'boolean'],
        ]);

        Setting::set('store_whatsapp', $this->normalizeWa((string) ($validated['store_whatsapp'] ?? '')));
        Setting::set('store_brand', trim((string) ($validated['store_brand'] ?? '')));
        Setting::set('store_email', trim((string) ($validated['store_email'] ?? '')));
        Setting::set('unique_code_enabled', $request->boolean('unique_code_enabled') ? '1' : '0');
        Setting::set('payment_transfer_enabled', $request->boolean('payment_transfer_enabled') ? '1' : '0');
        Setting::set('payment_qris_enabled', $request->boolean('payment_qris_enabled') ? '1' : '0');

        return response()->json([
            'message' => 'Pengaturan toko berhasil disimpan.',
        ]);
    }

    /**
     * Normalisasi nomor ke format internasional tanpa "+" (cocok utk wa.me):
     * "0812..." -> "62812...", "+62812..." -> "62812...".
     */
    protected function normalizeWa(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return $digits;
    }
}
