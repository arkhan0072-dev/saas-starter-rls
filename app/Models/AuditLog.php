<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $action e.g. project.created
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $changes
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'changes'])]
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @param  array<string, mixed>  $changes */
    public static function record(Model $subject, string $event, array $changes): void
    {
        $type = Str::snake(class_basename($subject));

        static::create([
            'user_id' => Auth::id(),
            'action' => "{$type}.{$event}",
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
        ]);
    }
}
