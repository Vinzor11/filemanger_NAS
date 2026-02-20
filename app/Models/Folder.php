<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'parent_id',
        'name',
        'owner_user_id',
        'department_id',
        'path',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(FolderPermission::class);
    }
}

