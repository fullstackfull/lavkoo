<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * The invented people, shop and address of one GCC market, for a staging storefront (M2-xx, criterion "a seeder creates six storefronts
 * with invented sellers, customers, products, orders and wallets and no real personal data").
 *
 * EVERYTHING HERE IS MADE UP, AND IS MEANT TO LOOK IT. Nothing is a real person's name, number or address, nothing is a real credential,
 * and nothing is derived from production data -- the charter forbids a production copy, so there is nothing real to derive from.
 *
 * Two shapes are deliberate and must survive any edit of this class.
 *
 * 1. A TELEPHONE NUMBER IS NEVER WRITTEN WHOLE. It is assembled at run time from the market's dialling code and a local part that is all
 *    zeros, so no line of this repository holds a string shaped like somebody's mobile. That is not decoration: the repository's own
 *    scanner (scripts/ci/leak-guard.php) refuses an added line carrying a Saudi mobile number, the app repositories' port of it refuses
 *    all six markets', and a number spelled out here -- however invented -- would be a finding in the history for good. An all-zero local
 *    part also cannot be allocated to a subscriber in any of the six markets, so it cannot become somebody's number later.
 *
 * 2. AN E-MAIL ADDRESS IS ALWAYS UNDER A RESERVED DOMAIN. `.test` is reserved by RFC 2606 and can never be registered, so these cannot
 *    reach a mailbox and cannot be mistaken for a person's address by a reader or by the scanner.
 *
 * There is no password here on purpose. See GccStagingSeeder::unreachablePassword().
 */
final class InventedMarketIdentity
{
    /** The international dialling code of each market, without the local part. */
    private const DIALLING = [
        'SA' => '966',
        'AE' => '971',
        'KW' => '965',
        'QA' => '974',
        'BH' => '973',
        'OM' => '968',
    ];

    /**
     * The local part of every invented number: all zeros. No market allocates a subscriber number that opens with a zero after the
     * dialling code, so this can never become a real line, and it matches no mobile pattern any scanner of this programme reads.
     */
    private const INVENTED_LOCAL_LINE = '0000000';

    /** Reserved by RFC 2606: it resolves nowhere and can never be registered. */
    private const RESERVED_DOMAIN = 'gcc-staging.example.test';

    private function __construct(public readonly string $country, public readonly string $currency)
    {
    }

    public static function of(string $country, string $currency): self
    {
        return new self(country: $country, currency: $currency);
    }

    public function sellerName(): string
    {
        return 'Invented Seller ' . $this->country;
    }

    public function customerName(): string
    {
        return 'Invented Customer ' . $this->country;
    }

    public function shopName(): string
    {
        return 'Invented ' . $this->country . ' Storefront';
    }

    public function sellerEmail(): string
    {
        return 'invented-seller-' . strtolower($this->country) . '@' . self::RESERVED_DOMAIN;
    }

    public function customerEmail(): string
    {
        return 'invented-customer-' . strtolower($this->country) . '@' . self::RESERVED_DOMAIN;
    }

    /** The dialling code on its own, as a form would show it beside a number. */
    public function diallingCode(): string
    {
        return '+' . (self::DIALLING[$this->country] ?? '000');
    }

    /**
     * An invented telephone number for this market, assembled rather than written. `$line` tells one invented number of the market from
     * another (the seller's from the customer's); it is not a real numbering plan and is not meant to be read as one.
     */
    public function phone(int $line): string
    {
        return $this->diallingCode() . '-' . self::INVENTED_LOCAL_LINE . str_pad((string) $line, 2, '0', STR_PAD_LEFT);
    }

    /** An address that names no real street, district or building anywhere. */
    public function address(): string
    {
        return 'Invented building 0, No Such Street, Example District, ' . $this->country . ' (staging data, not a real address)';
    }

    /** How a row of this seeder is recognised again, so a second run adds nothing and so an operator can find and delete the lot. */
    public function marker(): string
    {
        return 'gcc-staging-' . strtolower($this->country);
    }

    /** The product code of one invented product of this market. Country-scoped codes are allowed to repeat across markets, never within one. */
    public function productCode(int $number): string
    {
        return 'GCC-STAGING-' . $this->country . '-' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    public function productName(int $number): string
    {
        return 'Invented ' . $this->country . ' Product ' . $number;
    }
}
