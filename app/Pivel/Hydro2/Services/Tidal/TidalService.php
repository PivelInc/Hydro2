<?php

namespace Pivel\Hydro2\Services\Tidal;

use DateTime;
use Override;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Extensions\TidalController;
use Pivel\Hydro2\Extensions\TidalSubscription;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Uuid;
use Pivel\Hydro2\Services\Entity\IEntityRepository;
use Pivel\Hydro2\Services\Entity\IEntityService;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\PackageManifestService;
use ReflectionClass;

class TidalService implements ITidalService
{
    private const string LOG_PACKAGE_NAME = "Hydro2/Tidal";

    private Hydro2 $_app;
    private ILoggerService $_logger;
    private IEntityService $_entityService;
    private PackageManifestService $_packageManifestService;
    
    private IEntityRepository $_tokenRepository;

    public ITidalServer $server;

    // fields: date time
    public function __construct(
        Hydro2 $app,
        ILoggerService $logger,
        PackageManifestService $packageManifestService,
        IEntityService $entityService,
    )
    {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_entityService = $entityService;
        $this->_packageManifestService = $packageManifestService;
        
        $this->_tokenRepository = $this->_entityService->GetRepository(TidalToken::class);
    }

    public function PublishRemoteEvent(string $event, array|null $data = null): bool
    {
        if (!$this->IsTidalRunning()) {
            $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Cannot publish event '{$event}' because Tidal service is not running.");
            return false;
        }

        // this method is used by controllers during regular HTTP requests, so the Tidal server is not available here.
        // process:
        // 1. generate a new token
        // 2. initiate a client connection to the websocket
        // 3. complete the handshake process
        // 4. send the token and event data to the websocket
        // 5. close the client connection

        $token = $this->CreateToken(new DateTime('+1 minute'), null, 'tidal_publish_event');

        $tidalServerAddress = "ws://localhost:8080";
        $address = parse_url($tidalServerAddress);
        if ($address === false || !isset($address['host'])) {
            $this->_logger->Error(self::LOG_PACKAGE_NAME, "Invalid Tidal server address: {$tidalServerAddress}");
            return false;
        }

        $host = $address['host'];
        $port = $address['port'] ?? 80;
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false || !socket_connect($socket, $host, $port)) {
            $this->_logger->Error(self::LOG_PACKAGE_NAME, "Unable to connect to Tidal server at {$tidalServerAddress}");
            if ($socket !== false) {
                socket_close($socket);
            }
            return false;
        }

        try {
            $webSocketKey = base64_encode(random_bytes(16));
            $handshake = "GET / HTTP/1.1\r\n"
                . "Host: {$host}:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$webSocketKey}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            socket_write($socket, $handshake, strlen($handshake));

            $response = '';
            while (!str_contains($response, "\r\n\r\n")) {
                $chunk = socket_read($socket, 2048);
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('Tidal handshake response was empty.');
                }
                $response .= $chunk;
            }

            $expectedAccept = base64_encode(hex2bin(sha1(
                $webSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
            )));
            if (!str_starts_with($response, 'HTTP/1.1 101')
                || !preg_match('/\r\nSec-WebSocket-Accept:\s*([^\r\n]+)/i', $response, $matches)
                || trim($matches[1]) !== $expectedAccept
            ) {
                throw new \RuntimeException('Tidal websocket handshake failed.');
            }

            $message = json_encode([
                'token' => $token->token,
                'event' => $event,
                'data' => $data,
            ], JSON_THROW_ON_ERROR);
            $mask = random_bytes(4);
            $maskedMessage = '';
            for ($index = 0, $length = strlen($message); $index < $length; $index++) {
                $maskedMessage .= $message[$index] ^ $mask[$index % 4];
            }

            $length = strlen($maskedMessage);
            if ($length < 126) {
                $frame = chr(0x81) . chr(0x80 | $length);
            } elseif ($length <= 65535) {
                $frame = chr(0x81) . chr(0xFE) . pack('n', $length);
            } else {
                throw new \RuntimeException('Tidal message is too large.');
            }
            socket_write($socket, $frame . $mask . $maskedMessage);
            socket_write($socket, "\x88\x80\x00\x00\x00\x00");
            return true;
        } catch (\Throwable $exception) {
            $this->_logger->Error(self::LOG_PACKAGE_NAME, "Unable to publish Tidal event '{$event}': " . $exception->getMessage());
            return false;
        } finally {
            socket_close($socket);
        }

    }

    public function CreateToken(DateTime $expires, ?User $user, string|null $reference = null): TidalToken {
        $token = bin2hex(random_bytes(32));
        $tidalToken = new TidalToken($token, $expires, $user, $reference);
        $this->_tokenRepository->Create($tidalToken);
        return $tidalToken;
    }

    public function GetTokenByValue(string $token): TidalToken|null {
        $query = (new Query())->Equal('token', $token);
        $tokens = $this->_tokenRepository->Read($query);

        if (count($tokens) != 1) {
            return null;
        }

        if ($tokens[0]->expires < new DateTime()) {
            return null;
        }

        return $tokens[0];
    }

    public function GetTokensByUserId(Uuid $userId): array {
        $query = (new Query())->Equal('user_id', $userId);
        $tokens = $this->_tokenRepository->Read($query);

        $validTokens = [];
        foreach ($tokens as $token) {
            if ($token->expires >= new DateTime()) {
                $validTokens[] = $token;
            }
        }

        return $validTokens;
    }

    #[Override]
    public function GetTokensByReference(string $reference): array
    {
        $query = (new Query())->Equal('reference', $reference);
        $tokens = $this->_tokenRepository->Read($query);

        $validTokens = [];
        foreach ($tokens as $token) {
            if ($token->expires >= new DateTime()) {
                $validTokens[] = $token;
            }
        }

        return $validTokens;
    }

    public function IsTidalRunning() : bool {
        return TidalServer::IsRunning();
    }

    public function StartTidalService() : never {
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Request to start Tidal service...");
        echo "Request to start Tidal service...\n";
        if ($this->IsTidalRunning()) {
            $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Tidal service is already running.");
            echo "Tidal is already running.\n";
            throw new \Exception("Tidal service is already running");
        }

        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Starting Tidal service...");
        echo "Starting tidal service...\n";

        $this->server = new TidalServer($this->_app, $this->_logger, $this, "0.0.0.0:8080");

        // SIGINT = 2
        $this->_app->OnSignal(2, function() {
            echo "SIGINT received. Stopping Tidal service...\n";
            $this->_logger->Info(self::LOG_PACKAGE_NAME, "SIGINT received. Stopping Tidal service...");
            $this->server->Stop();
        });

        // register all Tidal subscriptions
        $subscribers = $this->GetSubscribers();

        foreach ($subscribers as $subscriber) {
            $this->server->Subscribe($subscriber['event'], function(ITidalServer $server, TidalConnection|null $connection, array|null $data) use ($subscriber) {
                $controller_class = $subscriber['controller_class'];
                $controller_method = $subscriber['controller_method'];
                $request = new Request([], [], [], []);
                // get the instance of the controller from Hydro2's dependency injection container
                $controller = $this->_app->ResolveDependency($controller_class, [$request]);
                
                try {
                    $this->_logger->Debug(self::LOG_PACKAGE_NAME, "Invoking Tidal subscriber: {$controller_class}::{$controller_method}");
                    $controller->$controller_method($server, $connection, $data);
                } catch (\Throwable $e) {
                    $this->_logger->Error(self::LOG_PACKAGE_NAME, "Error invoking Tidal subscriber: {$controller_class}::{$controller_method}: " . $e->getMessage());
                }
            });
        }

        $this->server->Start();

        exit();
    }

    public function StopTidalService() : void {
        echo "Request to stop Tidal service...\n";
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Request to stop Tidal service...");
        if (!$this->IsTidalRunning()) {
            echo "Tidal is not running.\n";
            $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Tidal service not running.");
            return;
        }

        echo "Stopping Tidal service...\n";
        $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Stopping Tidal service...");
        
        TidalServer::Stop();

        echo "Tidal service stopped.\n";
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Tidal service stopped.");

        return;
    }

    private function GetSubscribers() : array {
        /** @var array<object> $subscribers */
        $subscribers = [];

        // Get list of controllers
        $controllers = [];
        $pkg_manifest = $this->_packageManifestService->GetPackageManifest();
        foreach ($pkg_manifest as $vendor_name => $vendor_pkg) {
            foreach ($vendor_pkg as $pkg_name => $pkg_info) {
                echo "Checking package {$vendor_name}/{$pkg_name} for controllers...\n";
                if (!isset($pkg_info['controllers'])) {
                    continue;
                }

                foreach ($pkg_info['controllers'] as $c) {
                    $controllers[] = $c;//::class;
                }
            }
        }

        foreach ($controllers as $c) {
            $class = new ReflectionClass($c);
            echo "Checking class {$class->getName()} for Tidal subscriptions...\n";

            if (count($class->getAttributes(TidalController::class)) == 0) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                $attributes = $method->getAttributes(TidalSubscription::class);

                if (count($attributes) == 0) {
                    continue;
                }

                foreach ($attributes as $attribute) {
                    $subscription = $attribute->newInstance();
                    echo "Found Tidal subscription: {$subscription->event} in {$c}::{$method->getName()}\n";
                    $this->_logger->Info(self::LOG_PACKAGE_NAME, "Found Tidal subscription: {$subscription->event} in {$c}::{$method->getName()}");
                    $subscribers[] = [
                        'event' => $subscription->event,
                        'controller_class' => $c,
                        'controller_method' => $method->getName(),
                    ];
                }
            }
        }

        echo "Found " . count($subscribers) . " Tidal subscriptions.\n";
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Found " . count($subscribers) . " Tidal subscriptions.");

        return $subscribers;
    }
}