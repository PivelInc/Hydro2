<?php

namespace Pivel\Hydro2\Models\Geometry;

class Point extends Geometry
{
    public int $Dimension = 2;

    public function __construct(
        public float $X = 0.0,
        public float $Y = 0.0,
        int $SRID = 0,
    ) {
        $this->SRID = $SRID;
    }

    public function jsonSerialize(): mixed
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'x_coordinate' => $this->X,
                'y_coordinate' => $this->Y,
            ],
        );
    }
    
    public static function jsonDeserialize(mixed $object): ?static
    {
        if (!is_array($object)) {
            return null;
        }

        $instance = parent::jsonDeserialize($object);
        $instance->X = $object["x_coordinate"] ?? 0.0;
        $instance->Y = $object["y_coordinate"] ?? 0.0;
        
        return $instance;
    }

    public function ToWKT(): string
    {
        return "POINT({$this->X} {$this->Y})";
    }

    public static function FromWKT(string $wkt): ?static
    {
        $wkt = strtoupper(trim($wkt));
        if (!str_starts_with($wkt, 'POINT')) {
            return null;
        }

        $coords = explode(' ', trim(substr($wkt, strlen('POINT')), ' ()'));
        if (count($coords) != 2) {
            return null;
        }

        return new Point(
            X: (float)$coords[0],
            Y: (float)$coords[1],
        );
    }

    public static function FromWKB(string $wkb): ?static
    {
        // convert from MySQL WKB format to new Point
        $parts = unpack('Vsrid/Corder/Vtype/ex/ey', $wkb);
        // only little-endian supported and must be of type Point
        if ($parts['order'] !== 1 || $parts['type'] !== 1) {
            // Values from 1 through 7 to indicate Point, LineString, Polygon,
            //  MultiPoint, MultiLineString, MultiPolygon, and GeometryCollection.
            return null;
        }
        return new Point(
            X: $parts['x'],
            Y: $parts['y'],
            SRID: $parts['srid'],
        );
    }

    public function GetGeoJSON(): ?array
    {
        return [
            'type' => 'Point',
            'coordinates' => [$this->X, $this->Y],
        ];
    }

    public static function FromGeoJSON(array $geojson): ?static
    {
        if (!isset($geojson['type']) || strtoupper($geojson['type']) !== 'POINT') {
            return null;
        }
        if (!isset($geojson['coordinates']) || !is_array($geojson['coordinates']) || count($geojson['coordinates']) != 2) {
            return null;
        }
        return new Point(
            X: (float)$geojson['coordinates'][0],
            Y: (float)$geojson['coordinates'][1],
        );
    }
}
