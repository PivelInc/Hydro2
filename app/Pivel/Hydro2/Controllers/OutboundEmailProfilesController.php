<?php

namespace Pivel\Hydro2\Controllers;

use Exception;
use Pivel\Hydro2\Exceptions\Email\AuthenticationFailedException;
use Pivel\Hydro2\Exceptions\Email\EmailHostNotFoundException;
use Pivel\Hydro2\Exceptions\Email\NotAuthenticatedException;
use Pivel\Hydro2\Exceptions\Email\TLSUnavailableException;
use Pivel\Hydro2\Extensions\Database\OrderBy;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\Email\EmailAddress;
use Pivel\Hydro2\Models\Email\EmailMessage;
use Pivel\Hydro2\Models\Email\OutboundEmailProfile;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Models\Permissions;
use Pivel\Hydro2\Services\Email\EmailService;
use Pivel\Hydro2\Services\Entity\IEntityService;
use Pivel\Hydro2\Services\IdentityService;
use Pivel\Hydro2\Views\EmailViews\TestEmailView;

#[RoutePrefix('api/hydro2/email/outboundprofiles')]
class OutboundEmailProfilesController extends BaseController
{
    protected Hydro2 $_app;
    protected IEntityService $_entityService;
    protected IdentityService $_identityService;
    protected EmailService $_emailService;

    public function __construct(
        Hydro2 $app,
        IEntityService $entityService,
        IdentityService $identityService,
        EmailService $emailService,
        Request $request,
    ) {
        $this->_app = $app;
        $this->_entityService = $entityService;
        $this->_identityService = $identityService;
        $this->_emailService = $emailService;
        parent::__construct($request);
    }

    #[Route(Method::GET, '')]
    public function GetAllProfiles(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }
        
        if (isset($this->request->Args['sort_by']) && $this->request->Args['sort_by'] == 'sender') {
            $this->request->Args['sort_by'] = 'sender_address';
        }

        $query = Query::SortSearchPageQueryFromRequest($this->request, searchField:"label");

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        /** @var OutboundEmailProfile[] */
        $profiles = $r->Read($query);

        return new JsonResponse($profiles);
    }

    #[Route(Method::GET, '~api/hydro2/email/outboundprofileproviders')]
    public function GetProviders(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        return new JsonResponse($this->_emailService->GetAvailableProviders());
    }

    #[Route(Method::POST, '')]
    public function CreateProfile(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        if (!isset($this->request->Args['key'])) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0001', 'Missing parameter \"key\"', 'A unique key for this outbound email profile is required.'),
                StatusCode::BadRequest,
            );
        }

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        if ($r->Count((new Query)->Equal('key', $this->request->Args['key'])) !== 0) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0002', 'Invalid parameter \"key\"', 'An outbound email profile already exists with the provided key.'),
                StatusCode::BadRequest,
            );
        }
        
        $secure = $this->request->Args['secure']??OutboundEmailProfile::SECURE_NONE;
        if (!in_array($secure, [
            OutboundEmailProfile::SECURE_NONE,
            OutboundEmailProfile::SECURE_SSL,
            OutboundEmailProfile::SECURE_TLS_AUTO,
            OutboundEmailProfile::SECURE_TLS_REQUIRE,
        ])) {
            $secure = OutboundEmailProfile::SECURE_NONE;
        }

        $profile = new OutboundEmailProfile(
            key: $this->request->Args['key'],
            label: $this->request->Args['label']??'Unnamed Email Profile',
            type: $this->request->Args['type']??'smtp',
            sender: new EmailAddress(
                $this->request->Args['sender_address']??'',
                $this->request->Args['sender_name']??''
            ),
            requireAuth: filter_var($this->request->Args['require_auth']??false, FILTER_VALIDATE_BOOL),
            username: $this->request->Args['username']??null,
            password: $this->request->Args['password']??null,
            host: $this->request->Args['host']??'localhost',
            port: $this->request->Args['port']??465,
            secure: $secure,
        );

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        if (!$r->Update($profile)) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0003', 'Internal server error', 'There was a problem with the database.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::GET, '{key}')]
    public function GetProfile(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        $profiles = $r->Read((new Query)->Equal('key', $this->request->Args['key'])->Limit(1));

        if (count($profiles) !== 1) {
            return new Response(status: StatusCode::NotFound);
        }

        return new JsonResponse($profiles[0]);
    }

    #[Route(Method::POST, '{key}')]
    public function UpdateProfile(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        $profiles = $r->Read((new Query)->Equal('key', $this->request->Args['key']));
        if (count($profiles) != 1) {
            $profile = new OutboundEmailProfile(
                key: $this->request->Args['key'],
            );
        } else {
            $profile = $profiles[0];
        }

        $secure = $this->request->Args['secure']??OutboundEmailProfile::SECURE_NONE;
        if (!in_array($secure, [
            OutboundEmailProfile::SECURE_NONE,
            OutboundEmailProfile::SECURE_SSL,
            OutboundEmailProfile::SECURE_TLS_AUTO,
            OutboundEmailProfile::SECURE_TLS_REQUIRE,
        ])) {
            $secure = OutboundEmailProfile::SECURE_NONE;
        }
        
        $profile->Label = $this->request->Args['label']??'Unnamed Email Profile';
        $profile->Type = $this->request->Args['type']??'smtp';
        $profile->SenderAddress = $this->request->Args['sender_address']??'';
        $profile->SenderName = $this->request->Args['sender_name']??'';
        $profile->RequireAuth = filter_var($this->request->Args['require_auth']??false, FILTER_VALIDATE_BOOL);
        $profile->Username = $this->request->Args['username']??null;
        // if new password not provided, keep the same one.
        $profile->Password = $this->request->Args['password']??$profile->Password;
        $profile->Host = $this->request->Args['host']??'localhost';
        $profile->Port = $this->request->Args['port']??465;
        $profile->Secure = $secure;

        if (!$r->Update($profile)) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0004', 'Internal server error', 'There was a problem with the database.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new JsonResponse(status: StatusCode::NoContent);
    }

    #[Route(Method::DELETE, '{key}')]
    public function DeleteProfile(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        $profiles = $r->Read((new Query)->Equal('key', $this->request->Args['key']));

        if (count($profiles) == 1 && !$r->Delete($profiles[0])) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0005', 'Internal server error', 'There was a problem with the database.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new JsonResponse(status:StatusCode::NoContent);
    }

    #[Route(Method::POST, '{key}/test')]
    public function TestProfile(): Response
    {
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageOutboundEmailProfiles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $destination = new EmailAddress($this->request->Args['to'] ?? $requestUser->Email);

        $r = $this->_entityService->GetRepository(OutboundEmailProfile::class);

        $profiles = $r->Read((new Query)->Equal('key', $this->request->Args['key']));

        if (count($profiles) != 1) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0007', 'Invalid parameter \"key\"', 'An outbound email profile with the specified key does not exist.'),
                status: StatusCode::BadRequest,
            );
        }
        $profile = $profiles[0];

        $provider = $this->_emailService->GetOutboundEmailProvider($profile);

        if ($provider === null) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0008', 'Invalid parameter \"type\"', 'No provider available to handle this outbound email profile\'s type'),
                status: StatusCode::BadRequest,
            );
        }

        $emailView = new TestEmailView($this->request->Args['key']);
        $message = new EmailMessage($emailView, $this->_app, [$destination]);

        $result = false;
        try {
            $result = $provider->SendEmail($message, true);
        } catch (EmailHostNotFoundException) {
            $errors = [
                new ErrorMessage('emailprofiles-0009', 'Invalid parameter \"host\"', 'Unable to connect to host.'),
                new ErrorMessage('emailprofiles-0010', 'Invalid parameter \"port\"', 'Unable to connect to host.'),
            ];
            if ($profile->Secure == OutboundEmailProfile::SECURE_SSL) {
                $errors[] = new ErrorMessage('emailprofiles-0011', 'Invalid parameter \"secure\"', 'Unable to connect to host via SSL.');
            }
            return new JsonResponse(
                $errors,
                status: StatusCode::BadRequest,
            );
        } catch (TLSUnavailableException) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0012', 'Invalid parameter \"secure\"', 'The selected profile requires TLS, but TLS negotiation was unavailable or unsuccessful.'),
                status: StatusCode::BadRequest,
            );
        } catch (AuthenticationFailedException) {
            return new JsonResponse(
                [
                    new ErrorMessage('emailprofiles-0013', 'Invalid parameter \"require_auth\"', 'Authentication failed.'),
                    new ErrorMessage('emailprofiles-0014', 'Invalid parameter \"username\"', 'Authentication failed.'),
                    new ErrorMessage('emailprofiles-0015', 'Invalid parameter \"password\"', 'Authentication failed.'),
                ],
                status: StatusCode::BadRequest,
            );
        } catch (NotAuthenticatedException) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0016', 'Invalid parameter \"require_auth\"', 'The email server requires authentication.'),
                status: StatusCode::BadRequest,
            );
        } catch (Exception) {
            $result = false;
        }

        if (!$result) {
            return new JsonResponse(
                new ErrorMessage('emailprofiles-0017', 'Unable to validate', 'The email could not be sent.'),
                status: StatusCode::BadRequest,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }
}