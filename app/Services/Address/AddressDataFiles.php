<?php

namespace App\Services\Address;

use Illuminate\Support\Carbon;

/**
 * The region, city and alias data files that ship with the application, described without loading them into the database: what each
 * is called, where its rows come from, the date of that source and how many rows it holds. This is what the administrator is about to
 * import, shown before the preview.
 */
class AddressDataFiles
{
    private const KINDS = ['regions', 'cities', 'aliases'];

    /** @return array<string, array{file: string, readable: bool, source: string|null, snapshot_date: string|null, rows: int}> by kind of list */
    public function describe(): array
    {
        $files = [];
        foreach (self::KINDS as $kind) {
            $path = (string) config('address.seed.' . $kind);
            $document = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            $document = is_array($document) ? $document : null;

            $files[$kind] = [
                'file' => basename($path),
                'readable' => $document !== null,
                'source' => $document['_meta']['source'] ?? null,
                'snapshot_date' => $this->date($document['_meta']['snapshot_date'] ?? $document['_meta']['prepared'] ?? null),
                'rows' => is_array($document[$kind] ?? null) ? count($document[$kind]) : 0,
            ];
        }

        return $files;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Carbon::parse($value)->toDateString() : null;
    }
}
