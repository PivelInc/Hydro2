<?php

namespace Pivel\Hydro2\Models;

use JsonSerializable;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Services\Entity\IEntityPersistenceProvider;
use Pivel\Hydro2\Services\Entity\SqlitePersistenceProvider;
use TypeError;

#[Entity(CollectionName: 'profiles', PersistenceProfile: 'persistence_profile_store')]
class EntityPersistenceProfile implements JsonSerializable
{
    #[EntityField()]
    #[EntityPrimaryKey]
    private string $key;
    /** @var class-string */
    #[EntityField()]
    private string $persistenceProviderClass;
    #[EntityField()]
    private string $hostOrPath;
    #[EntityField(IsNullable: true)]
    private ?string $username;
    #[EntityField(IsNullable: true)]
    private ?string $password;
    #[EntityField(IsNullable: true)]
    private ?string $databaseSchema;

    public function __construct(
        string $key = 'primary',
        string $hostOrPath = '',
        string $persistenceProviderClass = SqlitePersistenceProvider::class,
        ?string $username = null,
        ?string $password = null,
        ?string $databaseSchema = null,
)
    {
        $this->key = $key;
        $this->persistenceProviderClass = $persistenceProviderClass;
        $this->hostOrPath = $hostOrPath;
        $this->username = $username;
        $this->password = $password;
        $this->databaseSchema = $databaseSchema;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'key' => $this->GetKey(),
            'persistenceProviderClass' => (new $this->persistenceProviderClass($this))->GetFriendlyName(),
            'hostOrPath' => $this->hostOrPath,
            'username' => $this->username,
            // Don't return the password.
            'databaseSchema' => $this->databaseSchema,
        ];
    }

    public function GetKey() : string { return $this->key; }
    public function GetPersistenceProvider() : IEntityPersistenceProvider { return new $this->persistenceProviderClass($this); }
    public function GetPersistenceProviderFriendlyName() : string { return (new $this->persistenceProviderClass($this))->GetFriendlyName(); }
    public function GetHostOrPath() : string { return $this->hostOrPath; }
    public function GetUsername() : ?string { return $this->username; }
    public function GetPassword() : ?string { return $this->password; }
    public function GetDatabaseSchema() : ?string { return $this->databaseSchema; }

    /**
     * @param class-string $persistenceProviderClass
     */
    public function SetProfile(
        string $persistenceProviderClass,
        string $hostOrPath,
        ?string $username = null,
        ?string $password = null,
        ?string $databaseSchema = null
    ) : void
    {
        // check that the provided class is of the right type
        if (!is_subclass_of($persistenceProviderClass, IEntityPersistenceProvider::class)) {
            echo $persistenceProviderClass;
            throw new TypeError('Expected persistenceProviderClass to be the name of a class which implements IEntityPersistenceProvider.');
        }
        $this->persistenceProviderClass = $persistenceProviderClass;
        $this->hostOrPath = $hostOrPath;
        $this->username = $username;
        $this->password = $password;
        $this->databaseSchema = $databaseSchema;
    }
}