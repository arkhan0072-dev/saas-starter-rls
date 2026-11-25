<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;

/** For models on tables protected by RowLevelSecurity: new rows get the current tenant. */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            $model->setAttribute('tenant_id', $model->getAttribute('tenant_id') ?? app(Tenancy::class)->id());
        });
    }
}
