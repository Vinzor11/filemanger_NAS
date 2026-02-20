<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_id',
        'user_id',
        'can_view',
        'can_download',
        'can_edit',
        'can_delete',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_download' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

