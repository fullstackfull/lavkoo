<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Model;

interface VendorWalletRepositoryInterface extends RepositoryInterface
{
    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateWhere(array $params, array $data): bool;

    /**
     * Reads the first matching wallet and holds a row lock on it until the surrounding transaction ends.
     *
     * @param array $params
     * @return Model|null
     */
    public function getFirstWhereForUpdate(array $params): ?Model;
}
