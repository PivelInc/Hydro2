<?php

namespace Pivel\Hydro2\Models\Geometry;

class Polygon extends Geometry
{
    /** @var LineString[] */
    public array $ExteriorRings = [];
    /** @var LineString[] */
    public array $InteriorRings = [];
    public int $Dimension = 2;

    public function __construct(
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'exterior_rings' => $this->ExteriorRings,
                'interior_rings' => $this->InteriorRings,
            ],
        );
    }
    
    public static function jsonDeserialize(mixed $object): ?static
    {
        if (!is_array($object)) {
            return null;
        }

        $instance = parent::jsonDeserialize($object);
        $instance->ExteriorRings = array_map(fn($ring) => LineString::jsonDeserialize($ring), $object["exterior_rings"] ?? []);
        $instance->InteriorRings = array_map(fn($ring) => LineString::jsonDeserialize($ring), $object["interior_rings"] ?? []);
        
        return $instance;
    }

    public function ToWKT(): string
    {
        $exteriorStrings = array_map(function($ring) {
            $pointStrings = array_map(fn($point) => "{$point->X} {$point->Y}", $ring->Points);
            return "(" . implode(", ", $pointStrings) . ")";
        }, $this->ExteriorRings);

        $interiorStrings = array_map(function($ring) {
            $pointStrings = array_map(fn($point) => "{$point->X} {$point->Y}", $ring->Points);
            return "(" . implode(", ", $pointStrings) . ")";
        }, $this->InteriorRings);

        $allRings = array_merge($exteriorStrings, $interiorStrings);
        $ringsWKT = implode(", ", $allRings);
        return "POLYGON({$ringsWKT})";
    }

    public static function FromWKT(string $wkt): ?static
    {
        $wkt = strtoupper(trim($wkt));
        if (!str_starts_with($wkt, 'POLYGON')) {
            return null;
        }

        $rings = explode('),', trim(substr($wkt, strlen('POLYGON')), ' ()'));
        $polygon = new Polygon();

        foreach ($rings as $ring) {
            $points = explode(',', trim($ring, ' ()'));
            $lineString = new LineString();
            foreach ($points as $point) {
                $coords = explode(' ', trim($point));
                if (count($coords) != 2) {
                    return null;
                }
                $pointObj = new Point();
                $pointObj->X = (float)$coords[0];
                $pointObj->Y = (float)$coords[1];
                $lineString->Points[] = $pointObj;
            }

            if (count($polygon->ExteriorRings) === 0) {
                $polygon->ExteriorRings[] = $lineString;
            } else {
                $polygon->InteriorRings[] = $lineString;
            }
        }

        return $polygon;
    }
}
