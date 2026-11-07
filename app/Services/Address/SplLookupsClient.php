<?php

namespace App\Services\Address;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Reads the regions and the cities of Saudi Arabia from Saudi Post's (SPL) National Address Lookups API, version 3.1, with the store
 * owner's own API key. This is the official source of the lists: nothing from it is kept in the software package.
 *
 * It sends GET requests only. The free Starter plan allows five calls a minute and a hundred a week, so the calls are spaced out; a
 * full read is two calls for the regions and two for each of the 13 regions (Arabic and English): 28. The service takes the key in
 * the query string, so every message that leaves this class is cleaned of it first.
 */
class SplLookupsClient
{
    private const LANGUAGES = ['A', 'E'];

    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $baseUrl, private readonly int $pauseSeconds)
    {
    }

    /**
     * @param Closure(string): void|null $progress told what is about to be read
     * @return array{regions: array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>, cities: array<string, array<string, list<array{Id: string, Name: string, GovernorateName: ?string}>>>, calls: int}
     */
    public function read(string $apiKey, ?Closure $progress = null): array
    {
        $calls = 0;
        $call = function (string $operation, array $query, string $label) use (&$calls, $apiKey, $progress): array {
            if ($calls > 0) {
                Sleep::for($this->pauseSeconds)->seconds();
            }
            $calls++;
            if ($progress !== null) {
                $progress($label);
            }

            return $this->get(operation: $operation, query: $query, apiKey: $apiKey);
        };

        $regions = [];
        foreach (self::LANGUAGES as $language) {
            $regions[$language] = $this->rows(payload: $call('regions', ['language' => $language], "regions ({$language})"), listKey: 'Regions', operation: 'regions');
        }

        $cities = [];
        foreach ($regions['A'] as $region) {
            foreach (self::LANGUAGES as $language) {
                $payload = $call('cities', ['regionid' => $region['Id'], 'language' => $language], "cities of region {$region['Id']} ({$language})");
                $cities[$region['Id']][$language] = $this->rows(payload: $payload, listKey: 'Cities', operation: 'cities');
            }
        }

        return ['regions' => $regions, 'cities' => $cities, 'calls' => $calls];
    }

    /** @return array<string, mixed> */
    private function get(string $operation, array $query, string $apiKey): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(rtrim($this->baseUrl, '/') . '/' . $operation, $query + ['format' => 'JSON', 'encode' => 'utf8', 'api_key' => $apiKey]);
        } catch (Throwable $exception) {
            throw new SplLookupsException("The {$operation} request could not be made: " . $this->cleaned($exception->getMessage(), $apiKey));
        }

        if ($response->failed()) {
            throw new SplLookupsException(sprintf('Saudi Post answered %d to the %s request%s.', $response->status(), $operation, $this->meaningOf($response->status())));
        }

        $payload = $this->decoded($response->body());
        if ($payload === null) {
            throw new SplLookupsException("The answer to the {$operation} request is not JSON.");
        }

        $success = $this->valueOf($payload, 'success');
        if ($success === false || $success === 'false' || $success === 0 || $success === '0') {
            $reason = $this->valueOf($payload, 'statusdescription');
            throw new SplLookupsException("Saudi Post reported a failure for the {$operation} request" . (is_string($reason) && $reason !== '' ? ': ' . mb_substr($this->cleaned($reason, $apiKey), 0, 160) : '.'));
        }

        return $payload;
    }

    /** The body as an array; some installations of the service ignore `encode=utf8` and answer in Windows-1256. */
    private function decoded(string $body): ?array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload) && !mb_check_encoding($body, 'UTF-8') && function_exists('iconv')) {
            $converted = iconv('Windows-1256', 'UTF-8//IGNORE', $body);
            $payload = $converted === false ? null : json_decode($converted, true);
        }

        return is_array($payload) ? $payload : null;
    }

    /** @return list<array{Id: string, Name: string, GovernorateName: ?string}> */
    private function rows(array $payload, string $listKey, string $operation): array
    {
        $list = $this->valueOf($payload, $listKey);
        if (!is_array($list)) {
            throw new SplLookupsException("The answer to the {$operation} request has no {$listKey} list.");
        }

        $rows = [];
        foreach ($list as $position => $row) {
            $id = is_array($row) ? $this->valueOf($row, 'Id') : null;
            $name = is_array($row) ? $this->valueOf($row, 'Name') : null;
            if ((!is_string($id) && !is_int($id)) || (string) $id === '' || !is_string($name)) {
                throw new SplLookupsException("Row {$position} of the {$operation} answer has no Id or no Name.");
            }

            $governorate = $this->valueOf($row, 'GovernorateName');
            $rows[] = ['Id' => (string) $id, 'Name' => trim($name), 'GovernorateName' => is_string($governorate) && trim($governorate) !== '' ? trim($governorate) : null];
        }

        return $rows;
    }

    /** The value under a key whatever its letter case, or null. */
    private function valueOf(array $values, string $key): mixed
    {
        foreach ($values as $name => $value) {
            if (is_string($name) && strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function meaningOf(int $status): string
    {
        return match ($status) {
            401 => ' (the API key was refused)',
            403 => ' (the key may not call this API, or the calls of its plan are used up for now)',
            404 => ' (the address of the service is not the one the documentation gives)',
            default => '',
        };
    }

    private function cleaned(string $message, string $apiKey): string
    {
        $message = $apiKey === '' ? $message : str_replace($apiKey, '***', $message);

        return (string) preg_replace('/api_key=[^&\s"\']+/i', 'api_key=***', $message);
    }
}
