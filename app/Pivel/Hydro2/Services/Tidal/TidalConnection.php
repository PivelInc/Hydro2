<?php

namespace Pivel\Hydro2\Services\Tidal;

use Pivel\Hydro2\Models\Identity\User;
use Socket;

class TidalConnection
{
    public string $id;
    public Socket $socket;
    public User|null $user = null;
    public TidalToken|null $token = null;
    public bool $isHandshakeComplete = false;
    public bool $isAuthenticated = false;
    public string $buffer = '';
    /** @var string[] */
    public array $subscriptions = [];
    public string $address = '';

    public function __construct(Socket $socket, string $id)
    {
        $this->socket = $socket;
        $this->id = $id;
        socket_getpeername($this->socket, $this->address);
    }

    // unadjusted code

    public array $headers = [];
    public bool $handlingPartialPacket = false;
    public bool $sendingContinuous = false;
    public string $partialMessage = "";
    public bool $hasSentClose = false;
}