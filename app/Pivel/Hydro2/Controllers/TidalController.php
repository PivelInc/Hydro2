<?php

namespace Pivel\Hydro2\Controllers;

use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Extensions\TidalController as ExtensionsTidalController;
use Pivel\Hydro2\Extensions\TidalSubscription;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\PackageManifestService;
use Pivel\Hydro2\Services\Tidal\ITidalServer;
use Pivel\Hydro2\Services\Tidal\ITidalService;
use Pivel\Hydro2\Services\Tidal\TidalConnection;
use Pivel\Hydro2\Services\UserNotificationService;

#[RoutePrefix('api/hydro2/tidal')]
#[ExtensionsTidalController]
class TidalController extends BaseController
{
    private Hydro2 $_app;
    private ILoggerService $_logger;
    private ITidalService $_tidalService;

    public function __construct(
        Hydro2 $app,
        ILoggerService $logger,
        ITidalService $tidalService,
        Request $request,
    ) {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_tidalService = $tidalService;
        parent::__construct($request);
    }

    #[Route(Method::GET, 'status')]
    public function GetTidalServiceStatus(): JsonResponse
    {
        $status = $this->_tidalService->IsTidalRunning();
        return new JsonResponse(['status' => $status]);
    }

    #[Route(Method::CLI, '~TidalStart')]
    public function StartTidalService(): void
    {
        $this->_tidalService->StartTidalService();
    }

    #[Route(Method::CLI, '~TidalStop')]
    public function StopTidalService(): void
    {
        $this->_tidalService->StopTidalService();
    }

    #[TidalSubscription('pivel.hydro2.ping')]
    public function OnPing(ITidalServer $server, TidalConnection|null $connection, array|null $data): void
    {
        $this->_logger->Info("Hydro2/Tidal", "Received ping from Tidal service.");
        if ($connection === null) {
            return;
        }
        $server->SendToClient($connection->token->token, 'pivel.hydro2.pong');
    }
}