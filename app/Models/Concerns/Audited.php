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
                $model->audit('updated', $model->auditValues(array_intersect_key($model->getOriginal(), $changes)), $changes);
            }
        });
        static::deleted(fn ($model) => $model->audit('deleted', $model->auditValues($model->getAttributes()), null));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->audit('restored', null, null));
        }
    }

    // hidden and encrypted columns (API keys, secrets) show only that they were set or changed, never the value
    private function auditValues(array $values): array
    {
        $values = array_diff_key($values, array_flip(self::$auditSkip));
        foreach ($values as $key => $value) {
            if (in_array($key, $this->getHidden(), true) || str_starts_with((string) ($this->getCasts()[$key] ?? ''), 'encrypted')) {
                $values[$key] = ($value === null || $value === '') ? null : '[secret]';
            }
        }
        return $values;
    }

    private function audit(string $event, ?array $old, ?array $new): void
    {
        AuditLogger::log(Str::snake(class_basename($this)) . '.' . $event, $this, $old, $new);
    }
}
