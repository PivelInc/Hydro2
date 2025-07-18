<?php

namespace Pivel\Hydro2\Services\Entity;

use DateTime;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityOneToMany;
use Pivel\Hydro2\Exceptions\Database\TableNotFoundException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Type;
use Pivel\Hydro2\Models\EntityDefinition;
use Pivel\Hydro2\Services\ILoggerService;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

/**
 * @template TEntity
 */
class EntityRepository implements IEntityRepository
{
    private IEntityService $_entityService;
    private IEntityPersistenceProvider $_provider;
    private ILoggerService $_logger;
    /**
     * @var class-string<TEntity>
     */
    private string $entityClass;
    /**
     * @var EntityDefinition<TEntity>
     */
    private EntityDefinition $definition;

    public function __construct(IEntityService $entityService, IEntityPersistenceProvider $provider, ILoggerService $logger, string $entityClass)
    {
        $this->_entityService = $entityService;
        $this->_provider = $provider;
        $this->_logger = $logger;
        $this->entityClass = $entityClass;
        $this->definition = new EntityDefinition($entityClass);
    }

    public function Read(?Query $query = null) : array
    {
        try {
            $results = $this->_provider->Select($this->definition, $query);
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "Definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return [];
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $results = $this->_provider->Select($this->definition, $query);
        }

        // Cast results
        /** @var TEntity[] */
        $entities = [];
        $count = 0;
        foreach ($results as $result) {
            $entities[] = $this->EntityFromArray($result);
            $count++;
        }

        $this->_logger->Debug('Pivel/Hydro2', "Found {$count} entries from collection '{$this->definition->GetName()}' that match the query.");
        return $entities;
    }

    public function ReadById(mixed $id) : ?object
    {
        $pkField = $this->definition->GetPrimaryKeyField();
        if ($pkField === null) {
            return null;
        }

        $results = $this->Read((new Query)->Equal($pkField->FieldName, $id));
        if (count($results) != 1) {
            return null;
        }
        return $results[0];
    }

    public function ReadByIdIntoSubEntity(object &$entity, $id) : bool
    {
        $pkField = $this->definition->GetPrimaryKeyField();
        if ($pkField === null) {
            return false;
        }

        $query = (new Query)->Equal($pkField->FieldName, $id);

        try {
            $results = $this->_provider->Select($this->definition, $query);
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "Definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return [];
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $results = $this->_provider->Select($this->definition, $query);
        }

        if (count($results) != 1) {
            return false;
        }

        $this->EntityFromArray($results[0], $entity);
        
        return true;
    }

    public function Count(?Query $query = null) : int
    {
        try {
            $result = $this->_provider->Count($this->definition, $query);
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return 0;
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $result = $this->_provider->Count($this->definition, $query);
        }

        return $result;
    }

    public function Create(object &$entity) : bool
    {
        if (!($entity instanceof ($this->entityClass))) {
            throw new TypeError("Expected object of type {$this->entityClass}.");
        }

        // if this entity has a parent, update it first (update, not create, because parent record could already exist).
        // TODO Passing a sub-class shouldn't cause any issues. test this.
        if ($this->definition->HasParent()) {
            $r = $this->_entityService->GetRepository($this->definition->GetParent()->GetEntityClass());
            if (!$r->Update($entity)) {
                // unable to update the parent. return false;
                $this->_logger->Error('Pivel/Hydro2', "Failed to save parent entity '{$this->definition->GetParent()->GetEntityClass()}'. Deleting.");
                return false;
            }
        }

        $values = $this->ArrayFromEntity($entity);

        try {
            $pk = $this->_provider->Insert($this->definition, $values);
            $this->_logger->Info('Pivel/Hydro2', "Successfully inserted in '{$this->definition->GetName()}' with primary key {$pk}");
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return false;
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $pk = $this->_provider->Insert($this->definition, $values);
        }

        if ($pk !== null) {
            $this->SetEntityPrimaryKey($entity, $pk);
            $this->SetEntityCollections($entity);
        }

        return true;
    }

    public function Update(object &$entity) : bool
    {
        if (!($entity instanceof ($this->entityClass))) {
            $this->_logger->Warn("Pivel/Hydro2", "Expected object of type {$this->entityClass}.");
            throw new TypeError("Expected object of type {$this->entityClass}.");
        }

        // if this entity has a parent, update it first.
        if ($this->definition->HasParent()) {
            $r = $this->_entityService->GetRepository($this->definition->GetParent()->GetEntityClass());
            if (!$r->Update($entity)) {
                // unable to update the parent. return false;
                $this->_logger->Error('Pivel/Hydro2', "Failed to update parent entity '{$this->definition->GetParent()->GetEntityClass()}'.");
                $this->_logger->Warn('Pivel/Hydro2', "This instance of '{$this->entityClass}' may have an invalid state.");
                return false;
            }
        }

        $values = $this->ArrayFromEntity($entity);

        try {
            $pk = $this->_provider->InsertOrUpdate($this->definition, $values);
            $this->_logger->Info('Pivel/Hydro2', "Successfully inserted or updated record in '{$this->definition->GetName()}' with primary key {$pk}");
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return false;
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $pk = $this->_provider->InsertOrUpdate($this->definition, $values);
        }

        if ($pk !== null) {
            $this->SetEntityPrimaryKey($entity, $pk);
            $this->SetEntityCollections($entity);
        }

        return true;
    }

    public function Delete(object $entity) : bool
    {
        if (!($entity instanceof ($this->entityClass))) {
            throw new TypeError("Expected object of type {$this->entityClass}.");
        }

        $pkField = $this->definition->GetPrimaryKeyField();

        if ($pkField === null) {
            return false;
        }

        // TODO handle unable to delete due to foreign reference
        try {
            $affectedRows = $this->_provider->Delete($this->definition, (new Query())->Equal($pkField->FieldName, $this->GetEntityPrimaryKey($entity)));
        } catch (TableNotFoundException) {
            $this->_logger->Warn('Pivel/Hydro2', "definition '{$this->definition->GetName()}' not found.");
            $this->_logger->Info('Pivel/Hydro2', "Creating definition '{$this->definition->GetName()}'...");
            $created = $this->_provider->CreateCollectionIfNotExists($this->definition);
            if (!$created) {
                $this->_logger->Error('Pivel/Hydro2', "Failed to create definition '{$this->definition->GetName()}'.");
                return 0;
            }
            $this->_logger->Info('Pivel/Hydro2', "Successfully created '{$this->definition->GetName()}'.");
            $affectedRows = $this->_provider->Delete($this->definition, (new Query())->Equal($pkField->FieldName, $this->GetEntityPrimaryKey($entity)));
        }

        // intentionally doesn't delete parent entities; business logic of implementations may require different behaviours here.

        return $affectedRows == 1;
    }

    

    /**
     * @param mixed[] $values The data to cast to an entity.
     * @return TEntity The entity.
     */
    private function EntityFromArray(array $values, ?object &$entity=null) : object {
        // if this entity's definition says that it is extendable:
        if ($this->definition->IsExtendable() && $entity === null) {
            // check discriminator
            $d = class_exists($values["discriminator"]) ? $values["discriminator"] : $this->entityClass;
            // if discriminator is this entity, continue loading it
            if ($d !== $this->entityClass) {
                // else, load that entity and return it. If it doesn't exist, keep loading this entity instead.
                $r = $this->_entityService->GetRepository($d);
                $e = $r->ReadById($values[$this->definition->GetPrimaryKeyField()->FieldName]);
                if ($e !== null) {
                    return $e;
                }
            }
        }

        /** @var TEntity */
        $entity ??= new $this->entityClass();

        // if this entity has a parent, load those properties
        if ($this->definition->HasParent()) {
            $r = $this->_entityService->GetRepository($this->definition->GetParent()->GetEntityClass());
            if (!$r->ReadByIdIntoSubEntity($entity, $values[$this->definition->GetPrimaryKeyField()->FieldName])) {
                // unable to successfully load parent properties
                // TODO how to handle this?
            }
        }

        foreach ($this->definition as $field) {
            if (!isset($values[$field->FieldName])) {
                continue;
            }

            // don't try to read discriminator field or a sub-entity's primary key field
            if ($field->Property === null) {
                continue;
            }
            if ($this->definition->HasParent() && $field->FieldName == $this->definition->GetPrimaryKeyField()->FieldName) {
                continue;
            }

            $value = $values[$field->FieldName];

            // TODO type conversion
            // TODO custom class conversions (i.e. Polygon)
            if ($field->FieldType == Type::DATETIME) {
                if ($field->IsNullable && $value == null) {
                    $value = null;
                } else {
                    $value = new DateTime($value.'+00:00');
                }
            }

            if (!$field->IsForeignKey) {
                $field->Property->setValue($entity, $value);
                continue;
            }

            $r = $this->_entityService->GetRepository($field->ForeignKeyClassName);
            $foreignEntities = $r->Read((new Query)->Equal($field->foreignKeyCollectionFieldName, $value));
            if (count($foreignEntities) == 1) {
                $field->Property->setValue($entity, $foreignEntities[0]);
            }
        }

        $this->SetEntityCollections($entity);

        return $entity;
    }

    // TODO saving extendable entities

    /**
     * @param TEntity $entity The entity to be converted to an array.
     * @return mixed[] The entity's field values as an array
     */
    private function ArrayFromEntity(object $entity) : array {
        $values = [];

        // if this entity's definition says that it is extendable:
        if ($this->definition->IsExtendable()) {
            // set discriminator
            $values["discriminator"] = $entity::class;
        }

        foreach ($this->definition as $field) {
            $value = $field->Property->getValue($entity);

            // TODO type conversion
            if ($field->FieldType == Type::DATETIME) {
                /** @var DateTime $value */
                if ($field->IsNullable && $value == null) {
                    $value = null;
                } else {
                    $value = $value->format('c');
                }
            }

            if (!$field->IsForeignKey || !is_object($value)) {
                $values[$field->FieldName] = $value;
                continue;
            }

            $fkPkField = (new EntityDefinition($field->ForeignKeyClassName))->GetPrimaryKeyField();
            $values[$field->FieldName] = $value===null?null:$fkPkField->Property->getValue($value);
        }

        return $values;
    }

    /**
     * @param TEntity &$entity The entity whose primary key field is to be set
     * @param mixed $value The primary key value to set
     */
    private function SetEntityPrimaryKey(object &$entity, mixed $value) : void
    {
        if ($this->definition->GetPrimaryKeyField() == null) {
            return;
        }

        $this->definition->GetPrimaryKeyField()->Property->setValue($entity, $value);
    }

    /**
     * @param TEntity $entity
     * @return mixed The primary key's value.
     */
    private function GetEntityPrimaryKey(object $entity) : mixed
    {
        if ($this->definition->GetPrimaryKeyField() == null) {
            return null;
        }

        return $this->definition->GetPrimaryKeyField()->Property->getValue($entity);
    }

    /**
     * @param TEntity &$entity
     */
    private function SetEntityCollections(object &$entity) : void
    {
        $rc = new ReflectionClass($entity);
        foreach ($rc->getProperties() as $property) {
            $attrs = $property->getAttributes(ForeignEntityOneToMany::class);
            if (count($attrs) != 1) {
                continue;
            }

            $attr = $attrs[0]->newInstance();

            if ($attr->OtherEntityFieldName == null) {
                $d = new EntityDefinition($attr->OtherEntityClass);
                foreach ($d as $field) {
                    if (!$field->IsForeignKey) {
                        continue;
                    }

                    if ($field->ForeignKeyClassName != $this->entityClass) {
                        continue;
                    }

                    $attr->OtherEntityFieldName = $field->FieldName;
                    break;
                }

                if ($attr->OtherEntityFieldName == null) {
                    // Couldn't determine the inverse field name.
                    continue;
                }
            }

            $pKValue = $this->GetEntityPrimaryKey($entity);
            // check if pkValue is an object representing another Entity, and if so get the value of it's primary key.
            // TODO this should be recursive as long as pkValue represents another Entity
            if (is_object($pKValue) && count((new ReflectionClass(get_class($pKValue)))->getAttributes(Entity::class)) == 1) {
                // Has an Entity tag
                $definition = new EntityDefinition(get_class($pKValue));
                $pKValue = $definition->GetPrimaryKeyField()->Property->getValue($pKValue);
                var_dump($pKValue);
            }
            
            $collection = new EntityCollection(
                $this->_entityService->GetRepository($attr->OtherEntityClass),
                (new Query)->Equal($attr->OtherEntityFieldName, $pKValue),
            );

            $property->setValue($entity, $collection);
        }
    }
}