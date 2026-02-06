<?php

namespace Pivel\Hydro2\Services\Entity;

use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Services\ILoggerService;

/**
 * @template TEntity
 */
interface IEntityRepository
{
    /**
     * @param IEntityPersistenceProvider $provider
     * @param class-string<TEntity> $entityClass
     */
    public function __construct(IEntityService $entityService, IEntityPersistenceProvider $provider, ILoggerService $logger, string $entityClass);

    /**
     * Creates the collection/table for this entity if it does not already exist.
     * @return bool Whether the creation was successful
     */
    public function CreateCollection() : bool;

    /**
     * @param ?Query $query If not provided, will return all entities
     * @return TEntity[]
     */
    public function Read(?Query $query = null) : array;

    /**
     * @param mixed $id The value of the entity's primary key to match
     * @return ?TEntity
     */
    public function ReadById(mixed $id) : ?object;

    /**
     * @param object &$entity The entity to apply field values to
     * @return bool Whether reading and applying values was successful
     */
    public function ReadByIdIntoSubEntity(object &$entity, $id) : bool;

    /**
     * @param ?Query $query If not provided, will count the total number of entities
     * @return int The number of entities that match the given query
     */
    public function Count(?Query $query = null) : int;

    /**
     * @param TEntity $entity
     * @return bool Whther the creation was successful
     * @throws TypeError If the provided entity is not of type TEntity
     */
    public function Create(object &$entity) : bool;

    /**
     * Updates the entity in the database (using the entity's id). If it doesn't exist, create it instead (the entity's id will be overridden if a new one was set).
     * @param TEntity $entity
     * @return bool Whether the update was successful
     * @throws TypeError If the provided entity is not of type TEntity
     */
    public function Update(object &$entity) : bool;

    /**
     * @param TEntity $entity
     * @return bool Whether the deletion was successful
     * @throws TypeError If the provided entity is not of type TEntity
     */
    public function Delete(object $entity) : bool;
}