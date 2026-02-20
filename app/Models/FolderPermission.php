<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'folder_id',
        'user_id',
        'can_view',
        'can_upload',
        'can_edit',
        'can_delete',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_upload' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
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

