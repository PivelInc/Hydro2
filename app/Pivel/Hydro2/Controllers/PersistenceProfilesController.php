<?php

namespace Pivel\Hydro2\Controllers;

use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\EntityPersistenceProfile;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\Permissions;
use Pivel\Hydro2\Services\Entity\IEntityService;
use Pivel\Hydro2\Services\Identity\IIdentityService;
use Pivel\Hydro2\Services\ILoggerService;

#[RoutePrefix('api/hydro2/persistence')]
class PersistenceProfilesController extends BaseController
{
    private ILoggerService $_logger;
    private IEntityService $_entityService;
    private IIdentityService $_identityService;

    public function __construct(
        ILoggerService $logger,
        IEntityService $entityService,
        IIdentityService $identityService,
        Request $request,
    ) {
        $this->_logger = $logger;
        $this->_entityService = $entityService;
        $this->_identityService = $identityService;
        parent::__construct($request);
    }

    #[Route(Method::GET, 'profiles')]
    public function GetAllProfiles(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManagePersistenceProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $query = Query::SortSearchPageQueryFromRequest($this->request, searchField:"key");

        $r = $this->_entityService->GetRepository(EntityPersistenceProfile::class);

        /** @var EntityPersistenceProfile[] */
        $profiles = $r->Read($query);

        return new JsonResponse($profiles);
    }

    #[Route(Method::GET, 'providers')]
    public function GetProviders(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManagePersistenceProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        return new JsonResponse($this->_entityService->GetAvailableProviders());
    }

    #[Route(Method::POST, 'validatehost')]
    public function ValidateHost(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManagePersistenceProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        // validate args
        if (!isset($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0001', 'Missing parameter \"provider\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!isset($this->request->Args['host'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0002', 'Missing parameter \"host\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$this->_entityService->IsProviderValid($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0003', 'Invalid parameter \"provider\"', 'Selected provider is not supported.'),
                status: StatusCode::BadRequest,
            );
        }

        $profile = new EntityPersistenceProfile(
            'validation_test',
            $this->request->Args['host'],
            $this->request->Args['provider'],
        );

        if (!$this->_entityService->IsHostValid($profile)) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0004', 'Invalid parameter \"host\"', 'Host is not valid.'),
                status: StatusCode::BadRequest,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::POST, 'validateuser')]
    public function ValidateUser(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        // check whether the session/user is allowed to view the admin panel at all.
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManagePersistenceProfiles->value)) {
            return new Response(
                status: StatusCode::NotFound,
            );
        }

        // validate args
        if (!isset($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0001', 'Missing parameter \"provider\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!isset($this->request->Args['host'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0002', 'Missing parameter \"host\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$this->_entityService->IsProviderValid($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0003', 'Invalid parameter \"provider\"', 'Selected provider is not supported.'),
                status: StatusCode::BadRequest,
            );
        }

        $profile = new EntityPersistenceProfile(
            'validation_test',
            $this->request->Args['host'],
            $this->request->Args['provider'],
            username: $this->request->Args['username'] ?? null,
            password: $this->request->Args['password'] ?? null,
        );

        if (!$this->_entityService->IsHostValid($profile)) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0004', 'Invalid parameter \"host\"', 'Host is not valid.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$this->_entityService->IsUserValid($profile)) {
            return new JsonResponse(
                [
                    new ErrorMessage('persistprofiles-0005', 'Invalid parameter \"username\"', 'User credentials not valid.'),
                    new ErrorMessage('persistprofiles-0006', 'Invalid parameter \"password\"', 'User credentials not valid.'),
                ],
                status: StatusCode::BadRequest,
            );
        }

        return new JsonResponse(
            [
                'cancreatedb' => $profile->GetPersistenceProvider()->CanCreateDatabaseSchemas(),
                'availabledatabaseschemas' => $profile->GetPersistenceProvider()->GetDatabaseSchemas(),
            ],
            status: StatusCode::OK,
        );
    }

    #[Route(Method::POST, 'profiles/{key}')]
    #[Route(Method::POST, 'profiles')]
    public function SaveConfiguration(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        // check whether the session/user is allowed to view the admin panel at all.
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManagePersistenceProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        // validate args
        if (!isset($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0001', 'Missing parameter \"provider\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!isset($this->request->Args['host'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0002', 'Missing parameter \"host\"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$this->_entityService->IsProviderValid($this->request->Args['provider'])) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0003', 'Invalid parameter \"provider\"', 'Selected provider is not supported.'),
                status: StatusCode::BadRequest,
            );
        }

        $profile = new EntityPersistenceProfile(
            $this->request->Args['key']??'primary',
            $this->request->Args['host'],
            $this->request->Args['provider'],
            username: $this->request->Args['username'] ?? null,
            password: $this->request->Args['password'] ?? null,
            databaseSchema: $this->request->Args['database'] ?? null,
        );

        if (!$this->_entityService->IsHostValid($profile)) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0004', 'Invalid parameter \"host\"', 'Host is not valid.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$this->_entityService->IsUserValid($profile)) {
            return new JsonResponse(
                [
                    new ErrorMessage('persistprofiles-0005', 'Invalid parameter \"username\"', 'User credentials not valid.'),
                    new ErrorMessage('persistprofiles-0006', 'Invalid parameter \"password\"', 'User credentials not valid.'),
                ],
                status: StatusCode::BadRequest,
            );
        }

        $canCreateDatabaseSchemas = $profile->GetPersistenceProvider()->CanCreateDatabaseSchemas();
        $databaseSchemas = $profile->GetPersistenceProvider()->GetDatabaseSchemas();

        // check that selected database is valid, or create a new one if selected, unless there are none because then this is probably Sqlite
        if (count($databaseSchemas) != 0 && !$canCreateDatabaseSchemas && !in_array($profile->GetDatabaseSchema(), $databaseSchemas)) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0007', 'Invalid parameter \"database\"', 'The database doesn\'t exist, or the user doesn\'t have privileges on it, and the user doesn\'t have privileges to create it.'),
                status: StatusCode::BadRequest,
            );
        }

        if ($canCreateDatabaseSchemas && !in_array($profile->GetDatabaseSchema(), $databaseSchemas)) {
            if (!$profile->GetPersistenceProvider()->CreateDatabaseSchema($profile->GetDatabaseSchema())) {
                return new JsonResponse(
                    new ErrorMessage('persistprofiles-0008', 'Error creating database schema', 'The database doesn\'t exist and the user has privileges to create it, but the server failed to create the new database.'),
                    status: StatusCode::InternalServerError,
                ); 
            }

            $this->_logger->Debug('Pivel/Hydro2', "Database schema {$profile->GetDatabaseSchema()} created.");
        }

        if (!$this->_entityService->SavePersistenceProfile($profile)) {
            return new JsonResponse(
                new ErrorMessage('persistprofiles-0009', 'Error saving profile', 'Failed to save the persistence profile.'),
                status: StatusCode::InternalServerError,
            ); 
        }
        
        return new Response(status: StatusCode::NoContent);
    }
}