<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Services\SalesChannels\Data\ListingRequirements;

/**
 * Turns one Trendyol category's attribute list into what the channel requires of a product in it.
 *
 * Trendyol answers with `categoryAttributes[]`, each naming an `attribute` and saying whether it is `required`, whether it `allowCustom` (a value of our own is accepted) and
 * whether it `allowMultipleAttributeValues`. Where the list of acceptable values is short Trendyol embeds it as `categoryAttributeValues[]`; a very long list has its own
 * endpoint, which this version does not page through — so an attribute whose values Trendyol did not embed is recorded as required WITHOUT a value list rather than with an
 * empty one, because an empty list would read as "no value is acceptable" and refuse every product.
 *
 * The attribute's NAME is what is kept, not its id: the rest of this module speaks in channel field names (`ListingRequirements::required`), and a screen that showed `338`
 * would be asking a person to guess. The ids are kept beside the names, because a create call sends ids.
 */
class TrendyolCategoryMapper
{
    /**
     * @param array<string, mixed> $response the body of the category-attributes call
     */
    public function map(array $response, string $categoryId): ListingRequirements
    {
        $required = [];
        $optional = [];
        $allowedValues = [];
        $ids = [];

        foreach ((array) ($response['categoryAttributes'] ?? []) as $entry) {
            $name = trim((string) (($entry['attribute']['name'] ?? '')));
            $attributeId = $entry['attribute']['id'] ?? null;
            if ($name === '') {
                continue;
            }

            if ((bool) ($entry['required'] ?? false)) {
                $required[] = $name;
            } else {
                $optional[] = $name;
            }

            $values = [];
            foreach ((array) ($entry['categoryAttributeValues'] ?? []) as $value) {
                $valueName = trim((string) ($value['name'] ?? ''));
                if ($valueName !== '') {
                    $values[] = $valueName;
                }
            }
            // Only where Trendyol really listed values. An attribute that takes a value of our own (`allowCustom`) is not limited to a list either, so no list is recorded
            // for it: a check against one would refuse a value Trendyol would have taken.
            if ($values !== [] && !(bool) ($entry['allowCustom'] ?? false)) {
                $allowedValues[$name] = array_values(array_unique($values));
            }

            if ($attributeId !== null) {
                $ids[$name] = [
                    'attribute_id' => (int) $attributeId,
                    'values' => $this->valueIds((array) ($entry['categoryAttributeValues'] ?? [])),
                    'allow_custom' => (bool) ($entry['allowCustom'] ?? false),
                    'allow_many' => (bool) ($entry['allowMultipleAttributeValues'] ?? false),
                    'varianter' => (bool) ($entry['varianter'] ?? false),
                ];
            }
        }

        if ($required === [] && $optional === []) {
            // A category that named no attribute at all tells us nothing, and "nothing is required" is the one conclusion that must never be drawn from silence.
            return ListingRequirements::unknown($categoryId);
        }

        return new ListingRequirements(
            productType: $categoryId,
            required: array_values(array_unique($required)),
            optional: array_values(array_unique($optional)),
            allowedValues: $allowedValues,
            readAt: now(),
            channelFields: $ids,
        );
    }

    /**
     * @param list<array<string, mixed>> $values
     * @return array<string, int> value name => Trendyol's id for it, which is what a create call sends
     */
    private function valueIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $name = trim((string) ($value['name'] ?? ''));
            if ($name !== '' && isset($value['id'])) {
                $ids[$name] = (int) $value['id'];
            }
        }

        return $ids;
    }
}
