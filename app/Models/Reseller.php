<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'commission_type',
        'commission_value',
        'status',
        'rejected_reason',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_value' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Customer yang tertaut permanen ke reseller ini (lihat users.reseller_id). */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'reseller_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ResellerCommission::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(ResellerWithdrawal::class);
    }

    /** Saldo yang bisa dicairkan: komisi confirmed dikurangi penarikan yang sudah disetujui. */
    public function balance(): int
    {
        $confirmed = (int) $this->commissions()->where('status', 'confirmed')->sum('amount');
        $withdrawn = (int) $this->withdrawals()->where('status', 'approved')->sum('amount');

        return max(0, $confirmed - $withdrawn);
    }
}
