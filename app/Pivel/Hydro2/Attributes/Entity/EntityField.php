<?php

namespace Pivel\Hydro2\Attributes\Entity;

use Attribute;
use Pivel\Hydro2\Models\Database\Type;

#[Attribute(Attribute::TARGET_PROPERTY)]
class EntityField
{
    /**
     * @param ?string $FieldName The name of the field used in the database. If null, will use the property name
     * @param ?Type $FieldType The type of the field to cast to/from in the database. If null, will try to auto-detect based on the property type.
     * @param bool $IsNullable Whether the field is allowed to be null.
     * @param bool $AutoIncrement Whether the field should be auto-incremented. Only valid for int types.
     */
    public function __construct(
        public ?string $FieldName = null,
        public Type|string|null $FieldType = null,
        public bool $IsNullable = false,
        public bool $AutoIncrement = false,
    ) {
    }
}