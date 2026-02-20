<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_user_id',
        'scope',
        'idempotency_key',
        'request_hash',
        'status',
        'response_code',
        'response_body',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'resource_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

