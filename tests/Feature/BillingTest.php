<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_123',
        'services.stripe.webhook_secret' => 'whsec_test_456',
        'services.stripe.prices' => ['pro' => 'price_pro_monthly'],
    ]);

    $this->acme = Tenant::factory()->create(['stripe_customer_id' => 'cus_acme']);
    $this->owner = User::factory()->for($this->acme)->create(['role' => Role::Owner]);
});

/** Send a webhook the way Stripe does: JSON body plus a Stripe-Signature header. */
function stripeWebhook(array $event, ?string $signature = null, ?int $timestamp = null): TestResponse
{
    $payload = json_encode($event);
    $timestamp ??= time();
    $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_456');

    return test()->call('POST', '/api/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload);
}

function subscriptionEvent(string $type, string $status = 'active', string $id = 'evt_1', int $created = 1_800_000_000, string $customer = 'cus_acme'): array
{
    return [
        'id' => $id,
        'type' => $type,
        'created' => $created,
        'data' => ['object' => [
            'customer' => $customer,
            'status' => $status,
            'items' => ['data' => [['price' => ['id' => 'price_pro_monthly'], 'current_period_end' => 1_802_592_000]]],
        ]],
    ];
}

describe('webhooks', function () {
    it('upgrades the tenant when a subscription becomes active', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.updated'))->assertOk();

        $this->acme->refresh();
        expect($this->acme->plan)->toBe('pro')
            ->and($this->acme->subscription_status)->toBe('active')
            ->and($this->acme->current_period_end->timestamp)->toBe(1_802_592_000);
    });

    it('moves the tenant back to the free plan when the subscription ends', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.created'))->assertOk();
        stripeWebhook(subscriptionEvent('customer.subscription.deleted', 'canceled', 'evt_2', 1_800_000_100))->assertOk();

        $this->acme->refresh();
        expect($this->acme->plan)->toBe('free')
            ->and($this->acme->subscription_status)->toBe('canceled')
            ->and($this->acme->current_period_end)->toBeNull();
    });

    it('rejects a request with a forged signature', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.updated'), signature: 't='.time().',v1='.str_repeat('0', 64))
            ->assertStatus(400);

        expect($this->acme->refresh()->plan)->toBe('free');
    });

    it('rejects a request without a signature header', function () {
        $this->postJson('/api/stripe/webhook', subscriptionEvent('customer.subscription.updated'))->assertStatus(400);
    });

    it('rejects a correctly signed request that is too old to be fresh (replay)', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.updated'), timestamp: time() - 600)->assertStatus(400);

        expect($this->acme->refresh()->plan)->toBe('free');
    });

    it('applies an event only once, however often Stripe delivers it', function () {
        $event = subscriptionEvent('customer.subscription.updated');
        stripeWebhook($event)->assertOk();
        expect($this->acme->refresh()->plan)->toBe('pro');
        $this->acme->forceFill(['plan' => 'free'])->save();

        stripeWebhook($event)->assertOk();

        expect($this->acme->refresh()->plan)->toBe('free')
            ->and(DB::table('stripe_events')->count())->toBe(1);
    });

    it('ignores an older event that arrives after a newer one', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.deleted', 'canceled', 'evt_new', 1_800_000_500))->assertOk();
        stripeWebhook(subscriptionEvent('customer.subscription.updated', 'active', 'evt_old', 1_800_000_000))->assertOk();

        expect($this->acme->refresh()->plan)->toBe('free');
    });

    it('acknowledges events for unknown customers and unrelated types without changing anything', function () {
        stripeWebhook(subscriptionEvent('customer.subscription.updated', customer: 'cus_unknown'))->assertOk();
        stripeWebhook(['id' => 'evt_9', 'type' => 'invoice.paid', 'created' => 1_800_000_000, 'data' => ['object' => []]])->assertOk();

        expect($this->acme->refresh()->plan)->toBe('free');
    });
});

describe('checkout', function () {
    it('creates a Stripe customer once and returns a checkout link', function () {
        $globex = Tenant::factory()->create(['name' => 'Globex']);
        $owner = User::factory()->for($globex)->create(['role' => Role::Owner]);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_globex']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/billing/checkout')
            ->assertOk()
            ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_test_1');

        expect($globex->refresh()->stripe_customer_id)->toBe('cus_globex');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer sk_test_123')
            && $request['customer'] === 'cus_globex'
            && $request['mode'] === 'subscription'
            && $request['line_items'][0]['price'] === 'price_pro_monthly'
            && $request['client_reference_id'] === (string) $globex->id);
    });

    it('reuses an existing Stripe customer', function () {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_test_2'])]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/billing/checkout')->assertOk();

        Http::assertSentCount(1);
    });

    it('reports a Stripe outage as a bad gateway', function () {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/billing/checkout')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Payment provider unavailable, please try again.');
    });

    it('is only for the owner', function (Role $role) {
        $user = User::factory()->for($this->acme)->create(['role' => $role]);

        $this->actingAs($user, 'sanctum')->postJson('/api/billing/checkout')->assertForbidden();
    })->with([Role::Admin, Role::Member]);
});

describe('plan limits', function () {
    function createProjects(int $count): void
    {
        app(Tenancy::class)->run(test()->acme, function () use ($count) {
            Project::factory()->count($count)->create();
        });
    }

    it('caps the free plan at three projects', function () {
        createProjects(3);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/projects', ['name' => 'Fourth'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The free plan includes up to 3 projects. Upgrade to add more.');
    });

    it('lifts the cap for an active subscription', function () {
        createProjects(3);
        $this->acme->forceFill(['plan' => 'pro', 'subscription_status' => 'active'])->save();

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/projects', ['name' => 'Fourth'])->assertCreated();
    });

    it('applies the cap again when payment is overdue', function () {
        createProjects(3);
        $this->acme->forceFill(['plan' => 'pro', 'subscription_status' => 'past_due'])->save();

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/projects', ['name' => 'Fourth'])->assertForbidden();
    });
});
