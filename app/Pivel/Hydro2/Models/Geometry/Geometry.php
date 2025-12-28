<?php

namespace Pivel\Hydro2\Models\Geometry;

use JsonSerializable;
use Pivel\Hydro2\Extensions\JsonDeserializable\JsonDeserializable;

class Geometry implements JsonSerializable, JsonDeserializable
{
    public int $SRID = 0;
    public int $Dimension = -1;

    public function jsonSerialize(): mixed
    {
        return [
            'srid' => $this->SRID,
            'dimension' => $this->Dimension,
        ];
    }
    
    public static function jsonDeserialize(mixed $object): ?static
    {
        if (!is_array($object)) {
            return null;
        }

        $instance = new static();
        
        return $instance;
    }

    public function ToWKT(): string
    {
        return '';
    }

    public static function FromWKT(string $wkt): ?static
    {
        return null;
    }

    public static function FromWKB(string $wkb): ?static
    {
        return null;
    }

    public function __toString()
    {
        return $this->ToWKT();
    }
}
