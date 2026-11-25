<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit log entry for every create, update and delete made through Eloquent.
 * Hidden attributes (password and token hashes) are never copied into the log.
 *
 * shortcut: the entry is written after the change, not in the same transaction; wrap writes in DB::transaction() where a missing entry is unacceptable.
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn (Model $model) => AuditLog::record($model, 'created', self::auditable($model, $model->getAttributes())));
        static::deleted(fn (Model $model) => AuditLog::record($model, 'deleted', self::auditable($model, $model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = [];
            foreach (self::auditable($model, $model->getChanges()) as $key => $new) {
                $changes[$key] = ['old' => $model->getRawOriginal($key), 'new' => $new];
            }

            if ($changes !== []) {
                AuditLog::record($model, 'updated', $changes);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function auditable(Model $model, array $attributes): array
    {
        $skip = [...$model->getHidden(), $model->getKeyName(), 'tenant_id', 'created_at', 'updated_at'];

        return array_diff_key($attributes, array_flip($skip));
    }
}
