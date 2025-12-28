<?php

namespace Pivel\Hydro2\Models;

use Pivel\Hydro2\Models\Database\ReferenceBehaviour;
use Pivel\Hydro2\Models\Database\Type;
use ReflectionProperty;

class EntityFieldDefinition
{
    public ?string $PropertyType = null;
    // TODO definition when foreign field
    public function __construct(
        public string $FieldName,
        //#[\Deprecated]
        //public Type|string $FieldType,
        public ?ReflectionProperty $Property,
        public bool $IsNullable = false,
        public bool $AutoIncrement = false,
        public bool $IsPrimaryKey = false,
        public bool $IsForeignKey = false,
        public ?string $ForeignKeyClassName = null,
        public ?string $ForeignKeyCollectionName = null,
        public ?EntityFieldDefinition $ForeignKeyCollectionField = null,
        public ReferenceBehaviour $ForeignKeyOnUpdate = ReferenceBehaviour::CASCADE,
        public ReferenceBehaviour $ForeignKeyOnDelete = ReferenceBehaviour::RESTRICT,
    )
    {
        $this->PropertyType = $this->Property?->getType()?->getName();
    }
}