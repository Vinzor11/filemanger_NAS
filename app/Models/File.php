<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'folder_id',
        'owner_user_id',
        'department_id',
        'original_name',
        'stored_name',
        'extension',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'storage_disk',
        'storage_path',
        'visibility',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(FilePermission::class);
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'file_tags');
    }
}

