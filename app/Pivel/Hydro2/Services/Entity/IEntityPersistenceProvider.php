<?php

namespace Pivel\Hydro2\Services\Entity;

use Pivel\Hydro2\Exceptions\Database\TableNotFoundException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\EntityDefinition;
use Pivel\Hydro2\Models\EntityFieldDefinition;
use Pivel\Hydro2\Models\EntityPersistenceProfile;

interface IEntityPersistenceProvider
{
    public static function GetFriendlyName() : string;

    public function __construct(EntityPersistenceProfile $profile);
    public function __destruct();

    // Profile validation

    /**
     * @return bool Whether a successful connection was opened with the provided profile.
     */
    public function IsProfileValid() : bool;

    // Schema manipulation

    /**
     * @return bool Returns true if the profile given has the permission to create new database schemas, otherwise false.
     *              If the peristence provider doesn't support separate database schemas, returns false.
     */
    public function CanCreateDatabaseSchemas() : bool;
    /**
     * @return string[] Returns a list of names of database schemas. If the provider doesn't support schemas, returns an empty array.
     */
    public function GetDatabaseSchemas() : array;
    /**
     * @return bool Whether the schema was created successfully. If the provider doesn't support schemas, returns false.
     */
    public function CreateDatabaseSchema(string $schemaName) : bool;

    // Collection manipulation

    /**
     * @return bool Whether the given collection exists.
     */
    public function CollectionExists(EntityDefinition $collection) : bool;
    /**
     * Creates the collection if it doesn't already exist.
     * @return bool Whether the collection was successfully created. If the collection already existed, returns true.
     */
    public function CreateCollectionIfNotExists(EntityDefinition $collection) : bool;

    // Entity/data manipulation

    /**
     * @param EntityDefinition $collection The collection definition
     * @param ?Query $query The query object to filter by. If null, will select all rows.
     * 
     * @return array Returns an array of rows of field=>value pairs that match the given query
     * 
     * @throws TableNotFoundException
     */
    public function Select(EntityDefinition $collection, ?Query $query) : array;
    /**
     * @param EntityDefinition $collection The collection definition
     * @param ?Query $query The query object to filter by. If null, will count all rows.
     * 
     * @return int Returns the number of rows that match the given query
     * 
     * @throws TableNotFoundException
     */
    public function Count(EntityDefinition $collection, ?Query $query) : int;
    /**
     * @param EntityDefinition $collection The collection definition
     * @param mixed[] $fieldValues An array of field=>value pairs.
     * 
     * @return ?int If the given collection has a primary key that is auto-generated, returns the new primary key that was generated
     * 
     * @throws TableNotFoundException
     */
    public function Insert(EntityDefinition $collection, array $fieldValues) : ?int;
    // TODO Implement this
    // public function Update(EntityDefinition $collection, array $fieldValues, ?Query $query) : int;
    /**
     * @param EntityDefinition $collection The collection definition. The definition must contain a primary key.
     * @param mixed[] $fieldValues An array of field=>value pairs.
     * 
     * @return ?int If the given collection has a primary key, returns the primary key.
     *              If insert was used, the new primary key will be returned if one was generated.
     * 
     * @throws TableNotFoundException
     */
    public function InsertOrUpdate(EntityDefinition $collection, array $fieldValues) : ?int;
    /**
     * @param EntityDefinition $collection The collection definition. The definition must contain a primary key.
     * @param Query $query The query object to filter by.
     * 
     * @return int The number of rows that were deleted.
     * 
     * @throws TableNotFoundException
     */
    public function Delete(EntityDefinition $collection, Query $query) : int;

    public static function ConvertValueToStorage(EntityFieldDefinition $field, mixed $value): mixed;
    public static function ConvertValueFromStorage(EntityFieldDefinition $field, mixed $value): mixed;
}