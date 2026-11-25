<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Billing columns are not fillable: only Stripe webhooks change them.
 *
 * @property int $id
 * @property string $name
 * @property string|null $stripe_customer_id
 * @property string $plan free or pro
 * @property string|null $subscription_status Stripe's status, e.g. active, past_due, canceled
 * @property Carbon|null $current_period_end
 * @property Carbon|null $subscription_synced_at
 */
#[Fillable(['name'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public const FREE_PROJECT_LIMIT = 3;

    protected $attributes = ['plan' => 'free'];

    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'subscription_synced_at' => 'datetime',
        ];
    }

    /** A paid plan counts only while Stripe considers the subscription in good standing. */
    public function hasActiveSubscription(): bool
    {
        return $this->plan === 'pro' && in_array($this->subscription_status, ['active', 'trialing'], true);
    }
}
