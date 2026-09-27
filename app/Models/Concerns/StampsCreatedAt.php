<?php

namespace App\Models\Concerns;

// For append-only tables without Eloquent timestamps: created_at is set from PHP, in the
// company's timezone, instead of MySQL's CURRENT_TIMESTAMP default, which follows the
// database server's clock.
trait StampsCreatedAt
{
    protected static function bootStampsCreatedAt(): void
    {
        static::creating(function ($model) {
            $model->created_at ??= now();
        });
    }
}
