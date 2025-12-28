<?php

namespace Pivel\Hydro2\Services\Entity;

use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\EntityPersistenceProfile;

interface IEntityService
{
    /**
     * @param class-string<T> $entityClass
     */
    public function GetRepository(string $entityClass): IEntityRepository;

    /**
     * @param class-string<T> $entityClass
     */
    public function GetProvider(string $entityClass): IEntityPersistenceProvider;

    /**
     * Create or update a persistence profile.
     */
    public function SavePersistenceProfile(EntityPersistenceProfile $profile): bool;

    /**
     * @template T
     * @param class-string<T> $entityClass
     * @param Query $query
     * 
     * @return T
     */
    public function Read(string $entityClass, Query $query): object;

    /**
     * @return string[] A list of provider class names which are available
     */
    public function GetAvailableProviders(): array;

    /**
     * @return bool Whether the provided class name is an implemented provider
     */
    public function IsProviderValid(string $providerClass): bool;

    /**
     * @return bool Whether the host in the given profile can be connected to by the provider
     */
    public function IsHostValid(EntityPersistenceProfile $profile): bool;

    /**
     * @return bool Whether the username and password in the given profile can be connected to by the provider
     */
    public function IsUserValid(EntityPersistenceProfile $profile): bool;
}