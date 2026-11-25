<?php

namespace App\Billing;

/**
 * Verifies the Stripe-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "t.payload">".
 *
 * @see https://docs.stripe.com/webhooks#verify-manually
 */
class StripeSignature
{
    /** Seconds a signed request stays valid; older ones are treated as replays. Stripe's libraries use 300. */
    public const TOLERANCE = 300;

    public static function verify(string $payload, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        // Stripe sends several v1 signatures while a secret is being rolled.
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
