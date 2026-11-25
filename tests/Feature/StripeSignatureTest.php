<?php

use App\Billing\StripeSignature;

const PAYLOAD = '{"id":"evt_1"}';
const SECRET = 'whsec_abc';
const NOW = 1_800_000_000;

function sign(int $time = NOW, string $secret = SECRET): string
{
    return hash_hmac('sha256', $time.'.'.PAYLOAD, $secret);
}

it('accepts a valid signature', function () {
    expect(StripeSignature::verify(PAYLOAD, 't='.NOW.',v1='.sign(), SECRET, NOW))->toBeTrue();
});

it('accepts any matching v1 signature while a secret is being rolled', function () {
    $header = 't='.NOW.',v1='.sign(secret: 'whsec_old').',v1='.sign();

    expect(StripeSignature::verify(PAYLOAD, $header, SECRET, NOW))->toBeTrue();
});

it('rejects', function (?string $header, string $secret = SECRET, int $now = NOW, string $payload = PAYLOAD) {
    expect(StripeSignature::verify($payload, $header, $secret, $now))->toBeFalse();
})->with([
    'no header' => [null],
    'empty secret on our side' => ['t='.NOW.',v1='.hash_hmac('sha256', NOW.'.'.PAYLOAD, ''), ''],
    'no timestamp' => ['v1='.sign()],
    'non-numeric timestamp' => ['t=soon,v1='.sign()],
    'no v1 signature' => ['t='.NOW.',v0='.sign()],
    'garbage' => ['nonsense'],
    'wrong secret' => ['t='.NOW.',v1='.sign(secret: 'whsec_wrong')],
    'tampered payload' => ['t='.NOW.',v1='.sign(), SECRET, NOW, '{"id":"evt_2"}'],
    'older than 5 minutes' => ['t='.NOW.',v1='.sign(), SECRET, NOW + 301],
    'more than 5 minutes in the future' => ['t='.NOW.',v1='.sign(), SECRET, NOW - 301],
]);

it('still accepts a request at the edge of the tolerance', function () {
    expect(StripeSignature::verify(PAYLOAD, 't='.NOW.',v1='.sign(), SECRET, NOW + 300))->toBeTrue();
});
