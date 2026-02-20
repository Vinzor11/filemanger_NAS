<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'employee_no',
        'department_id',
        'position_id',
        'position_title',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile',
        'status',
        'hired_at',
    ];

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function registrationCode(): HasOne
    {
        return $this->hasOne(EmployeeRegistrationCode::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}

