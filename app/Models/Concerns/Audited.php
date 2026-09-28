<?php

namespace App\Models\Concerns;

use App\Services\Isp\AuditLogger;
use Illuminate\Support\Str;

// Writes every create / change / delete / restore of the model to the audit log (who, when, IP,
// old and new values), whichever controller, service or job made it. Action names are
// "<model>.<event>", e.g. "bank_transaction.updated".
trait Audited
{
    // bookkeeping columns that change on every save and say nothing about the record
    private static array $auditSkip = ['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by', 'ipAddress'];

    public static function bootAudited(): void
    {
        static::created(fn ($model) => $model->audit('created', null, $model->auditValues($model->getAttributes())));
        static::updated(function ($model) {
            $changes = $model->auditValues($model->getChanges());
            if ($changes) {
                $model->audit('updated', array_intersect_key($model->getOriginal(), $changes), $changes);
            }
        });
        static::deleted(fn ($model) => $model->audit('deleted', $model->auditValues($model->getAttributes()), null));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->audit('restored', null, null));
        }
    }

    private function auditValues(array $values): array
    {
        return array_diff_key($values, array_flip(array_merge(self::$auditSkip, $this->getHidden())));
    }

    private function audit(string $event, ?array $old, ?array $new): void
    {
        AuditLogger::log(Str::snake(class_basename($this)) . '.' . $event, $this, $old, $new);
    }
}
