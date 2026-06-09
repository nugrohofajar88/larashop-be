<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = PaymentAccount::query()->ordered()->get();

        return response()->json([
            'data' => $accounts->map(fn (PaymentAccount $a) => $this->present($a))->values()->all(),
            'meta' => ['count' => $accounts->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = PaymentAccount::create($this->validateData($request));

        return response()->json(['data' => $this->present($account)], 201);
    }

    public function update(Request $request, PaymentAccount $paymentAccount): JsonResponse
    {
        $paymentAccount->update($this->validateData($request));

        return response()->json(['data' => $this->present($paymentAccount->refresh())]);
    }

    public function destroy(PaymentAccount $paymentAccount): JsonResponse
    {
        $paymentAccount->delete();

        return response()->json(['message' => 'Rekening pembayaran berhasil dihapus.']);
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_holder' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    protected function present(PaymentAccount $account): array
    {
        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'account_holder' => $account->account_holder,
            'note' => $account->note,
            'is_active' => $account->is_active,
            'sort_order' => $account->sort_order,
        ];
    }
}
