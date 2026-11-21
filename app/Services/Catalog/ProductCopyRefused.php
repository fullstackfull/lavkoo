<?php

namespace App\Services\Catalog;

use RuntimeException;

/** Why a copy of a product into another country was not made. The message is the one the screen shows. */
final class ProductCopyRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function notAvailable(): self
    {
        return new self(translate('copying_a_product_to_another_country_is_not_available_yet'), 'storefront_country');
    }

    public static function noTarget(): self
    {
        return new self(translate('choose_the_country_to_copy_this_product_into'), 'storefront_country');
    }

    public static function theSourceCountry(string $country): self
    {
        return new self(translate('this_product_is_already_in_that_country') . ': ' . $country, 'storefront_country');
    }
}
