<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    /**
     * Find a model by its primary key
     */
    public function find(int $id): ?Model;

    /**
     * Find a model by its primary key or throw an exception
     */
    public function findOrFail(int $id): Model;

    /**
     * Get all models
     */
    public function all(array $columns = ['*']): Collection;

    /**
     * Create a new model
     */
    public function create(array $data): Model;

    /**
     * Update a model
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete a model
     */
    public function delete(int $id): bool;

    /**
     * Paginate results
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator;

    /**
     * Find models by specific criteria
     */
    public function findWhere(array $criteria): Collection;

    /**
     * Count records matching criteria
     */
    public function count(array $criteria = []): int;
}
