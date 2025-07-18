<?php

namespace Pivel\Hydro2\Services\Entity;

use Countable;
use PHPUnit\SebastianBergmann\Environment\Console;
use Pivel\Hydro2\Extensions\Query;

/**
 * @template T
 */
class EntityCollection implements Countable
{
    private IEntityRepository $_repository;
    private Query $baseQuery;

    public function __construct(IEntityRepository $repository, Query $baseQuery)
    {
        $this->_repository = $repository;
        $this->baseQuery = $baseQuery;
    }

    public function GetRepository(): IEntityRepository
    {
        return $this->_repository;
    }

    /**
     * @return T[]
     */
    public function Read(?Query $query = null): array
    {
        if ($query === null) {
            return $this->_repository->Read($this->baseQuery);
        }

        return $this->_repository->Read($this->baseQuery->And($query));
    }

    /**
     * @param T $entity
     */
    public function Update(object &$entity): bool
    {
        return $this->_repository->Update($entity);
    }

    /**
     * @param T $entity
     */
    public function Create(object &$entity): bool
    {
        return $this->_repository->Create($entity);
    }

    /**
     * @param T $entity
     */
    public function Delete(object &$entity): bool
    {
        return $this->_repository->Delete($entity);
    }

    public function count(): int
    {
        return $this->_repository->Count($this->baseQuery);
    }
}