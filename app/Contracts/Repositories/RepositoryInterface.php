<?php

namespace App\Contracts\Repositories;

/**
 * What a repository offers: reading and appending (AppendOnlyRepositoryInterface) plus changing and removing a row.
 *
 * A repository whose table is a financial ledger implements AppendOnlyRepositoryInterface instead, and so has no update() or delete()
 * to call.
 */
interface RepositoryInterface extends AppendOnlyRepositoryInterface
{
    /**
     * @param string $id
     * @param array $data Data value must be in key and value pair structure, ex: params = ['name'=>'John Doe']
     * @return bool
     */
    public function update(string $id, array $data): bool;

    /**
     * @param array $params
     * @return bool
     */
    public function delete(array $params): bool;
}
