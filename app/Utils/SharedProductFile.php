<?php

namespace App\Utils;

use App\Models\DigitalProductVariation;
use App\Models\Product;
use App\Models\ProductSeo;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * A product names its files in its own row (gallery, thumbnail, preview, meta image, digital file), and a name is only a claim: a copy of a
 * product shares the files of its original, and the add form and the seller apps post names that a seller can take from the storefront or
 * write with the folder in front of them (`thumbnail/x.webp`). A file that more than one product holds is not deleted when one of them lets
 * go of it, so a seller who adds a product holding another seller's file names cannot delete those files by removing them again.
 *
 * Every caller deletes before the product's own row changes, so the product that lets go of the file still holds it and counts once.
 *
 * @phpstan-type File array{relative: string, name: string, folder: string, column: ?string}
 */
final class SharedProductFile
{
    private const COLUMN_OF_FOLDER = [
        'thumbnail' => 'thumbnail',
        'preview' => 'preview_file',
        'meta' => 'meta_image',
        'digital-product' => 'digital_file_ready',
    ];

    /** The products read from the database for each statement: the search stops at the second holder of every file, so it rarely needs a second one. */
    private const CANDIDATES_PER_STATEMENT = 100;

    /** Whether the file at a path of the disk (`product/x.webp`, `/product/thumbnail/x.webp`) is held by more than one product. */
    public static function isShared(string $path): bool
    {
        return self::sharedAmong(paths: [$path]) !== [];
    }

    /**
     * The same question for several files, answered with one search of the products instead of one for each file.
     *
     * @param array<int, string> $paths
     * @return list<string> the paths of the list whose file more than one product holds
     */
    public static function sharedAmong(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $file = self::fileAt(path: $path);
            if ($file !== null) {
                $files[$path] = $file;
            }
        }

        return array_keys(array_filter(self::holdersOf(files: $files), static fn (array $holderIds): bool => count($holderIds) > 1));
    }

    /** @return File|null the file a path names, or nothing when the path is no file of `product/` (a folder of it is none) */
    private static function fileAt(string $path): ?array
    {
        $normalised = self::normalised(path: $path);
        $relative = substr($normalised, strlen('product/'));
        if (!str_starts_with($normalised, 'product/') || isset(self::COLUMN_OF_FOLDER[$relative])) {
            return null;
        }

        $folder = preg_match('#^(' . implode('|', array_keys(self::COLUMN_OF_FOLDER)) . ')/[^/]+$#D', $relative, $match) === 1 ? $match[1] : '';

        return ['relative' => $relative, 'name' => basename($relative), 'folder' => $folder, 'column' => self::COLUMN_OF_FOLDER[$folder] ?? null];
    }

    /** A path the way the disk reads it: a backslash separates like a slash, and an empty segment or a `.` is nothing. */
    private static function normalised(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * @param array<string, File> $files by path
     * @return array<string, array<int|string, true>> the products that hold each file, by path
     */
    private static function holdersOf(array $files): array
    {
        $holders = array_fill_keys(array_keys($files), []);
        if ($files === []) {
            return $holders;
        }

        foreach (self::candidates(files: $files) as $row) {
            $entries = self::entriesOf(row: $row);
            foreach ($files as $path => $file) {
                if (self::holds(row: $row, entries: $entries, file: $file)) {
                    $holders[$path][$row->id] = true;
                }
            }
            if (min(array_map('count', $holders)) > 1) {
                return $holders;
            }
        }

        foreach ($files as $path => $file) {
            foreach (self::otherTablesHolding(file: $file) as $productId) {
                $holders[$path][$productId] = true;
            }
        }

        return $holders;
    }

    /**
     * The rows of the products that may hold one of the files, as plain rows with only the columns the check reads, a few at a time.
     *
     * @param array<string, File> $files
     * @return LazyCollection<int, object>
     */
    private static function candidates(array $files): LazyCollection
    {
        $columns = array_values(array_unique(array_filter(array_column($files, 'column'))));

        return Product::withoutGlobalScopes()
            ->toBase()
            ->select(['id', 'images', 'color_image', ...$columns])
            ->where(static function ($query) use ($files) {
                $like = self::likeOperator(connection: $query->getConnection());
                foreach ($files as $file) {
                    foreach (self::jsonSpellings(name: $file['name']) as $spelling) {
                        $pattern = self::likeContaining(text: $spelling);
                        $query->orWhereRaw("images {$like} ? escape '!'", [$pattern])->orWhereRaw("color_image {$like} ? escape '!'", [$pattern]);
                    }
                    if ($file['column'] !== null) {
                        $query->orWhere($file['column'], $file['name']);
                    }
                }
            })
            ->lazyById(chunkSize: self::CANDIDATES_PER_STATEMENT);
    }

    /** @return list<string> the names in the gallery and the colour images of a row, the way the disk reads them */
    private static function entriesOf(object $row): array
    {
        $entries = [];
        foreach ([$row->images, $row->color_image] as $list) {
            foreach ((array) json_decode((string) $list, true) as $image) {
                $imageName = is_array($image) ? ($image['image_name'] ?? null) : $image;
                if (is_string($imageName)) {
                    $entries[] = self::normalised(path: $imageName);
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $entries
     * @param File $file
     */
    private static function holds(object $row, array $entries, array $file): bool
    {
        return ($file['column'] !== null && $row->{$file['column']} === $file['name']) || in_array($file['relative'], $entries, true);
    }

    /**
     * @param File $file
     * @return iterable<int|string> the products whose SEO row or digital variation holds the file (a deleted product leaves both rows behind and holds nothing)
     */
    private static function otherTablesHolding(array $file): iterable
    {
        return match ($file['folder']) {
            'meta' => ProductSeo::query()->where('image', $file['name'])->whereIn('product_id', self::existingProductIds())->pluck('product_id'),
            'digital-product' => DigitalProductVariation::query()->where('file', $file['name'])->whereIn('product_id', self::existingProductIds())->pluck('product_id'),
            default => [],
        };
    }

    private static function existingProductIds(): Builder
    {
        return Product::withoutGlobalScopes()->select('id');
    }

    /** @return list<string> the name as a JSON text holds it: as it is, and as json_encode() escapes it (letters that are not ASCII, quotes, backslashes) */
    private static function jsonSpellings(string $name): array
    {
        $escaped = substr((string) json_encode($name), 1, -1);

        return array_values(array_unique(array_filter([$name, $escaped], static fn (string $spelling): bool => $spelling !== '')));
    }

    /** The rows are checked exactly afterwards, so the case-insensitive collation of the columns only slows the search down: MySQL compares bytes, several times faster. */
    private static function likeOperator(ConnectionInterface $connection): string
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'like binary' : 'like';
    }

    /** A pattern for `like ... escape '!'` that finds the text anywhere: the escape character, `%` and `_` stand for themselves, and a backslash is no escape. */
    private static function likeContaining(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }
}
