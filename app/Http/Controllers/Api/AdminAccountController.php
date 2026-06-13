<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = User::query()->where('role', 'admin');
        $search = trim($request->string('search')->toString());

        if (! $request->user()->isSuperAdmin()) {
            $accounts->where('admin_role', '!=', 'super_admin');
        }

        if ($search !== '') {
            $accounts->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        $items = $accounts->orderBy('name')->get();

        return response()->json([
            'data' => $items->map(fn (User $user) => ApiData::adminAccount($user))->values()->all(),
            'meta' => [
                'count' => $items->count(),
            ],
        ]);
    }

    public function show(Request $request, User $account): JsonResponse
    {
        $this->authorizeTargetAccount($request->user(), $account);

        return response()->json([
            'data' => ApiData::adminAccount($account),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request->user());

        $validated = $this->validateAccount($request);
        $nextNumber = str_pad((string) ((User::query()->where('role', 'admin')->count()) + 1), 3, '0', STR_PAD_LEFT);

        $account = User::create([
            'code' => 'ADM-'.$nextNumber,
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'role' => 'admin',
            'admin_role' => $validated['admin_role'],
            'status' => $validated['status'],
            'password' => $validated['password'],
        ]);

        return response()->json([
            'data' => ApiData::adminAccount($account),
        ], 201);
    }

    public function update(Request $request, User $account): JsonResponse
    {
        $actor = $request->user();
        $isSuper = $actor->isSuperAdmin();
        $isSelf = $actor->is($account);

        // Non-super hanya boleh mengubah profil DIRINYA SENDIRI.
        abort_unless($isSuper || $isSelf, 403, 'Kamu hanya bisa mengubah profil sendiri.');

        if ($isSuper) {
            $this->authorizeTargetAccount($actor, $account);
        }

        // Role & status hanya boleh diubah super admin (cegah self-escalation).
        $validated = $this->validateAccount($request, $account, $isSuper);
        $payload = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ];

        if ($isSuper) {
            $payload['admin_role'] = $validated['admin_role'];
            $payload['status'] = $validated['status'];
        }

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $account->update($payload);

        return response()->json([
            'data' => ApiData::adminAccount($account->refresh()),
        ]);
    }

    public function destroy(Request $request, User $account): JsonResponse
    {
        $this->ensureSuperAdmin($request->user());
        $this->authorizeTargetAccount($request->user(), $account);

        if ($request->user()->is($account)) {
            throw ValidationException::withMessages([
                'account' => ['Akun yang sedang dipakai login tidak bisa dihapus.'],
            ]);
        }

        $account->delete();

        return response()->json([
            'message' => 'Account admin berhasil dihapus.',
        ]);
    }

    protected function validateAccount(Request $request, ?User $account = null, bool $withRole = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($account?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account?->id)],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($account?->id)],
            'password' => [$account ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];

        // Role & status hanya divalidasi saat super admin yang mengubah.
        if ($withRole) {
            $rules['admin_role'] = ['required', Rule::in(['super_admin', 'operational_admin', 'warehouse_admin'])];
            $rules['status'] = ['required', Rule::in(['active', 'inactive'])];
        }

        return $request->validate($rules);
    }

    /** Hanya super admin yang boleh menambah/ubah/hapus akun admin. */
    protected function ensureSuperAdmin(User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403, 'Hanya super admin yang dapat mengelola akun admin.');
    }

    protected function authorizeTargetAccount(User $actor, User $target): void
    {
        abort_unless($target->role === 'admin', 404);

        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            abort(404);
        }
    }
}
