<?php

namespace App\Services\Fulfillment\Support;

final readonly class StoredFieldMap
{
    /** @param array<string, string|null> $paths */
    public function __construct(
        public ?string $itemsPath,
        public array $paths,
        public ?string $verifiedAt,
        public bool $verified,
    ) {
    }

    public function map(): FieldMap
    {
        return new FieldMap($this->paths);
    }
}
