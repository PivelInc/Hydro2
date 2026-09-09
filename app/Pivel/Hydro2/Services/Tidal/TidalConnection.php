<?php

namespace Pivel\Hydro2\Services\Tidal;

use Pivel\Hydro2\Models\Identity\User;
use Socket;

class TidalConnection
{
    public string $id;
    public Socket $socket;
    public User|null $user = null;
    public string|null $token = null;
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

    /*
    function processMessage($server, $message) {
        if (strpos($message, " ") === false) {
            return;
        }

        $msgParts = explode(" ", $message, 2);
        $hook = $msgParts[0];

        if (strlen($hook) == 0) {
            return;
        }

        $isSubscribed = isset($this->subscriptions[$hook]);

        $body = $msgParts[1];
        if ($body == "subscribe") {
            if ($isSubscribed) {
                // already subscribed!
                return;
            }

            $newSubscription = $this->getNewSubscription($server, $hook);
            if ($newSubscription === false) {
                return;
            }

            $this->subscriptions[$hook] = $newSubscription;
            return;
        }

        if (!$isSubscribed) {
            // not subscribed yet
            return;
        }

        if ($body == "unsubscribe") {
            unset($this->subscriptions[$hook]);
            return;
        }

        $this->subscriptions[$hook]->processMessage($body);
    }*/
}