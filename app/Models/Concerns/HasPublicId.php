<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (blank($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }
}

