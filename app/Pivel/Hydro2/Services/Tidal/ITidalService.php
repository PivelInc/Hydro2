<?php

namespace Pivel\Hydro2\Services\Tidal;

interface ITidalService
{
    public function IsTidalRunning() : bool;
    public function StartTidalService() : never;
    public function StopTidalService() : void;
}