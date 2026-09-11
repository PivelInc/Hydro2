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

    public static function FromWKB(string $wkb): ?static
    {
        // convert from MySQL WKB format to new LineString
        $parts = unpack('Vsrid/Corder/Vtype/Vnum_rings', $wkb);
        // only little-endian supported and must be of type Polygon
        if ($parts['order'] !== 1 || $parts['type'] !== 3) {
            // Values from 1 through 7 to indicate Point, LineString, Polygon,
            //  MultiPoint, MultiLineString, MultiPolygon, and GeometryCollection.
            return null;
        }
        $offset = 4 + 1 + 4 + 4; // initial offset after header
        $polygon = new Polygon();
        for ($r = 0; $r < $parts['num_rings']; $r++) {
            $ringParts = unpack('Vnum_points', $wkb, $offset);
            $offset += 4;
            $lineString = new LineString();
            for ($i = 0; $i < $ringParts['num_points']; $i++) {
                $pointParts = unpack('ex/ey', $wkb, $offset);
                $offset += 8 + 8;
                $point = new Point(
                    X: $pointParts['x'],
                    Y: $pointParts['y'],
                );
                $lineString->Points[] = $point;
            }
            if ($r === 0) {
                $polygon->ExteriorRings[] = $lineString;
            } else {
                $polygon->InteriorRings[] = $lineString;
            }
        }

        return $polygon;
    }

    public function GetGeoJSON(): ?array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => array_map(function($ring) {
                return array_map(function($point) {
                    return [$point->X, $point->Y];
                }, $ring->Points);
            }, array_merge($this->ExteriorRings, $this->InteriorRings)),
        ];
    }

    public static function FromGeoJSON(array $geojson): ?static
    {
        if (!isset($geojson['type']) || $geojson['type'] !== 'Polygon' || !isset($geojson['coordinates']) || !is_array($geojson['coordinates'])) {
            return null;
        }

        $polygon = new Polygon();
        foreach ($geojson['coordinates'] as $index => $ringCoords) {
            $lineString = new LineString();
            foreach ($ringCoords as $coord) {
                if (count($coord) != 2) {
                    return null;
                }
                $point = new Point();
                $point->X = (float)$coord[0];
                $point->Y = (float)$coord[1];
                $lineString->Points[] = $point;
            }

            if ($index === 0) {
                $polygon->ExteriorRings[] = $lineString;
            } else {
                $polygon->InteriorRings[] = $lineString;
            }
        }

        return $polygon;
    }

    public function ContainsPoint(Point $point): bool
    {
        // Check if the point is inside the exterior ring
        if (!isset($this->ExteriorRings[0]) || !$this->ExteriorRings[0]->ContainsPoint($point)) {
            return false;
        }

        // Check if the point is inside any of the interior rings (holes)
        foreach ($this->InteriorRings as $interiorRing) {
            if ($interiorRing->ContainsPoint($point)) {
                return false;
            }
        }

        return true;
    }
}
