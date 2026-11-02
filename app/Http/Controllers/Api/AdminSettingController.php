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
        $data = [
            'store_whatsapp' => Setting::get('store_whatsapp', ''),
            'store_brand' => Setting::get('store_brand', ''),
            'store_email' => Setting::get('store_email', ''),
            'lion_commodity_code' => Setting::get('lion_commodity_code', 'BPI105'),
            'unique_code_enabled' => Setting::uniqueCodeEnabled(),
            'payment_transfer_enabled' => Setting::paymentTransferEnabled(),
            'payment_qris_enabled' => Setting::paymentQrisEnabled(),
            'payment_cod_enabled' => Setting::paymentCodEnabled(),
            'store_open' => Setting::storeOpen(),
        ];

        // Kill switch deploy (lihat config/features.php) - sembunyikan field
        // pengaturan reseller sepenuhnya kalau fiturnya belum dinyalakan di .env.
        if (config('features.reseller')) {
            $data['reseller_enabled'] = Setting::resellerEnabled();
            $data['reseller_min_withdraw'] = Setting::resellerMinWithdraw();
            $data['reseller_default_commission_type'] = Setting::resellerDefaultCommissionType();
            $data['reseller_default_commission_value'] = Setting::resellerDefaultCommissionValue();
        }

        return response()->json(['data' => $data]);
    }

    /** Toggle cepat terima-order-baru (dipakai tombol di sidebar admin, bukan form Pengaturan Toko). */
    public function toggleStoreOpen(): JsonResponse
    {
        $newValue = ! Setting::storeOpen();
        Setting::set('store_open', $newValue ? '1' : '0');

        return response()->json([
            'data' => ['store_open' => $newValue],
            'message' => $newValue
                ? 'Toko dibuka kembali. Customer bisa checkout seperti biasa.'
                : 'Toko ditutup sementara. Customer tidak bisa checkout (web & WhatsApp) sampai dibuka lagi.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_whatsapp' => ['nullable', 'string', 'max:20'],
            'store_brand' => ['nullable', 'string', 'max:100'],
            'store_email' => ['nullable', 'email', 'max:100'],
            'lion_commodity_code' => ['nullable', 'string', 'max:20'],
            'unique_code_enabled' => ['nullable', 'boolean'],
            'payment_transfer_enabled' => ['nullable', 'boolean'],
            'payment_qris_enabled' => ['nullable', 'boolean'],
            'payment_cod_enabled' => ['nullable', 'boolean'],
            'reseller_enabled' => ['nullable', 'boolean'],
            'reseller_min_withdraw' => ['nullable', 'integer', 'min:0'],
            'reseller_default_commission_type' => ['nullable', 'in:percentage,fixed'],
            'reseller_default_commission_value' => ['nullable', 'integer', 'min:0'],
        ]);

        Setting::set('store_whatsapp', $this->normalizeWa((string) ($validated['store_whatsapp'] ?? '')));
        Setting::set('store_brand', trim((string) ($validated['store_brand'] ?? '')));
        Setting::set('store_email', trim((string) ($validated['store_email'] ?? '')));
        Setting::set('lion_commodity_code', strtoupper(trim((string) ($validated['lion_commodity_code'] ?? ''))) ?: 'BPI105');
        Setting::set('unique_code_enabled', $request->boolean('unique_code_enabled') ? '1' : '0');
        Setting::set('payment_transfer_enabled', $request->boolean('payment_transfer_enabled') ? '1' : '0');
        Setting::set('payment_qris_enabled', $request->boolean('payment_qris_enabled') ? '1' : '0');
        Setting::set('payment_cod_enabled', $request->boolean('payment_cod_enabled') ? '1' : '0');

        if (config('features.reseller')) {
            Setting::set('reseller_enabled', $request->boolean('reseller_enabled') ? '1' : '0');
            Setting::set('reseller_min_withdraw', (string) ($validated['reseller_min_withdraw'] ?? 50000));
            Setting::set('reseller_default_commission_type', $validated['reseller_default_commission_type'] ?? 'percentage');
            Setting::set('reseller_default_commission_value', (string) ($validated['reseller_default_commission_value'] ?? 5));
        }

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
