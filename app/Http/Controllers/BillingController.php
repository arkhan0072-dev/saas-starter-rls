<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BillingController extends Controller
{
    /** Start a Stripe Checkout session for the pro plan. The webhook upgrades the tenant once payment succeeds. */
    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $tenant = $user->tenant;

        try {
            if ($tenant->stripe_customer_id === null) {
                $customer = $this->stripe()->post('customers', [
                    'name' => $tenant->name,
                    'metadata' => ['tenant_id' => $tenant->id],
                ])->throw();
                $tenant->forceFill(['stripe_customer_id' => $customer->json('id')])->save();
            }

            $session = $this->stripe()->post('checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $tenant->stripe_customer_id,
                'client_reference_id' => (string) $tenant->id,
                'line_items' => [['price' => config()->string('services.stripe.prices.pro'), 'quantity' => 1]],
                'success_url' => config()->string('app.url').'/billing/success',
                'cancel_url' => config()->string('app.url').'/billing',
            ])->throw();
        } catch (RequestException) {
            return response()->json(['message' => 'Payment provider unavailable, please try again.'], 502);
        }

        return response()->json(['url' => $session->json('url')]);
    }

    private function stripe(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1/')
            ->withToken(config()->string('services.stripe.secret'))
            ->asForm()
            ->timeout(10);
    }
}
