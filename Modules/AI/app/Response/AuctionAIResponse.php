<?php

namespace Modules\AI\app\Response;

use InvalidArgumentException;
use Modules\AI\app\Services\Auction\AuctionResourceService;

class AuctionAIResponse
{
    public function __construct(
        private readonly AuctionResourceService $auctionResourceService = new AuctionResourceService()
    ) {
    }

    public function title(string $result): string
    {
        return trim($result);
    }

    public function description(string $result): string
    {
        $result = trim($result);

        if (preg_match('/^```[a-zA-Z0-9_-]*\s*(.*?)\s*```$/s', $result, $matches)) {
            return trim($matches[1]);
        }

        return $result;
    }

    public function seo(string $result): array
    {
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        return $data;
    }

    public function generalSetup(string $result): array
    {
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        foreach (['product_type', 'category_name', 'brand_name', 'item_condition'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("The \"{$field}\" field is required.");
            }
        }

        $resources = $this->auctionResourceService->generalSetupData();
        $categoryId = $this->findResourceId((string) $data['category_name'], $resources['categories']);

        if (!$categoryId) {
            throw new InvalidArgumentException('The "category_name" field is invalid.');
        }

        $data['category_id'] = $categoryId;
        $data['brand_id'] = $this->findResourceId((string) $data['brand_name'], $resources['brands']);

        if (!$data['brand_id'] && !empty($resources['brands'])) {
            $data['brand_id'] = array_values($resources['brands'])[0];
        }

        return $data;
    }

    private function findResourceId(string $value, array $resources): ?int
    {
        $normalizedValue = $this->normalizeResourceKey($value);

        if ($normalizedValue === '') {
            return null;
        }

        foreach ($resources as $resourceName => $resourceId) {
            if ($this->normalizeResourceKey((string) $resourceName) === $normalizedValue) {
                return (int) $resourceId;
            }
        }

        return null;
    }

    private function normalizeResourceKey(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    public function shippingPolicy(string $result): array
    {
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        foreach (['shipping_fee', 'return_policy'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("The \"{$field}\" field is required.");
            }
        }

        return $data;
    }

    public function auctionInfo(string $result): array
    {
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        foreach (['entry_fee', 'starting_price', 'minimum_increment_amount', 'maximum_decrement_amount', 'tax_names', 'start_time', 'end_time'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("The \"{$field}\" field is required.");
            }
        }

        foreach (['minimum_increment_amount', 'maximum_decrement_amount'] as $field) {
            if ((float) $data[$field] <= 0) {
                throw new InvalidArgumentException("The \"{$field}\" field must be greater than 0.");
            }
        }

        $taxOptions = $this->auctionResourceService->taxOptions();
        $data['tax_ids'] = collect($data['tax_names'] ?? [])
            ->map(fn ($name) => $taxOptions[strtolower(trim((string) $name))] ?? null)
            ->filter()
            ->values()
            ->all();

        if (empty($data['tax_ids']) && !empty($taxOptions)) {
            $data['tax_ids'] = [array_values($taxOptions)[0]];
        }

        // Normalize "tags": accept array (preferred) or comma-separated string,
        // trim/dedupe/lowercase, expose as both an array and a CSV string so
        // the autofill JS can populate plain inputs and tagsinput widgets.
        $rawTags = $data['tags'] ?? [];
        if (is_string($rawTags)) {
            $rawTags = explode(',', $rawTags);
        }
        $tags = collect(is_array($rawTags) ? $rawTags : [])
            ->map(fn ($t) => trim(strtolower((string) $t)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $data['tags'] = $tags;
        $data['tags_csv'] = implode(', ', $tags);

        return $data;
    }

    public function setup(string $result): array
    {
        return $this->auctionInfo($result);
    }
}
