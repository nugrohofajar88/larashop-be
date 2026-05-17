<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()
            ->where('username', $credentials['login'])
            ->orWhere('phone', $credentials['login'])
            ->orWhere('email', $credentials['login'])
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Kredensial tidak valid.'],
            ]);
        }

        if ($user->status !== 'active') {
            abort(403, 'Akun belum aktif.');
        }

        $token = $user->createToken(
            $credentials['device_name'] ?? 'frontend-client',
            ['role:'.$user->role]
        )->plainTextToken;

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => ApiData::customer($user),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $customerNumber = str_pad((string) ((User::query()->where('role', 'customer')->count()) + 1), 3, '0', STR_PAD_LEFT);

        $user = User::create([
            'code' => 'CST-'.$customerNumber,
            'name' => $validated['name'],
            'username' => $validated['username'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'role' => 'customer',
            'status' => 'active',
            'password' => $validated['password'],
        ]);

        $token = $user->createToken('frontend-register', ['role:customer'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => ApiData::customer($user),
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ApiData::customer($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Token berhasil dicabut.',
        ]);
    }
}
