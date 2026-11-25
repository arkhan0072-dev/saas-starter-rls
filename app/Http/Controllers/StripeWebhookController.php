<?php

namespace App\Http\Controllers;

use App\Billing\StripeSignature;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if (! StripeSignature::verify($request->getContent(), $request->header('Stripe-Signature'), config()->string('services.stripe.webhook_secret'))) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        // Recording the event and applying it share a transaction: if applying fails,
        // the event is not marked as seen and Stripe's retry will apply it.
        DB::transaction(function () use ($request) {
            $firstDelivery = DB::table('stripe_events')->insertOrIgnore([
                'id' => $request->string('id')->value(),
                'type' => $request->string('type')->value(),
                'created_at' => now(),
            ]) === 1;

            if ($firstDelivery && in_array($request->string('type')->value(), self::SUBSCRIPTION_EVENTS, true)) {
                $this->syncSubscription($request);
            }
        });

        return response()->json(['received' => true]);
    }

    private function syncSubscription(Request $request): void
    {
        $tenant = Tenant::where('stripe_customer_id', $request->string('data.object.customer')->value())->lockForUpdate()->first();
        $eventTime = Carbon::createFromTimestamp($request->integer('created'));

        // Stripe does not guarantee delivery order.
        if ($tenant === null || $tenant->subscription_synced_at?->greaterThan($eventTime)) {
            return;
        }

        $ended = $request->string('type')->value() === 'customer.subscription.deleted';
        $price = $request->string('data.object.items.data.0.price.id')->value();
        $plan = array_search($price, config()->array('services.stripe.prices'), true);

        $tenant->forceFill([
            'plan' => $ended || ! is_string($plan) ? 'free' : $plan,
            'subscription_status' => $request->string('data.object.status')->value(),
            'current_period_end' => $ended ? null : Carbon::createFromTimestamp($request->integer('data.object.items.data.0.current_period_end')),
            'subscription_synced_at' => $eventTime,
        ])->save();
    }
}
