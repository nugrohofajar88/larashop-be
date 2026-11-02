<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reseller;
use App\Models\ResellerWithdrawal;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminResellerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();

        $query = Reseller::query()->with('user')->withCount('referredUsers');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $resellers = $query->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($resellers->items())->map(fn (Reseller $r) => ApiData::adminReseller($r))->values()->all(),
            'meta' => [
                'current_page' => $resellers->currentPage(),
                'last_page' => $resellers->lastPage(),
                'total' => $resellers->total(),
            ],
        ]);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        $reseller->load([
            'user',
            'commissions' => fn ($q) => $q->orderByDesc('id'),
            'withdrawals' => fn ($q) => $q->orderByDesc('id'),
        ]);

        $data = ApiData::adminReseller($reseller);
        $data['commissions'] = $reseller->commissions->map(fn ($c) => ApiData::resellerCommission($c))->values()->all();
        $data['withdrawals'] = $reseller->withdrawals->map(fn ($w) => ApiData::resellerWithdrawal($w))->values()->all();

        return response()->json(['data' => $data]);
    }

    public function approve(Reseller $reseller): JsonResponse
    {
        if ($reseller->status === 'active') {
            throw ValidationException::withMessages(['reseller' => 'Reseller ini sudah aktif.']);
        }

        $reseller->update(['status' => 'active', 'approved_at' => now(), 'rejected_reason' => null]);
        $reseller->load('user');

        app(\App\Support\ResellerNotifier::class)->sendApproved($reseller);

        return response()->json([
            'data' => ApiData::adminReseller($reseller),
            'message' => 'Reseller disetujui dan sudah aktif.',
        ]);
    }

    public function reject(Request $request, Reseller $reseller): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reseller->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'data' => ApiData::adminReseller($reseller->load('user')),
            'message' => 'Pendaftaran reseller ditolak.',
        ]);
    }

    public function suspend(Reseller $reseller): JsonResponse
    {
        $reseller->update(['status' => 'suspended']);

        return response()->json([
            'data' => ApiData::adminReseller($reseller->load('user')),
            'message' => 'Reseller ditangguhkan. Kode referralnya tidak bisa dipakai lagi sampai diaktifkan ulang.',
        ]);
    }

    public function reactivate(Reseller $reseller): JsonResponse
    {
        if (! in_array($reseller->status, ['suspended', 'rejected'], true)) {
            throw ValidationException::withMessages(['reseller' => 'Reseller ini tidak dalam status yang bisa diaktifkan ulang.']);
        }

        $reseller->update([
            'status' => 'active',
            'approved_at' => $reseller->approved_at ?? now(),
            'rejected_reason' => null,
        ]);

        return response()->json([
            'data' => ApiData::adminReseller($reseller->load('user')),
            'message' => 'Reseller diaktifkan kembali.',
        ]);
    }

    public function updateCommission(Request $request, Reseller $reseller): JsonResponse
    {
        $validated = $request->validate([
            'commission_type' => ['required', 'in:percentage,fixed'],
            'commission_value' => ['required', 'integer', 'min:0'],
        ]);

        $reseller->update($validated);

        return response()->json([
            'data' => ApiData::adminReseller($reseller->load('user')),
            'message' => 'Aturan komisi reseller berhasil diperbarui.',
        ]);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();

        $query = ResellerWithdrawal::query()->with(['reseller.user']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $withdrawals = $query->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($withdrawals->items())->map(fn (ResellerWithdrawal $w): array => ApiData::resellerWithdrawal($w) + [
                'reseller_name' => $w->reseller->user?->name,
                'referral_code' => $w->reseller->referral_code,
            ])->values()->all(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    public function approveWithdrawal(Request $request, ResellerWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            throw ValidationException::withMessages(['withdrawal' => 'Pengajuan ini sudah diproses.']);
        }

        $withdrawal->update([
            'status' => 'approved',
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        app(\App\Support\ResellerNotifier::class)->sendWithdrawalProcessed($withdrawal, true);

        return response()->json([
            'data' => ApiData::resellerWithdrawal($withdrawal),
            'message' => 'Pencairan disetujui. Transfer manual ke reseller lalu catat di sini.',
        ]);
    }

    public function rejectWithdrawal(Request $request, ResellerWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            throw ValidationException::withMessages(['withdrawal' => 'Pengajuan ini sudah diproses.']);
        }

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        $withdrawal->update([
            'status' => 'rejected',
            'admin_note' => $validated['admin_note'] ?? null,
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        app(\App\Support\ResellerNotifier::class)->sendWithdrawalProcessed($withdrawal, false);

        return response()->json([
            'data' => ApiData::resellerWithdrawal($withdrawal),
            'message' => 'Pengajuan pencairan ditolak.',
        ]);
    }
}
