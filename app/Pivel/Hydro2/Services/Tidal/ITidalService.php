<?php

namespace Pivel\Hydro2\Services\Tidal;

use DateTime;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Uuid;

interface ITidalService
{
    public function CreateToken(DateTime $expires, User $user, string|null $reference = null): TidalToken;
    public function GetTokenByValue(string $token): TidalToken|null;
    public function GetTokensByUserId(Uuid $userId): array;
    public function GetTokensByReference(string $reference): array;
    public function IsTidalRunning() : bool;
    public function StartTidalService() : never;
    public function StopTidalService() : void;
}