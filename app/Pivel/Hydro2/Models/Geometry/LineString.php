<?php

namespace Pivel\Hydro2\Models\Geometry;

class LineString extends Geometry
{
    /** @var Point[] */
    public array $Points = [];
    public int $Dimension = 1;

    public function __construct(
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'points' => $this->Points,
            ],
        );
    }
    
    public static function jsonDeserialize(mixed $object): ?static
    {
        if (!is_array($object)) {
            return null;
        }

        $instance = parent::jsonDeserialize($object);
        $instance->Points = array_map(fn($point) => Point::jsonDeserialize($point), $object["points"] ?? []);
        
        return $instance;
    }

    public function ToWKT(): string
    {
        $pointStrings = array_map(fn($point) => "{$point->X} {$point->Y}", $this->Points);
        $pointsWKT = implode(", ", $pointStrings);
        return "LINESTRING({$pointsWKT})";
    }

    public static function FromWKT(string $wkt): ?static
    {
        $wkt = strtoupper(trim($wkt));
        if (!str_starts_with($wkt, 'LINESTRING')) {
            return null;
        }

        $points = explode(',', trim(substr($wkt, strlen('LINESTRING')), ' ()'));
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

        return $lineString;
    }
}
