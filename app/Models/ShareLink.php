<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLink extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'file_id',
        'token',
        'expires_at',
        'max_downloads',
        'download_count',
        'password_hash',
        'created_by',
        'revoked_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_downloads' => 'integer',
            'download_count' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAccessible(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && now()->greaterThan($this->expires_at)) {
            return false;
        }

        if ($this->max_downloads !== null && $this->download_count >= $this->max_downloads) {
            return false;
        }

        return true;
    }
}

