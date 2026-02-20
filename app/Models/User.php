<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles, HasPublicId;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'employee_id',
        'email',
        'password_hash',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password_hash'] = Hash::make($value);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(self::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rejected_by');
    }

    public function ownedFolders(): HasMany
    {
        return $this->hasMany(Folder::class, 'owner_user_id');
    }

    public function ownedFiles(): HasMany
    {
        return $this->hasMany(File::class, 'owner_user_id');
    }

    public function issuedRegistrationCode(): HasMany
    {
        return $this->hasMany(EmployeeRegistrationCode::class, 'issued_by');
    }

    public function directFilePermission(): HasMany
    {
        return $this->hasMany(FilePermission::class);
    }

    public function directFolderPermission(): HasMany
    {
        return $this->hasMany(FolderPermission::class);
    }

    public function shareLinksCreated(): HasMany
    {
        return $this->hasMany(ShareLink::class, 'created_by');
    }

    public function fileVersionsCreated(): HasMany
    {
        return $this->hasMany(FileVersion::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->employee?->status === 'active';
    }
}
