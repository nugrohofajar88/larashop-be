<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResellerWithdrawal;
use App\Models\Setting;
use App\Support\ApiData;
use App\Support\ResellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResellerController extends Controller
{
    public function register(Request $request, ResellerService $service): JsonResponse
    {
        if (! Setting::resellerEnabled()) {
            throw ValidationException::withMessages([
                'reseller' => 'Program reseller sedang tidak aktif.',
            ]);
        }

        $reseller = $service->applyForReseller($request->user());

        return response()->json([
            'data' => ApiData::reseller($reseller->load('user')),
            'message' => 'Pendaftaran reseller berhasil dikirim. Menunggu persetujuan admin.',
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $reseller = $request->user()->resellerProfile()->first();

        abort_if($reseller === null, 404, 'Kamu belum terdaftar sebagai reseller.');

        return response()->json([
            'data' => ApiData::reseller($reseller->load('user')),
        ]);
    }

    public function bind(Request $request, ResellerService $service): JsonResponse
    {
        if (! Setting::resellerEnabled()) {
            throw ValidationException::withMessages([
                'reseller' => 'Program reseller sedang tidak aktif.',
            ]);
        }

        $validated = $request->validate([
            'referral_code' => ['required', 'string', 'max:30'],
        ]);

        $bound = $service->bindReferral($request->user(), $validated['referral_code']);

        if (! $bound) {
            throw ValidationException::withMessages([
                'referral_code' => $request->user()->reseller_id !== null
                    ? 'Akunmu sudah tertaut ke reseller lain sebelumnya.'
                    : 'Kode referral tidak valid.',
            ]);
        }

        return response()->json([
            'message' => 'Kode referral berhasil ditautkan ke akunmu.',
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $reseller = $request->user()->resellerProfile()->first();

        abort_if($reseller === null, 404, 'Kamu belum terdaftar sebagai reseller.');

        $commissions = $reseller->commissions()->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($commissions->items())->map(fn ($c) => ApiData::resellerCommission($c))->values()->all(),
            'meta' => [
                'current_page' => $commissions->currentPage(),
                'last_page' => $commissions->lastPage(),
                'total' => $commissions->total(),
            ],
        ]);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $reseller = $request->user()->resellerProfile()->first();

        abort_if($reseller === null, 404, 'Kamu belum terdaftar sebagai reseller.');

        $withdrawals = $reseller->withdrawals()->orderByDesc('id')->get();

        return response()->json([
            'data' => $withdrawals->map(fn ($w) => ApiData::resellerWithdrawal($w))->values()->all(),
        ]);
    }

    public function storeWithdrawal(Request $request): JsonResponse
    {
        if (! Setting::resellerEnabled()) {
            throw ValidationException::withMessages([
                'reseller' => 'Program reseller sedang tidak aktif.',
            ]);
        }

        $reseller = $request->user()->resellerProfile()->first();

        abort_if($reseller === null, 404, 'Kamu belum terdaftar sebagai reseller.');

        if ($reseller->status !== 'active') {
            throw ValidationException::withMessages([
                'reseller' => 'Akun reseller kamu belum aktif.',
            ]);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $balance = $reseller->balance();
        $minWithdraw = Setting::resellerMinWithdraw();

        if ($validated['amount'] < $minWithdraw) {
            throw ValidationException::withMessages([
                'amount' => 'Minimal pencairan Rp'.number_format($minWithdraw, 0, ',', '.').'.',
            ]);
        }

        if ($validated['amount'] > $balance) {
            throw ValidationException::withMessages([
                'amount' => 'Saldo tidak cukup. Saldo kamu saat ini Rp'.number_format($balance, 0, ',', '.').'.',
            ]);
        }

        if ($reseller->withdrawals()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'amount' => 'Masih ada pengajuan pencairan yang belum diproses admin.',
            ]);
        }

        $withdrawal = ResellerWithdrawal::query()->create([
            'reseller_id' => $reseller->id,
            'amount' => $validated['amount'],
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => ApiData::resellerWithdrawal($withdrawal),
            'message' => 'Pengajuan pencairan berhasil dikirim, menunggu diproses admin.',
        ], 201);
    }
}
