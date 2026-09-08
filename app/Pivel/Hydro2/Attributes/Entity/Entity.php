<?php

namespace Pivel\Hydro2\Attributes\Entity;

use Attribute;
use Pivel\Hydro2\Services\Entity\EntityRepository;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    /**
     * @param string $CollectionName The name of the table to use in the database
     * @param class-string<TRepository> $RepositoryClass The repository class to use
     * @param string $PersistenceProfile The name of the persistence profile to use. An error will be thrown if the profile doesn't exist.
     */
    public function __construct(
        public string $CollectionName,
        public string $RepositoryClass = EntityRepository::class,
        public string $PersistenceProfile = 'primary',
        public bool $Extendable = false,
    ) {
    }
}