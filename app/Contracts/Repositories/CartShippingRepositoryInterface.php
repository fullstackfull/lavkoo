<?php

namespace App\Contracts\Repositories;

interface CartShippingRepositoryInterface extends RepositoryInterface
{

    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateWhere(array $params, array $data): bool;

    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateOrInsert(array $params, array $data): bool;

}
