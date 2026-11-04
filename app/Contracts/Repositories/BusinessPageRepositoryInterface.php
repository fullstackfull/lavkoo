<?php

namespace App\Contracts\Repositories;

interface BusinessPageRepositoryInterface extends RepositoryInterface
{

    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateWhere(array $params, array $data): bool;
}
