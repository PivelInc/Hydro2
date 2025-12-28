<?php

namespace Pivel\Hydro2\Services\Entity;

use Exception;
use PDOException;
use PHPUnit\Util\Json;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Exceptions\Database\HostNotFoundException;
use Pivel\Hydro2\Exceptions\Database\InvalidUserException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\EntityPersistenceProfile;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\PackageManifestService;
use ReflectionClass;

class EntityService implements IEntityService
{
    private Hydro2 $_app;
    private ILoggerService $_logger;
    private PackageManifestService $_manifestService;
    private IEntityRepository $_persistenceProfileRepository;

    public function __construct(
        Hydro2 $app,
        ILoggerService $logger,
        PackageManifestService $manifestService,
    ) {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_manifestService = $manifestService;
        //$this->_logger->Debug('Pivel/Hydro2', 'Starting entity service...');

        $profile = new EntityPersistenceProfile(
            'persistence_profile_store',
            $this->_app->MainAppDir . DIRECTORY_SEPARATOR . 'persistenceprofiles.json',
            JsonPersistenceProvider::class
        );
        $provider = $profile->GetPersistenceProvider();
        $this->_persistenceProfileRepository = new EntityRepository($this, $provider, $this->_logger, EntityPersistenceProfile::class);
        
        // write this profile if it isn't already persisted.
        // It is not possible to modify this profile since it is hard-coded above, and if changes are made to it they will not be resepected.
        // TODO unsure how to allow this to be modified. All preferences are loaded using the EntityService, so we can't load preferences before
        //      the EntityService is loaded in the first place. A chicken/egg problem.
        if ($this->_persistenceProfileRepository->Read((new Query())->Equal('key', 'persistence_profile_store')) == null) {
            $this->_persistenceProfileRepository->Create($profile);
        }

        //$this->_logger->Debug('Pivel/Hydro2', 'Entity service constructed.');
    }

    /** @var IEntityRepository[] */
    private array $entityRepositories = [];
    /** @var IEntityPersistenceProvider[] */
    private array $entityPersistenceProviders = [];

    /**
     * @param class-string<T> $entityClass
     */
    public function GetRepository(string $entityClass): IEntityRepository
    {
        // determine the entityClass' provider, instatiate the repository, and return it. Repositories are singletons for each entity class.
        if (!isset($this->entityRepositories[$entityClass])) {
            $provider = $this->GetProvider($entityClass);
            $this->entityRepositories[$entityClass] = new EntityRepository($this, $provider, $this->_logger, $entityClass);
        }
        
        return $this->entityRepositories[$entityClass];
    }

    /**
     * @param class-string<T> $entityClass
     */
    public function GetProvider(string $entityClass): IEntityPersistenceProvider
    {
        // determine the entityClass' provider, instatiate it, and return it. Providers are singletons for each entity class.
        $profile = $this->GetPersistenceProfile($entityClass);
        $profileKey = $profile->GetKey();
        if (!isset($this->entityPersistenceProviders[$profileKey])) {
            $this->entityPersistenceProviders[$profileKey] = $profile->GetPersistenceProvider();
        }

        //$this->_logger->Debug('Pivel/Hydro2', "Getting persistence provider '{$this->entityPersistenceProviders[$profileKey]::GetFriendlyName()}' using profile '{$profileKey}' for entity class '{$entityClass}'");
        
        return $this->entityPersistenceProviders[$profileKey];
    }

    /**
     * @param class-string<T> $entityClass
     */
    private function GetPersistenceProfile(string $entityClass): EntityPersistenceProfile
    {
        // get entity
        $rc = new ReflectionClass($entityClass);
        $attrs = $rc->getAttributes(Entity::class);
        $profileKey = 'primary';
        if (count($attrs) == 1) {
            $profileKey = $attrs[0]->newInstance()->PersistenceProfile;
        }

        /** @var null|EntityPersistenceProfile|EntityPersistenceProfile[] */
        $profiles = $this->_persistenceProfileRepository->Read((new Query())->Equal('key', $profileKey));

        // if the profile wasn't found, create a new profile, by default using an Sqlite database.
        if (count($profiles) != 1) {
            $profiles[0] = new EntityPersistenceProfile(
                $profileKey,
                $this->_app->MainAppDir . DIRECTORY_SEPARATOR . "{$profileKey}.sqlite3",
                SqlitePersistenceProvider::class
            );
            $this->_persistenceProfileRepository->Create($profiles[0]);
        }

        return $profiles[0];
    }

    public function SavePersistenceProfile(EntityPersistenceProfile $profile): bool
    {
        return $this->_persistenceProfileRepository->Update($profile);
    }

    /**
     * @param class-string<T> $entityClass
     */
    public function Read(string $entityClass, Query $query): object
    {
        return new $entityClass();
    }

    public function GetAvailableProviders(): array
    {
        $manifest = $this->_manifestService->GetPackageManifest();

        $providers = [];
        foreach ($manifest as $vendorPkgs) {
            foreach ($vendorPkgs as $package) {
                if (!isset($package['persistence_providers'])) {
                    continue;
                }

                foreach ($package['persistence_providers'] as $providerClass) {
                    if (!$this->IsProviderValid($providerClass)) {
                        continue;
                    }

                    $providers[] = $providerClass;
                }
            }
        }

        return array_unique($providers);
    }

    public function IsProviderValid(string $providerClass): bool
    {
        if (!class_exists($providerClass)) {
            return false;
        }

        if (!is_subclass_of($providerClass, IEntityPersistenceProvider::class)) {
            return false;
        }

        return true;
    }

    public function IsHostValid(EntityPersistenceProfile $profile): bool
    {
        $provider = $profile->GetPersistenceProvider();

        try {
            $provider->IsProfileValid();
        } catch (HostNotFoundException) {
            return false;
        } catch (InvalidUserException|PDOException) {
            return true;
        }

        return true;
    }

    public function IsUserValid(EntityPersistenceProfile $profile): bool
    {
        $provider = $profile->GetPersistenceProvider();

        try {
            $provider->IsProfileValid();
        } catch (Exception) {
            return false;
        }

        return true;
    }
}