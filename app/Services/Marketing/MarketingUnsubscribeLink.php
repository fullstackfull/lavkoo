<?php

namespace App\Services\Marketing;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * The unsubscribe link an e-mail carries, and how to read one back.
 *
 * TWO INDEPENDENT BARRIERS, because one is not enough for a link that is sent to a stranger and opened without a
 * login:
 *
 *  1. The URL is SIGNED (URL::signedRoute, the same mechanism the abandoned-cart recovery link already uses and the
 *     `signed` middleware verifies). The signature covers the whole URL, so a visitor who holds one valid link cannot
 *     edit any part of it — not the subject, not the channel, not the purpose — and still be let in.
 *  2. The token itself is ENCRYPTED with the application key, so it is opaque. There is no account id and no address
 *     in it to read, and nothing sequential to walk: the next id is not the next token, because there are no ids.
 *
 * What an attacker holding one valid link can therefore do is unsubscribe the person that link was written for, from
 * the one channel and purpose it names. That is what an unsubscribe link is for.
 *
 * THE SIGNATURE DOES NOT EXPIRE, deliberately. People unsubscribe from old mail, and an unsubscribe link that has
 * timed out is a legal problem rather than a security one: the link can only ever REMOVE consent, never add it, so
 * there is nothing for a stale one to be abused for.
 */
final class MarketingUnsubscribeLink
{
    public const ROUTE = 'marketing.unsubscribe';

    public static function forCustomer(int $customerId, string $market, string $channel, string $purpose): string
    {
        return self::url(
            subjectHash: MarketingSubject::forCustomer(customerId: $customerId),
            customerId: $customerId,
            market: $market,
            channel: $channel,
            purpose: $purpose,
        );
    }

    public static function forEmail(string $email, string $market, string $channel, string $purpose): string
    {
        return self::url(
            subjectHash: MarketingSubject::forEmail(email: $email),
            customerId: null,
            market: $market,
            channel: $channel,
            purpose: $purpose,
        );
    }

    /**
     * What a token says, or null when it says nothing this application wrote.
     *
     * The one try/catch in this class, and it has a recovery: a token that was tampered with, truncated by a mail
     * client or encrypted under a previous application key is answered with the same neutral page as any other, so the
     * answer never tells a visitor whether a subject exists.
     */
    public static function read(string $token): ?array
    {
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        try {
            $payload = json_decode(Crypt::decryptString($padded), true);
        } catch (DecryptException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach (['subject_hash', 'market', 'channel', 'purpose'] as $required) {
            if (!isset($payload[$required]) || !is_string($payload[$required]) || $payload[$required] === '') {
                return null;
            }
        }

        if (!in_array($payload['channel'], MarketingConsent::CHANNELS, true) || !in_array($payload['purpose'], MarketingConsent::PURPOSES, true)) {
            return null;
        }

        return [
            'subject_hash' => $payload['subject_hash'],
            'market' => MarketingConsent::market($payload['market']),
            'channel' => $payload['channel'],
            'purpose' => $payload['purpose'],
            'customer_id' => isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
        ];
    }

    private static function url(string $subjectHash, ?int $customerId, string $market, string $channel, string $purpose): string
    {
        $token = Crypt::encryptString((string) json_encode([
            'subject_hash' => $subjectHash,
            'customer_id' => $customerId,
            'market' => MarketingConsent::market($market),
            'channel' => $channel,
            'purpose' => $purpose,
        ]));

        return URL::signedRoute(self::ROUTE, ['token' => rtrim(strtr($token, '+/', '-_'), '=')]);
    }
}
