<?php

namespace App\Enums\Fulfillment;

/**
 * Why a fulfillment is `held` instead of dispatched. Held is a safe state: nothing was sent, nothing is
 * lost, and an operator (or a fixed setting) releases it. Values double as translation keys.
 */
final class HoldReason
{
    public const PROVIDER_DISABLED = 'hold_provider_disabled';
    public const PROVIDER_NOT_READY = 'hold_provider_not_ready';
    public const BEFORE_GO_LIVE = 'hold_before_go_live';
    public const MAPPING_MISSING = 'hold_mapping_missing';
    public const MAPPING_NOT_APPROVED = 'hold_mapping_not_approved';
    public const STOCK_UNAVAILABLE = 'hold_stock_unavailable';
    public const MIXED_COD_BLOCKED = 'hold_mixed_cod_blocked';
    public const CURRENCY_UNCONVERTIBLE = 'hold_currency_unconvertible';
    public const CITY_UNMAPPED = 'hold_city_unmapped';
    public const ADDRESS_INCOMPLETE = 'hold_address_incomplete';
    public const PHONE_INVALID = 'hold_phone_invalid';
    public const ACKNOWLEDGEMENT_REQUIRED = 'hold_acknowledgement_required';
    public const MANUAL = 'hold_manual';
}
