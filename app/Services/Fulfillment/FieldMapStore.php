<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\FieldMapDetector;
use App\Services\Fulfillment\Support\StoredFieldMap;

/**
 * Persists and verifies the field maps for provider responses that are not documented.
 *
 * A map is only `verified` when an administrator confirmed it against a sample fetched from the real
 * provider; changing any path (or the items path) silently un-verifies it, because the hash it was
 * verified under no longer matches. Capabilities that depend on a map stay switched off until it is
 * verified.
 */
class FieldMapStore
{
    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly FieldMapDetector $detector,
    ) {
    }

    public function get(ProviderCode $provider, string $capability): StoredFieldMap
    {
        $stored = (array) ($this->connections->config($provider)->get('field_maps', [])[$capability] ?? []);
        $paths = (array) ($stored['paths'] ?? []);
        $itemsPath = isset($stored['items_path']) && $stored['items_path'] !== '' ? (string) $stored['items_path'] : null;
        $verifiedAt = $stored['verified_at'] ?? null;
        $verified = $verifiedAt !== null && ($stored['verified_hash'] ?? null) === $this->hash($itemsPath, $paths);

        return new StoredFieldMap(
            itemsPath: $itemsPath,
            paths: $paths,
            verifiedAt: $verifiedAt,
            verified: $verified,
            coverage: $verified ? (array) ($stored['coverage'] ?? []) : [],
        );
    }

    /** @param array<string, string|null> $paths */
    public function save(ProviderCode $provider, string $capability, array $paths, ?string $itemsPath): void
    {
        $maps = (array) $this->connections->config($provider)->get('field_maps', []);
        $paths = array_filter(array_map(static fn ($path): ?string => is_string($path) && trim($path) !== '' ? trim($path) : null, $paths));

        // Saving never carries the previous verification over.
        $maps[$capability] = [
            'items_path' => $itemsPath !== null && trim($itemsPath) !== '' ? trim($itemsPath) : null,
            'paths' => $paths,
            'verified_at' => null,
            'verified_hash' => null,
        ];

        $this->persist($provider, $maps);
    }

    /**
     * Verify the CURRENT stored map against a sample of real records. Every required logical field must
     * resolve in most records (a usable value, or for a nullable field just the key); only then is the map
     * stamped verified. This is a statement about the SHAPE of the response and nothing more: it says nothing
     * about what a field means, and a nullable field may pass without a single filled value, which the stored
     * coverage keeps visible.
     *
     * @param list<array<array-key, mixed>> $sample
     * @param list<string> $required
     * @param list<string> $nullable the required fields that only have to be present in the records, not filled
     * @return list<string> the required fields that did not resolve (empty = verified)
     */
    public function verify(ProviderCode $provider, string $capability, array $sample, array $required, array $nullable = []): array
    {
        $stored = $this->get($provider, $capability);
        $problems = $this->detector->unresolved($stored->map(), $sample, $required, $nullable);
        if ($problems !== []) {
            return $problems;
        }

        $maps = (array) $this->connections->config($provider)->get('field_maps', []);
        $maps[$capability]['verified_at'] = now()->toDateTimeString();
        $maps[$capability]['verified_hash'] = $this->hash($stored->itemsPath, $stored->paths);
        $maps[$capability]['coverage'] = $this->detector->coverage($stored->map(), $sample, $nullable);
        $this->persist($provider, $maps);

        return [];
    }

    /**
     * @param list<array<array-key, mixed>> $sample
     * @param array<string, list<string>> $candidates
     * @param list<string> $nullable
     * @return array<string, string>
     */
    public function propose(array $sample, array $candidates, array $nullable = []): array
    {
        return $this->detector->detect($sample, $candidates, $nullable);
    }

    /** @param list<array<array-key, mixed>>|null $items */
    public function itemsFrom(array $response, StoredFieldMap $stored): ?array
    {
        return FieldMap::extractItems($response, $stored->itemsPath);
    }

    /** @param array<string, mixed> $maps */
    private function persist(ProviderCode $provider, array $maps): void
    {
        $this->connections->save(
            provider: $provider,
            enabled: null,
            baseUrl: null,
            settings: ['field_maps' => $maps],
        );
    }

    /** @param array<string, string|null> $paths */
    private function hash(?string $itemsPath, array $paths): string
    {
        ksort($paths);

        return hash('sha256', json_encode([$itemsPath, $paths], JSON_THROW_ON_ERROR));
    }
}
