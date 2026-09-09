<?php

namespace Pivel\Hydro2\Services\Tidal;

use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Uuid;
use Pivel\Hydro2\Services\ILoggerService;

interface ITidalServer
{
    public function __construct(Hydro2 $app, ILoggerService $logger, string $address);
    public function Start() : void;
    public function Stop() : void;
    /**
     * Subscribe to a Tidal event.
     *
     * @param string $event The event to subscribe to.
     * @param callable(ITidalServer $server, object|null $data) $callback The callback to invoke when the event is triggered.
     */
    public function Subscribe(string $event, callable $callback) : void;
    public function Publish(string $event, object|null $data = null) : void;
    public function SendToAll(string $event, object|null $data = null) : void;
    public function SendToUser(User $user, string $event, object|null $data = null) : void;
    public function SendToUserId(Uuid $user, string $event, object|null $data = null) : void;
    public function SendToClient(string $token, string $event, object|null $data = null) : void;
}