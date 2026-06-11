<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentQris;
use App\Support\QrislyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQrisController extends Controller
{
    public function index(): JsonResponse
    {
        $svc = app(QrislyService::class);

        return response()->json([
            'data' => PaymentQris::query()->orderByDesc('is_active')->orderByDesc('id')->get()
                ->map(fn (PaymentQris $q): array => $this->map($q))->all(),
            'meta' => [
                'enabled' => $svc->enabled(),
                'active_qris_id' => $svc->qrisId(),
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'qris_image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
        ]);

        $res = app(QrislyService::class)->uploadQris(
            $request->file('qris_image')->getRealPath(),
            $validated['name'],
        );

        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'Upload QRIS gagal.'], 422);
        }

        return response()->json([
            'data' => $res['data'] ?? [],
            'message' => 'QRIS berhasil di-upload & diaktifkan.',
        ]);
    }

    public function activate(PaymentQris $qris): JsonResponse
    {
        PaymentQris::query()->where('is_active', true)->update(['is_active' => false]);
        $qris->update(['is_active' => true]);

        return response()->json(['message' => 'QRIS "'.$qris->name.'" diaktifkan.']);
    }

    public function destroy(PaymentQris $qris): JsonResponse
    {
        // Hanya hapus catatan lokal; entri di QRISLY tidak ikut terhapus.
        $qris->delete();

        return response()->json(['message' => 'QRIS dihapus dari daftar.']);
    }

    private function map(PaymentQris $q): array
    {
        return [
            'id' => $q->id,
            'qris_id' => $q->qris_id,
            'name' => $q->name,
            'merchant_name' => $q->merchant_name,
            'provider' => $q->provider,
            'is_active' => (bool) $q->is_active,
            'created_at' => optional($q->created_at)->toIso8601String(),
        ];
    }
}
