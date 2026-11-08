<?php

namespace App\Services\SalesChannels\Listings;

use Illuminate\Support\Facades\DB;

/**
 * A pharmacy's catalogue is not an ordinary one: a medicine, a supplement or a medical device can need a licence, a registration number or a document before a channel may show
 * it, and some categories and brands are closed on some channels altogether.
 *
 * This gate does NOT decide what the rules are — nobody can read them out of the code of a shop. It makes the owner's own list the condition: a product whose category is on
 * `sales_channels.listing.regulated_category_ids`, or whose name matches `regulated_name_patterns`, is never published by anything automatic. A person may publish it, and only
 * by saying so explicitly (`acknowledgedBy`), which is recorded. With both lists empty, which is how they ship, this gate stops nothing.
 */
class RegulatedProductGate
{
    public const NEEDS_A_PERSON = 'regulated_product_needs_a_person';

    /** Why this product may not be published by itself, or null when nothing here stops it. */
    public function refusalFor(int $productId, bool $acknowledgedByAPerson): ?string
    {
        if (!$this->isRegulated($productId)) {
            return null;
        }

        return $acknowledgedByAPerson ? null : self::NEEDS_A_PERSON;
    }

    public function isRegulated(int $productId): bool
    {
        $categories = array_map('strval', (array) config('sales_channels.listing.regulated_category_ids', []));
        $patterns = array_filter((array) config('sales_channels.listing.regulated_name_patterns', []));
        if ($categories === [] && $patterns === []) {
            return false;
        }

        $product = DB::table('products')->where('id', $productId)->first(['id', 'name', 'category_ids']);
        if ($product === null) {
            return false;
        }

        return $this->inARegulatedCategory($product, $categories) || $this->nameMatches((string) ($product->name ?? ''), $patterns);
    }

    /**
     * The platform keeps a product's categories as JSON (`[{"id":"5","position":1}]`), and an id there may be a string or a number; both are compared as text.
     *
     * @param list<string> $regulated
     */
    private function inARegulatedCategory(object $product, array $regulated): bool
    {
        if ($regulated === []) {
            return false;
        }
        $decoded = json_decode((string) ($product->category_ids ?? '[]'), true);
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if ($id !== null && in_array((string) $id, $regulated, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function nameMatches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && mb_stripos($name, (string) $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
