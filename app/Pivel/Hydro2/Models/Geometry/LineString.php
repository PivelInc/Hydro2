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

    public static function FromWKB(string $wkb): ?static
    {
        // convert from MySQL WKB format to new LineString
        $parts = unpack('Vsrid/Corder/Vtype/Vnum_points', $wkb);
        // only little-endian supported and must be of type LineString
        if ($parts['order'] !== 1 || $parts['type'] !== 2) {
            // Values from 1 through 7 to indicate Point, LineString, Polygon,
            //  MultiPoint, MultiLineString, MultiPolygon, and GeometryCollection.
            return null;
        }
        $offset = 4 + 1 + 4 + 4; // initial offset after header
        $lineString = new LineString();
        for ($i = 0; $i < $parts['num_points']; $i++) {
            $pointParts = unpack('ex/ey', $wkb, $offset);
            $offset += 8 + 8;
            $point = new Point(
                X: $pointParts['x'],
                Y: $pointParts['y'],
            );
            $lineString->Points[] = $point;
        }

        return $lineString;
    }

    public function GetGeoJSON(): ?array
    {
        $coordinates = array_map(fn($point) => [$point->X, $point->Y], $this->Points);
        return [
            'type' => 'LineString',
            'coordinates' => $coordinates,
        ];
    }

    public static function FromGeoJSON(array $geojson): ?static
    {
        if (!isset($geojson['type']) || $geojson['type'] !== 'LineString' || !isset($geojson['coordinates']) || !is_array($geojson['coordinates'])) {
            return null;
        }

        $lineString = new LineString();
        foreach ($geojson['coordinates'] as $coord) {
            if (count($coord) != 2) {
                return null;
            }
            $point = new Point();
            $point->X = (float)$coord[0];
            $point->Y = (float)$coord[1];
            $lineString->Points[] = $point;
        }

        return $lineString;
    }

    public function ContainsPoint(Point $point): bool
    {
        // only valid for closed LineStrings (where the first and last points are the same)
        if (count($this->Points) < 4 || $this->Points[0] != $this->Points[count($this->Points) - 1]) {
            return false;
        }

        // check if point is inside the polygon formed by the closed LineString using ray-casting algorithm
        $inside = false;
        $numPoints = count($this->Points);
        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
            $xi = $this->Points[$i]->X;
            $yi = $this->Points[$i]->Y;
            $xj = $this->Points[$j]->X;
            $yj = $this->Points[$j]->Y;

            $intersect = (($yi > $point->Y) != ($yj > $point->Y)) &&
                ($point->X < ($xj - $xi) * ($point->Y - $yi) / ($yj - $yi) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }
}