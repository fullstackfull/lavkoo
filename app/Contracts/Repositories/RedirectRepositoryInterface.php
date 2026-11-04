<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;

interface RedirectRepositoryInterface extends RepositoryInterface
{
    /** Active rules as plain arrays, shaped for the resolver/middleware. */
    public function getActiveRulesArray(): array;

    /** Update a redirect matched by arbitrary params. */
    public function updateByParams(array $params, array $data): bool;

    /** Whether a redirect with the given from_path already exists (optionally excluding an id). */
    public function existsForFromPath(string $fromPath, ?int $exceptId = null): bool;
}
