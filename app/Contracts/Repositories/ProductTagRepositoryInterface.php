<?php

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;

interface ProductTagRepositoryInterface extends RepositoryInterface
{

    /**
     * @param string $fieldName
     * @param array $filters
     * @return Collection|array
     */
    public function getIds(string $fieldName='tag_id', array $filters = []): Collection|array;
}
