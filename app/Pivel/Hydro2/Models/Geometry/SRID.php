<?php

namespace Pivel\Hydro2\Models\Geometry;

enum SRID : int
{
    case Cartesian = 0;
    case WGS84 = 4326;
}