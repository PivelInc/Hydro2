<?php

namespace Pivel\Hydro2\Controllers;

use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Services\PackageManifestService;
use Pivel\Hydro2\Views\FallbackView;

class FallbackController extends BaseController
{
    private Hydro2 $_app;
    private PackageManifestService $_packageManifestService;

    public function __construct(
        Hydro2 $app,
        PackageManifestService $packageManifestService,
        Request $request,
    ) {
        $this->_app = $app;
        $this->_packageManifestService = $packageManifestService;
        parent::__construct($request);
    }

    #[Route(Method::POST, '', order:100)]
    #[Route(Method::GET, '', order:100)]
    public function routeFallback() : Response {
        $view = new FallbackView($this->_packageManifestService);
        return new Response(
            content: $view->Render($this->_app),
            status: StatusCode::OK
        );
    }

    #[Route(Method::POST, '~{*path}', order:200)]
    #[Route(Method::GET, '~{*path}', order:200)]
    public function routeNotFound() : Response {
        //$view = new NotFoundView();
        return new Response(
            //content: $view->Render(),
            status: StatusCode::NotFound
        );
    }
}