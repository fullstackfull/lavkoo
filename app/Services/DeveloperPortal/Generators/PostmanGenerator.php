<?php

namespace App\Services\DeveloperPortal\Generators;

use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\Support\RuleTranslator;

/**
 * A Postman collection, generated from the manifest.
 *
 * Same principle as the OpenAPI document and the same payoff: a developer imports this, fills in
 * one token variable and starts making real calls, without anybody having curated a collection
 * that stopped matching the API three releases ago.
 *
 * Two things are deliberately not in it. Real credentials — the token is a collection variable the
 * importer fills in, never a value baked into a file that gets shared around. And real record ids
 * — path parameters become {{product_id}} style variables rather than an id lifted from this
 * shop's database.
 */
class PostmanGenerator
{
    public function __construct(
        private readonly ApiManifest $manifest,
        private readonly RuleTranslator $translator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(array $filters = []): array
    {
        $manifest = $this->manifest->get();
        $endpoints = array_filter(
            $this->manifest->endpoints($filters),
            static fn (array $endpoint) => $endpoint['surface'] === 'api',
        );

        return [
            'info' => [
                // ?audience[]=seller reaches here as an array, and concatenating one is a PHP
                // notice and a broken name; only a plain string narrows the collection's title.
                'name' => config('app.name') . ' API'
                    . (is_string($filters['audience'] ?? null) && $filters['audience'] !== ''
                        ? ' — ' . str_replace('_', ' ', $filters['audience'])
                        : ''),
                'description' => 'Generated from the live route table on ' . ($manifest['generated_at'] ?? 'unknown')
                    . '. Set base_url and the token variable for the API you are calling, then send.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => array_merge(
                [
                    ['key' => 'base_url', 'value' => $manifest['base_url'] ?: url('/'), 'type' => 'string'],
                    ['key' => 'customer_token', 'value' => '', 'type' => 'string'],
                    ['key' => 'vendor_token', 'value' => '', 'type' => 'string'],
                    ['key' => 'delivery_token', 'value' => '', 'type' => 'string'],
                ],
                // Every {{id}} the requests below reference. Emitting the references without
                // declaring them left most of the collection importing with unresolved variables,
                // so the first send went to a literal "{{id}}" path.
                $this->pathVariables($endpoints),
            ),
            'item' => $this->folders($endpoints),
        ];
    }

    /**
     * The path parameters used anywhere in this collection, declared once each.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<int, array<string, string>>
     */
    private function pathVariables(array $endpoints): array
    {
        $names = [];

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['path_parameters'] ?? [] as $parameter) {
                $names[(string) $parameter['name']] = true;
            }
        }

        ksort($names);

        return array_map(
            static fn (string $name) => ['key' => $name, 'value' => '', 'type' => 'string'],
            array_keys($names),
        );
    }

    public function toJson(array $filters = []): string
    {
        return (string) json_encode($this->generate($filters), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Folders that mirror the portal's own grouping, so a collection and the documentation are
     * navigated the same way.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<int, array<string, mixed>>
     */
    private function folders(array $endpoints): array
    {
        $tree = [];

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['methods'] as $method) {
                $tree[$endpoint['audience']][$endpoint['group']][] = $this->request($endpoint, $method);
            }
        }

        ksort($tree);
        $folders = [];

        foreach ($tree as $audience => $groups) {
            ksort($groups);
            $children = [];

            foreach ($groups as $group => $requests) {
                $children[] = [
                    'name' => ucfirst(str_replace('_', ' ', (string) $group)),
                    'item' => $requests,
                ];
            }

            $folders[] = [
                'name' => ucwords(str_replace('_', ' ', (string) $audience)),
                'item' => $children,
            ];
        }

        return $folders;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function request(array $endpoint, string $method): array
    {
        $isQuery = $method === 'GET';
        $headers = [['key' => 'Accept', 'value' => 'application/json']];

        $token = match ($endpoint['auth']['mechanism'] ?? '') {
            'passport', 'sanctum' => 'customer_token',
            'seller_token' => 'vendor_token',
            'delivery_token' => 'delivery_token',
            default => ($endpoint['auth']['optional_auth'] ?? false) ? 'customer_token' : null,
        };

        if ($token !== null) {
            $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{' . $token . '}}'];
        }

        if (!$isQuery && $endpoint['body'] !== [] && !$this->hasFile($endpoint)) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => $this->url($endpoint, $isQuery),
        ];

        if (!$isQuery && $endpoint['body'] !== []) {
            $request['body'] = $this->body($endpoint);
        }

        return array_filter([
            'name' => $endpoint['summary'] ?: $endpoint['path'],
            'request' => $request + array_filter([
                'description' => $this->describe($endpoint),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function url(array $endpoint, bool $isQuery): array
    {
        // Path parameters become collection variables rather than an id from this database, so a
        // shared collection never leaks a real record and never 404s on somebody else's install.
        $path = $endpoint['path'];

        foreach ($endpoint['path_parameters'] as $parameter) {
            $path = str_replace(
                ['{' . $parameter['name'] . '?}', '{' . $parameter['name'] . '}'],
                '{{' . $parameter['name'] . '}}',
                $path,
            );
        }

        $url = [
            'raw' => '{{base_url}}' . $path,
            'host' => ['{{base_url}}'],
            'path' => array_values(array_filter(explode('/', trim($path, '/')))),
        ];

        if ($isQuery && $endpoint['body'] !== []) {
            $url['query'] = array_map(fn (array $field) => [
                'key' => $field['name'],
                'value' => (string) $this->translator->example($field),
                'disabled' => empty($field['required']),
            ], $endpoint['body']);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function body(array $endpoint): array
    {
        if ($this->hasFile($endpoint)) {
            return [
                'mode' => 'formdata',
                'formdata' => array_map(fn (array $field) => array_filter([
                    'key' => $field['name'],
                    'type' => ($field['type'] ?? null) === 'file' ? 'file' : 'text',
                    'value' => ($field['type'] ?? null) === 'file' ? null : (string) $this->translator->example($field),
                    'disabled' => empty($field['required']),
                ], static fn ($value) => $value !== null), $endpoint['body']),
            ];
        }

        $example = [];

        foreach ($endpoint['body'] as $field) {
            $example[$field['name']] = $this->translator->example($field);
        }

        return [
            'mode' => 'raw',
            'raw' => (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    private function hasFile(array $endpoint): bool
    {
        foreach ($endpoint['body'] as $field) {
            if (($field['type'] ?? null) === 'file') {
                return true;
            }
        }

        return false;
    }

    private function describe(array $endpoint): ?string
    {
        $lines = array_filter([
            $endpoint['description'],
            $endpoint['auth']['note'] ?? null,
            $endpoint['permissions'] !== [] ? 'Requires: ' . implode(', ', $endpoint['permissions']) : null,
            ($endpoint['rate_limit']['requests'] ?? null) !== null
                ? 'Rate limit: ' . $endpoint['rate_limit']['requests'] . ' per ' . $endpoint['rate_limit']['minutes'] . ' minute(s).'
                : null,
            $endpoint['deprecated'] ? 'DEPRECATED' . ($endpoint['replaced_by'] ? ' — use ' . $endpoint['replaced_by'] : '') : null,
        ]);

        return $lines === [] ? null : implode("\n\n", $lines);
    }
}
