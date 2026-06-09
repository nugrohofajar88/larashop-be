<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\UserUniqueCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'code',
        'name',
        'username',
        'email',
        'phone',
        'role',
        'admin_role',
        'status',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function uniqueCodeLedger(): HasMany
    {
        return $this->hasMany(UserUniqueCode::class);
    }

    public function uniqueCodeBalance(): int
    {
        // Hanya kode unik dari order yang pembayarannya SUDAH tervalidasi
        // (orders.paid_at terisi) yang dihitung sebagai saldo yang bisa dipakai.
        // Order yang masih pending_payment tidak menambah saldo.
        $incoming = (int) $this->uniqueCodeLedger()
            ->where('type', 'paid')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.id', 'user_unique_codes.ref_id')
                    ->whereNotNull('orders.paid_at');
            })
            ->sum('value');

        $outgoing = (int) $this->uniqueCodeLedger()->where('type', 'used')->sum('value');

        return max(0, $incoming - $outgoing);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin' && $this->admin_role === 'super_admin';
    }
}
