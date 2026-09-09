<?php

namespace Pivel\Hydro2;

use Deprecated;
use Error;
use Exception;
use PHPUnit\Util\Json;
use Pivel\Hydro2\Models\EntityPersistenceProfile;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Services\AutoloadService;
use Pivel\Hydro2\Services\Entity\EntityRepository;
use Pivel\Hydro2\Services\Entity\EntityService;
use Pivel\Hydro2\Services\Entity\IEntityService;
use Pivel\Hydro2\Services\EnvironmentService;
use Pivel\Hydro2\Services\IEnvironmentService;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\LoggerService;
use Pivel\Hydro2\Services\PackageManifestService;
use Pivel\Hydro2\Services\Router;
use Pivel\Hydro2\Services\RouterService;
use ReflectionClass;
use ReflectionNamedType;

class Hydro2
{
    /**
     * @param string $webDir
     * @param string $appDir
     * @param string[] $additionalAppDirs
     */
    public static function CreateHydro2App(?string $webDir = null, ?string $appDir = null, array $additionalAppDirs = []) : Hydro2
    {
        $appDir ??= dirname(__FILE__, 3);
        $webDir ??= dirname(__FILE__, 4) . DIRECTORY_SEPARATOR . '/web';

        $app = new Hydro2($webDir, $appDir, $additionalAppDirs);
        $app->RegisterAutoloader();

        $app->RegisterSingleton(PackageManifestService::class);
        $app->RegisterSingleton(RouterService::class);
        $app->RegisterSingleton(LoggerService::class, ILoggerService::class);
        $app->RegisterSingleton(EnvironmentService::class, IEnvironmentService::class);
        
        $app->ResolveLoggerService(ILoggerService::class);
        $app->ResolveManifestService(PackageManifestService::class);
        $app->ResolveRouterService(RouterService::class);

        $app->RestoreOrRegisterManifestDI();

        return $app;
    }

    private AutoloadService $_autoloadService;
    private PackageManifestService $_manifestService;
    private RouterService $_routerService;
    private ILoggerService $_loggerService;
    private array $diClasses = [];

    public function __construct(
        public string $WebDir,
        public string $MainAppDir,
        public array $AdditionalAppDirs = [],
    )
    {
        date_default_timezone_set("UTC");

        // register self in DI registry
        $this->diClasses[self::class] = [
            'class' => self::class,
            'isSingleton' => true,
            'instance' => $this,
        ];
    }

    public function RegisterAutoloader() : void
    {
        // set up autoloader
        require_once $this->MainAppDir."/Pivel/Hydro2/Services/AutoloadService.php";
        
        $this->_autoloadService = new AutoloadService($this->MainAppDir);
        foreach ($this->AdditionalAppDirs as $additionalAppDir) {
            $this->_autoloadService->AddDir($additionalAppDir);
        }

        $this->_autoloadService->Register();
    }

    public function RegisterSingleton(string $class, ?string $interface = null) : void
    {
        $classOrInterface = $interface??$class;
        if (isset($this->_loggerService)) {
        }
        $this->diClasses[$classOrInterface] = [
            'class' => $class,
            'isSingleton' => true,
            'instance' => null,
        ];
    }

    public function RegisterTransient(string $class, ?string $interface = null) : void
    {
        $classOrInterface = $interface??$class;
        $this->diClasses[$classOrInterface] = [
            'class' => $class,
            'isSingleton' => false,
            'instance' => null,
        ];
    }

    /**
     * @param class-string $classOrInterface The name of the class or interface to resolve. Returns null if not registered.
     * @param mixed[] $args Array of args to pass (unpacked) to class' constructor after other dependencies are passed.
     */
    public function ResolveDependency(string $classOrInterface, array $args = []) : ?object
    {
        if (isset($this->diClasses[$classOrInterface]) && $this->diClasses[$classOrInterface]['isSingleton'] && $this->diClasses[$classOrInterface]['instance'] !== null) {
            return $this->diClasses[$classOrInterface]['instance'];
        }

        $className = isset($this->diClasses[$classOrInterface]) ? $this->diClasses[$classOrInterface]['class'] : $classOrInterface;
        
        // need to use reflection to find a list of the class or interface's constructor's arguments
        $dependencyArgs = [];
        $rc = new ReflectionClass($className);
        $constructor = $rc->getConstructor();
        if ($constructor != null) {
            $parameters = $constructor->getParameters();
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                if (!($type instanceof ReflectionNamedType)) {
                    break;
                }

                $class = $type->getName();

                if (!isset($this->diClasses[$class])) {
                    break;
                }

                // TODO prevent circular dependency
                $dependencyArgs[] = $this->ResolveDependency($class);
            }
        }

        $instance = new $className(...$dependencyArgs, ...$args);

        if (isset($this->diClasses[$classOrInterface]) && $this->diClasses[$classOrInterface]['isSingleton']) {
            $this->diClasses[$classOrInterface]['instance'] = $instance;
        }
        
        return $instance;
    }

    public function ResolveLoggerService(string $class) : void
    {
        $this->_loggerService = $this->ResolveDependency($class);
    }

    public function ResolveManifestService(string $class) : void
    {
        $this->_manifestService = $this->ResolveDependency($class);
    }
    
    public function ResolveRouterService(string $class) : void
    {
        $this->_routerService = $this->ResolveDependency($class);
    }

    public function RestoreOrRegisterManifestDI() : void
    {
        // TODO save this and load it from file on future requests
        // parse through all ['controllers'] and ['services'] in all package manifests, and register them all with DI
        $pkg_manifest = $this->_manifestService->GetPackageManifest();
        foreach ($pkg_manifest as $vendor_pkgs) {
            foreach ($vendor_pkgs as $pkg_info) {
                if (isset($pkg_info['controllers'])) {
                    foreach ($pkg_info['controllers'] as $c) {
                        $this->RegisterSingleton($c);
                    }
                }

                if (isset($pkg_info['singletons'])) {
                    foreach ($pkg_info['singletons'] as $c) {
                        $this->RegisterSingleton($c['class'], $c['interface']??null);
                    }
                }
            }
        }
    }
    
    public function buildRequest()
    {
        $sapi_name = php_sapi_name();
        
        // Returns Request
        return new Request($_SERVER, $_COOKIE, $_POST, $_GET, $sapi_name);
    }
    
    public function processRequest(Request $request)
    {
        // try loading pre-built routing table
        //$loading_start = microtime(true);
        $could_load = $this->_routerService->LoadRoutes();
        //$loading_end = microtime(true);
        //echo 'Took ' . ($loading_end - $loading_start) * 1000 . 'ms to load existing routing table.';

        if (!$could_load) {
            // routing table hasn't been built yet, so build it
            $parsing_start = microtime(true);
            $this->_loggerService->Warn('Pivel/Hydro2', "Couldn't load routing table, building a new one...");

            $this->_routerService->RegisterRoutesFromAttributes();
            $this->_routerService->SaveRoutes();

            $parsing_end = microtime(true);
            $this->_loggerService->Info('Pivel/Hydro2', "Took " . ($parsing_end - $parsing_start) * 1000 . " ms to build a new routing table.");
        }

        // search for match(es) in routing table
        $matched_routes = $this->_routerService->GetMatchingRoutes($request->method, $request->getEndpoint());
        //echo 'Matching routes: <pre>' . print_r($matched_routes, true) . '</pre>';

        $response = new Response();
        $response->setFinal(false);
        // For each matched route, parse the incoming path, initialize the controller and call the indicated method.
        // A method might return something to indicate that the next method should be used instead.
        foreach ($matched_routes as $matched_route) {
            $parameters = $this->_routerService::ParsePathParameters($matched_route['path'], $request->getEndpoint());
            //echo 'Parameters: <pre>' . print_r($parameters, true) . '</pre>';

            $request->Args = array_merge($request->Args, $parameters);

            $controller = $this->ResolveDependency($matched_route['controller_class'], [$request]);
            $method_name = $matched_route['controller_method'];
            
            try {
                $result = $controller->$method_name();
            } catch (Exception $e) {
                $this->_loggerService->Error('Pivel/Hydro2', "Exception in route handler {$matched_route['controller_class']}::{$method_name}: {$e->getMessage()}\n{$e->getTraceAsString()}");
                $result = new JsonResponse(
                    new ErrorMessage(
                        'hydro2-0001',
                        'An internal server error occurred while processing the request.',
                        "Exception in route handler {$matched_route['controller_class']}::{$method_name}",
                    ),
                    StatusCode::InternalServerError,
                );
            }

            if ($result instanceof Response) {
                $response->append($result);
            }
            
            if ($response->isFinal()) {
                break;
            }
        }

        // Returns Response
        return $response;
    }

    public function OnSignal(int $signal, callable $handler) : void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal($signal, $handler);
        }
    }

    public function Run() : self
    {
        // Process incoming request
        $run_start = microtime(true);
        $request = $this->buildRequest();
        $this->_loggerService->Info('Pivel/Hydro2', "{$request->method->value} {$request->getClientAddress()} {$request->endpoint}");

        $response = $this->processRequest($request);
        $response->send(false, $request->method == Method::CLI);

        $run_end = microtime(true);
        $elapsed_time = number_format(($run_end - $run_start) * 1000, 3);
        $this->_loggerService->Info('Pivel/Hydro2', "Sent response ({$elapsed_time}ms) for {$request->endpoint}");

        return $this;
    }
    
    public function Dispose()
    {
    }
}