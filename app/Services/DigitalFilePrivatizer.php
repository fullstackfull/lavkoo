<?php

namespace App\Services;

use App\Models\DigitalProductVariation;
use App\Models\OrderDetail;
use App\Utils\PlainFileName;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the digital files that the rows of the shop name and that sit on the public disk to the private disk, so an owner can move them before DIGITAL_FILES_PRIVATE
 * is turned on. A dry run only counts. A copy is written under a temporary name, compared with its source by size and by hash, and only then moved to its name, so a
 * run that stops leaves no half file under the name of a row and goes on where it stopped. A public copy is deleted only when the owner asks for it, and only after the
 * private copy has been compared again. A file that is already on the private disk is left as it is, a file that differs between the disks is left alone and reported,
 * a row that keeps its file on a bucket is not read from the public disk at all, and no row is ever written. A file of the public folder that no row names (what a replaced
 * or a deleted product left behind) is not moved either: it stays reachable by its address, so the report lists it.
 */
class DigitalFilePrivatizer
{
    public const TO_COPY = 'to_copy';

    public const COPIED = 'copied';

    public const ALREADY_COPIED = 'already_copied';

    public const ALREADY_PRIVATE = 'already_private';

    public const MISSING = 'missing';

    public const CONFLICT = 'conflict';

    public const FAILED = 'failed';

    public const REFUSED = 'refused';

    public const BUCKET = 'bucket';

    /** The outcomes whose files the owner has to look at: the names are in the report. */
    private const NEEDING_A_LOOK = [self::MISSING, self::CONFLICT, self::FAILED, self::REFUSED];

    private const PUBLIC_DISK = 'public';

    private const ROWS_PER_QUERY = 500;

    /** How many of the rows that hold a name the report names: enough to find them, not a list of every order line. */
    private const ROWS_NAMED = 3;

    public function __construct(private readonly DigitalProductFileService $digitalFiles)
    {
    }

    /**
     * @return array{outcomes: array<string, array{files: int, rows: int, bytes: int}>, names: array<string, list<string>>, removed: int, unnamed: list<string>}
     *         the files by what happened to them, the names of the ones that need a look, how many public copies were deleted, and the files of the public folder that no row names
     */
    public function run(bool $execute, bool $removePublic): array
    {
        $report = ['outcomes' => [], 'names' => [], 'removed' => 0, 'unnamed' => []];
        $namesHeldByRows = $this->namesHeldByRows();
        foreach ($namesHeldByRows as $name => $held) {
            [$outcome, $bytes, $removed] = $this->handle(name: (string) $name, isLocal: $held['local'], execute: $execute, removePublic: $removePublic);
            $report['outcomes'][$outcome] ??= ['files' => 0, 'rows' => 0, 'bytes' => 0];
            $report['outcomes'][$outcome]['files']++;
            $report['outcomes'][$outcome]['rows'] += $held['rows'];
            $report['outcomes'][$outcome]['bytes'] += $bytes;
            $report['removed'] += (int) $removed;
            if (in_array($outcome, self::NEEDING_A_LOOK, true)) {
                $report['names'][$outcome][] = $name . ' (' . implode(', ', $held['holders']) . ')';
            }
        }
        $report['unnamed'] = $this->filesOfThePublicFolderNoRowNames(namesHeldByRows: $namesHeldByRows);

        return $report;
    }

    /**
     * @return array{0: string, 1: int, 2: bool} what happened to the file, the bytes of its public copy, and whether the public copy was deleted
     */
    private function handle(string $name, bool $isLocal, bool $execute, bool $removePublic): array
    {
        if (!PlainFileName::isPlain($name)) {
            return [self::REFUSED, 0, false];
        }
        if (!$isLocal) {
            return [self::BUCKET, 0, false];
        }

        $path = DigitalProductFileService::DIRECTORY . $name;
        $onPrivate = $this->privateDiskHolds(path: $path);
        if (!Storage::disk(self::PUBLIC_DISK)->fileExists($path)) {
            return [$onPrivate ? self::ALREADY_PRIVATE : self::MISSING, 0, false];
        }

        $bytes = Storage::disk(self::PUBLIC_DISK)->size($path);
        if ($onPrivate && !$this->sameContent(path: $path, privatePath: $path)) {
            return [self::CONFLICT, $bytes, false];
        }
        if (!$execute) {
            return [$onPrivate ? self::ALREADY_COPIED : self::TO_COPY, $bytes, false];
        }
        if (!$onPrivate && !$this->copy(path: $path)) {
            return [self::FAILED, $bytes, false];
        }
        if ($removePublic && !$this->removePublicCopy(path: $path)) {
            return [self::FAILED, $bytes, false];
        }

        return [$onPrivate ? self::ALREADY_COPIED : self::COPIED, $bytes, $removePublic];
    }

    /**
     * Every name the rows hold, once, with how many rows hold it and whether one of them keeps its file locally.
     *
     * @return array<string, array{rows: int, local: bool, holders: list<string>}> `holders` names the first rows, as `table#id`
     */
    private function namesHeldByRows(): array
    {
        $names = [];
        foreach ($this->rowsThatNameAFile() as $row) {
            $names[$row->name] ??= ['rows' => 0, 'local' => false, 'holders' => []];
            $names[$row->name]['rows']++;
            $names[$row->name]['local'] = $names[$row->name]['local'] || !$this->digitalFiles->isOnTheBucket(storageType: $row->storage_type);
            if (count($names[$row->name]['holders']) < self::ROWS_NAMED) {
                $names[$row->name]['holders'][] = $row->source_table . '#' . $row->id;
            }
        }

        return $names;
    }

    /** @return iterable<object{source_table: string, id: int, name: string, storage_type: ?string}> */
    private function rowsThatNameAFile(): iterable
    {
        yield from DB::table('products')
            ->select(['id', 'digital_file_ready as name', 'digital_file_ready_storage_type as storage_type', DB::raw("'products' as source_table")])
            ->where('digital_file_ready', '!=', '')
            ->lazyById(self::ROWS_PER_QUERY);
        yield from $this->rowsOfATableWithAStorageType(table: 'digital_product_variations', column: 'file', model: DigitalProductVariation::class);
        yield from $this->rowsOfATableWithAStorageType(table: 'order_details', column: 'digital_file_after_sell', model: OrderDetail::class);
    }

    /** @return iterable<object{source_table: string, id: int, name: string, storage_type: ?string}> the rows of a table whose storage type is kept in a row of `storages` */
    private function rowsOfATableWithAStorageType(string $table, string $column, string $model): iterable
    {
        yield from DB::table($table . ' as holder')
            ->leftJoin('storages as storage', static fn (JoinClause $join) => $join->on('storage.data_id', '=', 'holder.id')->where('storage.data_type', $model)->where('storage.key', $column))
            ->select(['holder.id as id', 'holder.' . $column . ' as name', 'storage.value as storage_type', DB::raw("'" . $table . "' as source_table")])
            ->where('holder.' . $column, '!=', '')
            ->lazyById(self::ROWS_PER_QUERY, 'holder.id', 'id');
    }

    /**
     * @param array<string, array{rows: int, local: bool}> $namesHeldByRows
     * @return list<string>
     */
    private function filesOfThePublicFolderNoRowNames(array $namesHeldByRows): array
    {
        $named = array_flip(array_map('strval', array_keys($namesHeldByRows)));

        return array_values(array_filter(array_map('basename', Storage::disk(self::PUBLIC_DISK)->files(DigitalProductFileService::DIRECTORY)), static fn (string $name): bool => !isset($named[$name])));
    }

    /** Whether the private disk holds a file. A folder that is not there yet holds none, and is not asked: building a disk makes its folder, which a dry run must not do. */
    private function privateDiskHolds(string $path): bool
    {
        return is_dir((string) config('filesystems.disks.' . DigitalProductFileService::PRIVATE_DISK . '.root'))
            && Storage::disk(DigitalProductFileService::PRIVATE_DISK)->fileExists($path);
    }

    /** Copies a file under a temporary name, checks it, and moves it to its name; a copy that does not check out leaves nothing behind. */
    private function copy(string $path): bool
    {
        $private = Storage::disk(DigitalProductFileService::PRIVATE_DISK);
        $partial = DigitalProductFileService::DIRECTORY . '.' . basename($path) . '.partial';
        $source = Storage::disk(self::PUBLIC_DISK)->readStream($path);
        $written = $private->writeStream($partial, $source);
        fclose($source);

        if ($written && $this->sameContent(path: $path, privatePath: $partial)) {
            return $private->move($partial, $path);
        }
        $private->delete($partial);

        return false;
    }

    /** Deletes the public copy of a file whose private copy still checks out against it. */
    private function removePublicCopy(string $path): bool
    {
        return $this->sameContent(path: $path, privatePath: $path) && Storage::disk(self::PUBLIC_DISK)->delete($path);
    }

    /** Whether the public file at a path and the private file at another have the same size and the same hash. */
    private function sameContent(string $path, string $privatePath): bool
    {
        return $this->fingerprint(disk: self::PUBLIC_DISK, path: $path) === $this->fingerprint(disk: DigitalProductFileService::PRIVATE_DISK, path: $privatePath);
    }

    private function fingerprint(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return Storage::disk($disk)->size($path) . ':' . hash_final($hash);
    }
}
