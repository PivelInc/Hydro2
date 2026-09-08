<?php

namespace Pivel\Hydro2\Models;

use BackedEnum;
use Countable;
use DateTime;
use Iterator;
use PHPUnit\PhpParser\Node\Stmt\For_;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;
use Pivel\Hydro2\Models\Database\Type;
use ReflectionClass;
use ReflectionEnum;
use ReflectionUnionType;
use TypeError;

/**
 * A definition of the collection associated with an Entity. Contains the collection name and a
 * list of fields, field types, and the associated property names of the represented Entity.
 * 
 * @template TEntity
 */
class EntityDefinition implements Iterator, Countable
{
    /** @var class-string<TEntity> */
    private string $entityClass;
    private string $collectionName;
    private bool $isExtendable;
    private bool $hasParent = false;
    private ?EntityDefinition $parentEntity = null;
    /** @var EntityFieldDefinition[] */
    private array $fields;
    private int $position;
    private ?EntityFieldDefinition $primaryKey;

    private ReflectionClass $reflectionClass;

    /**
     * @param class-string<TEntity> $entityClass
     */
    public function __construct(string $entityClass)
    {
        // find all fields in the collection
        $this->entityClass = $entityClass;
        $this->reflectionClass = new ReflectionClass($entityClass);
        $cAttributes = $this->reflectionClass->getAttributes(Entity::class);
        if (count($cAttributes) != 1) {
            throw new TypeError("The provided class name {$entityClass} doesn't have an Entity tag.");
        }
        $entityAttribute = $cAttributes[0]->newInstance();
        $this->collectionName = $entityAttribute->CollectionName;
        $this->isExtendable = $entityAttribute->Extendable;

        // check if there is a parent class that is an entity.
        $parent = get_parent_class($entityClass);
        if ($parent !== false) {
            // shouldn't need to worry about circular references, because
            // to have one would require circular inheritance which probably
            // causes an error anyways.
            // TODO: test that circular inheritance causes an error.
            try {
                $this->parentEntity = new self($parent);
                $this->hasParent = true;
            } catch (TypeError) {
                // TODO how to handle the case where the parent isn't an entity?
            }
        }

        $this->primaryKey = null;
        $this->fields = [];

        // if there is a parent, the primary key is a field with the name "[parent's pk field name]"
        if ($this->hasParent) {
            // TODO should the parent be forced to be extendable?
            $parentPk = $this->parentEntity->GetPrimaryKeyField();
            if ($parentPk !== null) {
                $this->primaryKey = new EntityFieldDefinition(
                    $parentPk->FieldName,
                    $parentPk->Property,
                    IsPrimaryKey: true,
                    IsForeignKey: true,
                    ForeignKeyClassName: $parent,
                    ForeignKeyCollectionName: $this->parentEntity->GetName(),
                    ForeignKeyCollectionField: $parentPk,
                    ForeignKeyOnUpdate: ReferenceBehaviour::CASCADE,
                    ForeignKeyOnDelete: ReferenceBehaviour::CASCADE, // deleting the parent will also result in deleting children
                );
                $this->fields[] = $this->primaryKey;
            }
        }

        $foundDiscriminator = false;

        // TODO prevent field name collisions.
        $properties = $this->reflectionClass->getProperties();
        foreach ($properties as $property) {
            $pFieldAttributes = $property->getAttributes(EntityField::class);
            if (count($pFieldAttributes) != 1) {
                continue; // not an entity field
            }
            $pFieldAttribute = $pFieldAttributes[0]->newInstance();

            // if there is a parent, don't add the fields that are part of the parent
            // i.e. only include properties that were declared in this class.
            // except, we do still want to keep the discriminator field.
            if ($property->getDeclaringClass()->getName() !== $entityClass && $pFieldAttribute->FieldName != "discriminator") {
                continue;
            }

            $isForeignKey = false;
            $fkClass = null;
            $fkRc = null;
            $fkCollectionName = null;

            // ensure that the property has a type
            $type = $property->getType();
            
            // check if this is an enum type, and use its backing type if it is
            if (is_subclass_of($type->getName(), BackedEnum::class)) {
                $enum = new ReflectionEnum($type->getName());
                if (!$enum->isBacked()) {
                    continue; // only backed enums are supported
                }
                $type = $enum->getBackingType();
            }

            if ($type === null) {
                continue; // a type must be specified either in the attribute or in the entity.
            }
            if ($type instanceof ReflectionUnionType) {
                continue; // union types not supported
            }
            $pFieldAttribute->IsNullable = $type->allowsNull();

            // check if this is a class with an Entity tag. If so, this is a foreign key
            if (!$type->isBuiltin()) {
                $typeName = $type->getName();
                if (!class_exists($typeName)) {
                    continue; // Type/class doesn't exist.
                }
                $fkRc = new ReflectionClass($typeName);
                $fkRcAttrs = $fkRc->getAttributes(Entity::class);
                if (count($fkRcAttrs) >= 1) {
                    $isForeignKey = true;
                    $fkClass = $typeName;
                    $fkCollectionName = $fkRcAttrs[0]->newInstance()->CollectionName;
                }
            }

            $pk = false;
            // TODO support multi-column primary keys
            if ($this->primaryKey == null) {
                $pPrimaryKeyAttributes = $property->getAttributes(EntityPrimaryKey::class);
                if (count($pPrimaryKeyAttributes) == 1) {
                    $pk = true;
                }
            }

            $fkCollectionFieldName = null;
            $fkOnUpdate = ReferenceBehaviour::CASCADE;
            $fkOnDelete = ReferenceBehaviour::RESTRICT;
            $fkPkField = null;
            if ($isForeignKey) {
                $pFkAttributes = $property->getAttributes(ForeignEntityManyToOne::class);
                if (count($pFkAttributes) == 1) {
                    $pFkAttribute = $pFkAttributes[0]->newInstance();
                    // Identify other entity:
                    if ($pFkAttribute->OtherEntityClass !== null) {
                        $fkClass = $pFkAttribute->OtherEntityClass;
                    }
                    
                    $fkOnUpdate = $pFkAttribute->OnUpdate;
                    $fkOnDelete = $pFkAttribute->OnDelete;

                    if ($pFkAttribute->OtherEntityFieldName !== null) {
                        $fkPkField = (new EntityDefinition($fkClass))->GetFieldByFieldName($pFkAttribute->OtherEntityFieldName);
                    }
                }
                if ($fkPkField == null) {
                    $fkPkField = (new EntityDefinition($fkClass))->GetPrimaryKeyField();
                    if ($fkPkField === null) {
                        continue; // this is a foreign key, but couldn't identify the inverse field name.
                    }
                }
            }

            $pFieldAttribute->FieldName ??= $property->getName() . ($isForeignKey ? $fkCollectionFieldName : '');
            if ($pFieldAttribute->FieldName == "discriminator") {
                $foundDiscriminator = true;
            }

            $field = new EntityFieldDefinition(
                $pFieldAttribute->FieldName,
                $property,
                $pFieldAttribute->IsNullable,
                $pFieldAttribute->AutoIncrement,
                $pk,
                $isForeignKey,
                $fkClass,
                $fkCollectionName,
                $fkPkField,
                $fkOnUpdate,
                $fkOnDelete,
            );

            $this->fields[] = $field;
            if ($pk) {
                $this->primaryKey = $field;
            }
        }

        // if extendable then this entity needs a discriminator field.
        // even if parent has a discriminator field, it is much faster to load entities
        // when each extendable sub-entity also has the same field.
        if ($this->isExtendable && !$foundDiscriminator) {
            throw new TypeError("The provided class {$entityClass} is extendable but doesn't have an discriminator field.");
        }

        // set up Iterator interface
        $this->position = 0;
    }

    public function GetEntityClass() : string
    {
        return $this->entityClass;
    }

    public function GetName() : string
    {
        return $this->collectionName;
    }

    /**
     * @return EntityFieldDefinition[]
     */
    public function GetFields() : array
    {
        return $this->fields;
    }

    public function GetPrimaryKeyField() : ?EntityFieldDefinition
    {
        return $this->primaryKey;
    }

    public function GetFieldByFieldName(string $fieldName) : ?EntityFieldDefinition
    {
        // loop through $this->fields until $field->FieldName == $fieldName
        foreach ($this->fields as $field) {
            if ($field->FieldName == $fieldName) {
                return $field;
            }
        }
        return null;
    }

    public function IsExtendable() : bool
    {
        return $this->isExtendable;
    }

    public function HasParent() : bool
    {
        return $this->hasParent;
    }

    public function GetParent() : ?EntityDefinition
    {
        if (!$this->hasParent) {
            return null;
        }

        return $this->parentEntity;
    }

    public function GetTopLevelParent() : EntityDefinition
    {
        if (!$this->hasParent) {
            return $this;
        }

        return $this->parentEntity->GetTopLevelParent();
    }

    // Countable interface methods

    public function count() : int
    {
        return count($this->fields);
    }

    // Iterator interface methods

    public function current() : EntityFieldDefinition
    {
        return $this->fields[$this->position];
    }

    public function key() : int
    {
        return $this->position;
    }

    public function next() : void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->fields[$this->position]);
    }
}