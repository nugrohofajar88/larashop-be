<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserUniqueCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'value',
        'ref_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'ref_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
