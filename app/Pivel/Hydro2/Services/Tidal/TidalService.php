<?php

namespace Pivel\Hydro2\Services\Tidal;

use Pivel\Hydro2\Extensions\TidalController;
use Pivel\Hydro2\Extensions\TidalSubscription;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\PackageManifestService;
use ReflectionClass;

class TidalService implements ITidalService
{
    private const string LOG_PACKAGE_NAME = "Hydro2/Tidal";

    private Hydro2 $_app;
    private ILoggerService $_logger;
    public ITidalServer $server { get; private set; }
    private PackageManifestService $_packageManifestService;

    // fields: date time
    public function __construct(Hydro2 $app, ILoggerService $logger, PackageManifestService $packageManifestService)
    {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_packageManifestService = $packageManifestService;
    }

    public function IsTidalRunning() : bool {
        throw new \Exception("Not implemented");
    }

    public function StartTidalService() : never {
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Request to start Tidal service...");
        if ($this->IsTidalRunning()) {
            $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Tidal service is already running.");
            throw new \Exception("Tidal service is already running");
        }

        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Starting Tidal service...");

        $this->server = new TidalServer($this->_app, $this->_logger, "0.0.0.0:8080");

        $this->_app->OnSignal(SIGINT, function() {
            $this->_logger->Info(self::LOG_PACKAGE_NAME, "SIGINT received. Stopping Tidal service...");
            $this->server->Stop();
        });

        // register all Tidal subscriptions
        $subscribers = $this->GetSubscribers();

        foreach ($subscribers as $subscriber) {
            $this->server->Subscribe($subscriber['event'], function(ITidalServer $server, object|null $data) use ($subscriber) {
                $controller_class = $subscriber['controller_class'];
                $controller_method = $subscriber['controller_method'];

                // get the instance of the controller from Hydro2's dependency injection container
                $controller = $this->_app->ResolveDependency($controller_class);
                
                try {
                    $this->_logger->Debug(self::LOG_PACKAGE_NAME, "Invoking Tidal subscriber: {$controller::class}::{$controller_method}");
                    $controller->$controller_method($server, $data);
                } catch (\Throwable $e) {
                    $this->_logger->Error(self::LOG_PACKAGE_NAME, "Error invoking Tidal subscriber: {$controller::class}::{$controller_method}: " . $e->getMessage());
                }
            });
        }

        $this->server->Start();

        exit();
    }

    public function StopTidalService() : void {
        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Request to stop Tidal service...");
        if (!$this->IsTidalRunning()) {
            $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Tidal service not running.");
            return;
        }

        $this->_logger->Warn(self::LOG_PACKAGE_NAME, "Stopping Tidal service...");

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
                    $this->_logger->Info(self::LOG_PACKAGE_NAME, "Found Tidal subscription: {$subscription->event} in {$c}::{$method->getName()}");
                    $subscribers[] = [
                        'event' => $subscription->event,
                        'controller_class' => $c,
                        'controller_method' => $method->getName(),
                    ];
                }
            }
        }

        $this->_logger->Info(self::LOG_PACKAGE_NAME, "Found " . count($subscribers) . " Tidal subscriptions.");

        return $subscribers;
    }
}