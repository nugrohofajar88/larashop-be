<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->withCount('addresses');
        $search = trim($request->string('search')->toString());

        if ($search !== '') {
            $customers->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        $items = $customers->orderBy('name')->get();

        return response()->json([
            'data' => $items->map(fn (User $customer) => ApiData::adminCustomer($customer))->values()->all(),
            'meta' => ['count' => $items->count()],
        ]);
    }

    public function show(User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $customer->load('addresses');

        return response()->json([
            'data' => ApiData::adminCustomer($customer),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCustomer($request);

        $customer = DB::transaction(function () use ($validated) {
            $customerNumber = str_pad((string) ((User::query()->where('role', 'customer')->count()) + 1), 3, '0', STR_PAD_LEFT);

            $customer = User::create([
                'code' => 'CST-'.$customerNumber,
                'name' => $validated['name'],
                'username' => $validated['username'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'role' => 'customer',
                'status' => $validated['status'],
                'password' => $validated['password'],
            ]);

            foreach ($validated['addresses'] ?? [] as $index => $addressData) {
                $customer->addresses()->create([
                    ...$addressData,
                    'is_primary' => ($addressData['is_primary'] ?? false) || $index === 0,
                ]);
            }

            return $customer->load('addresses')->loadCount('addresses');
        });

        return response()->json([
            'data' => ApiData::adminCustomer($customer),
        ], 201);
    }

    public function update(Request $request, User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $validated = $this->validateCustomer($request, $customer);

        DB::transaction(function () use ($validated, $customer): void {
            $payload = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'status' => $validated['status'],
            ];

            if (! empty($validated['password'])) {
                $payload['password'] = $validated['password'];
            }

            $customer->update($payload);
        });

        $customer->load('addresses')->loadCount('addresses');

        return response()->json([
            'data' => ApiData::adminCustomer($customer),
        ]);
    }

    public function destroy(User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $customer->delete();

        return response()->json([
            'message' => 'Customer berhasil dihapus.',
        ]);
    }

    public function storeAddress(Request $request, User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $validated = $this->validateAddress($request);

        $address = DB::transaction(function () use ($customer, $validated) {
            $isPrimary = $validated['is_primary'] ?? $customer->addresses()->doesntExist();

            if ($isPrimary) {
                $customer->addresses()->update(['is_primary' => false]);
            }

            return $customer->addresses()->create([
                ...$validated,
                'is_primary' => $isPrimary,
            ]);
        });

        return response()->json([
            'data' => ApiData::address($address),
        ], 201);
    }

    public function updateAddress(Request $request, User $customer, CustomerAddress $address): JsonResponse
    {
        abort_unless($customer->role === 'customer' && $address->user_id === $customer->id, 404);

        $validated = $this->validateAddress($request);

        DB::transaction(function () use ($customer, $address, $validated): void {
            $isPrimary = $validated['is_primary'] ?? $address->is_primary;

            if ($isPrimary) {
                $customer->addresses()->update(['is_primary' => false]);
            }

            $address->update([
                ...$validated,
                'is_primary' => $isPrimary,
            ]);
        });

        $address->refresh();

        return response()->json([
            'data' => ApiData::address($address),
        ]);
    }

    public function destroyAddress(User $customer, CustomerAddress $address): JsonResponse
    {
        abort_unless($customer->role === 'customer' && $address->user_id === $customer->id, 404);

        DB::transaction(function () use ($customer, $address): void {
            $wasPrimary = $address->is_primary;
            $address->delete();

            if ($wasPrimary) {
                $customer->addresses()->orderBy('id')->first()?->update(['is_primary' => true]);
            }
        });

        return response()->json([
            'message' => 'Alamat customer berhasil dihapus.',
        ]);
    }

    protected function validateCustomer(Request $request, ?User $customer = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($customer?->id)],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($customer?->id)],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending_verification'])],
            'password' => [$customer ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
            'addresses' => ['nullable', 'array'],
            'addresses.*.label' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.recipient_name' => ['required_with:addresses', 'string', 'max:255'],
            'addresses.*.recipient_phone' => ['required_with:addresses', 'string', 'max:20'],
            'addresses.*.province' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.city' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.district' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.subdistrict' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.postal_code' => ['required_with:addresses', 'string', 'max:10'],
            'addresses.*.address_line' => ['required_with:addresses', 'string'],
            'addresses.*.note' => ['nullable', 'string', 'max:255'],
            'addresses.*.is_primary' => ['nullable', 'boolean'],
        ]);
    }

    protected function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'province' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'subdistrict' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:10'],
            'address_line' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
    }
}
