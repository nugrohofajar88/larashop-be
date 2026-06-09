<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Support\ApiData;
use App\Support\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ApiData::customer($request->user()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            ...($validated['password'] ?? null ? ['password' => $validated['password']] : []),
        ]);

        return response()->json([
            'data' => ApiData::customer($user->fresh()),
            'message' => 'Profil customer berhasil diperbarui.',
        ]);
    }

    public function addresses(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn ($address) => ApiData::address($address))
            ->values()
            ->all();

        return response()->json([
            'data' => $addresses,
        ]);
    }

    public function searchDestinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $items = app(ShippingService::class)->searchDestinations($validated['search'], $validated['limit'] ?? 5);

        return response()->json([
            'data' => $items,
        ]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        Log::info('api.customer.addresses.store.request', [
            'user_id' => $request->user()?->id,
            'payload' => $request->all(),
        ]);

        try {
            $validated = $this->validateAddress($request);
        } catch (ValidationException $exception) {
            Log::warning('api.customer.addresses.store.validation_failed', [
                'user_id' => $request->user()?->id,
                'errors' => $exception->errors(),
                'payload' => $request->all(),
            ]);

            throw $exception;
        }

        $user = $request->user();

        $address = DB::transaction(function () use ($request, $user, $validated) {
            $isPrimaryRequested = $request->has('is_primary') ? $request->boolean('is_primary') : null;
            $isPrimary = $isPrimaryRequested ?? $user->addresses()->doesntExist();

            if ($isPrimary) {
                $user->addresses()->update(['is_primary' => false]);
            }

            $address = $user->addresses()->create([
                ...$validated,
                'is_primary' => $isPrimary,
            ]);

            if (! $isPrimary && ! $user->addresses()->where('is_primary', true)->exists()) {
                $address->update(['is_primary' => true]);
            }

            return $address;
        });

        return response()->json([
            'data' => ApiData::address($address),
        ], 201);
    }

    public function updateAddress(Request $request, CustomerAddress $address): JsonResponse
    {
        Log::info('api.customer.addresses.update.request', [
            'user_id' => $request->user()?->id,
            'address_id' => $address->id,
            'payload' => $request->all(),
        ]);

        abort_unless($address->user_id === $request->user()->id, 404);

        try {
            $validated = $this->validateAddress($request, $address);
        } catch (ValidationException $exception) {
            Log::warning('api.customer.addresses.update.validation_failed', [
                'user_id' => $request->user()?->id,
                'address_id' => $address->id,
                'errors' => $exception->errors(),
                'payload' => $request->all(),
            ]);

            throw $exception;
        }

        DB::transaction(function () use ($request, $address, $validated): void {
            $rawIsPrimary = $request->input('is_primary');
            $isPrimary = in_array($rawIsPrimary, [true, 1, '1', 'true', 'on'], true);

            if ($isPrimary) {
                $request->user()->addresses()->whereKeyNot($address->id)->update(['is_primary' => false]);
            }

            $address->update([
                ...$validated,
                'is_primary' => $isPrimary,
            ]);
        });

        $address->refresh();

        Log::info('api.customer.addresses.update.result', [
            'user_id' => $request->user()?->id,
            'address_id' => $address->id,
            'raw_is_primary' => $request->input('is_primary'),
            'saved_is_primary' => $address->is_primary,
            'current_primary_map' => $request->user()->addresses()->orderBy('id')->get(['id', 'is_primary'])->toArray(),
        ]);

        return response()->json([
            'data' => ApiData::address($address),
        ]);
    }

    public function destroyAddress(Request $request, CustomerAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);

        DB::transaction(function () use ($request, $address): void {
            $wasPrimary = $address->is_primary;
            $address->delete();

            if ($wasPrimary) {
                $request->user()->addresses()->where('is_primary', false)->orderBy('id')->first()?->update(['is_primary' => true]);
            }
        });

        return response()->json([
            'message' => 'Alamat berhasil dihapus.',
        ]);
    }

    protected function validateAddress(Request $request, ?CustomerAddress $address = null): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'destination_id' => ['nullable', 'integer'],
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






