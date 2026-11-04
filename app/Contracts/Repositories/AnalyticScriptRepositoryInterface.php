<?php

namespace App\Contracts\Repositories;

interface AnalyticScriptRepositoryInterface extends RepositoryInterface
{

    /**
     * @param array $params
     * @param array $data
     * @return bool
     */
    public function updateOrInsert(array $params, array $data): bool;
}
