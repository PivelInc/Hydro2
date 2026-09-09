<?php

namespace Pivel\Hydro2\Controllers;

use DateTime;
use DateTimeZone;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Models\Identity\Session;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserPassword;
use Pivel\Hydro2\Models\Permissions;
use Pivel\Hydro2\Services\Database\DatabaseService;
use Pivel\Hydro2\Services\IdentityService;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\Tidal\ITidalService;
use Pivel\Hydro2\Services\UserNotificationService;
use Pivel\Hydro2\Views\EmailViews\Identity\NewUserVerificationEmailView;
use Pivel\Hydro2\Views\Identity\LoginView;

#[RoutePrefix('api/hydro2/identity')]
class SessionController extends BaseController
{
    private Hydro2 $_app;
    private ILoggerService $_logger;
    private IdentityService $_identityService;
    private UserNotificationService $_userNotificationService;
    private ITidalService $_tidalService;

    public function __construct(
        Hydro2 $app,
        ILoggerService $logger,
        IdentityService $identityService,
        UserNotificationService $userNotificationService,
        ITidalService $tidalService,
        Request $request,
    )
    {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_identityService = $identityService;
        $this->_userNotificationService = $userNotificationService;
        $this->_tidalService = $tidalService;
        parent::__construct($request);
    }

    #[Route(Method::POST, 'sessions')]
    #[Route(Method::POST, 'login')]
    #[Route(Method::POST, '~login')]
    #[Route(Method::POST, '~api/login')]
    public function Login(): Response
    {
        if ($this->_identityService->GetSessionFromRequest($this->request) !== null) {
            $session = $this->_identityService->GetSessionFromRequest($this->request);
            $user = $session->GetUser();
            return new JsonResponse(
                [
                    'authenticated' => true,
                    'challenge_required' => ($user->GetUserRole()->ChallengeIntervalMinutes>0),
                    'password_change_required' => $user->IsPasswordChangeRequired(),
                ],
                status: StatusCode::OK,
            );
        }

        if (!isset($this->request->Args['email'])) {
            return new JsonResponse(
                new ErrorMessage('session-0001', "Missing parameter \"email\"", "The user's email address is required."),
                status: StatusCode::BadRequest,
            );
        }

        if (!isset($this->request->Args['password'])) {
            return new JsonResponse(
                new ErrorMessage('session-0002', "Missing parameter \"password\"", "The user's current password is required."),
                status: StatusCode::BadRequest,
            );
        }

        $user = $this->_identityService->GetUserFromEmail($this->request->Args['email']);
        if ($user == null) {
            return new JsonResponse(
                new ErrorMessage('session-0003', "Invalid parameter \"email\"", "The provided email address does not match an account."),
                status: StatusCode::BadRequest,
            );
        }

        if ($user->GetUserRole() === null) {
            return new JsonResponse(
                new ErrorMessage('session-0004', "Invalid parameter \"email\"", "Unable to log in. Please contact the administrator."),
                status: StatusCode::BadRequest,
            );
        }

        if (
            ($user->GetUserRole()->MaxLoginAttempts > 0 && $user->FailedLoginAttempts >= $user->GetUserRole()->MaxLoginAttempts) ||
            ($user->GetUserRole()->Max2FAAttempts > 0 && $user->Failed2FAAttempts >= $user->GetUserRole()->Max2FAAttempts)
        ) {
            return new JsonResponse(
                new ErrorMessage('session-0005', "Invalid parameter \"email\"", 'This account is locked due to too many failed login attempts.'),
                status: StatusCode::BadRequest,
            );
        }

        if ($user->GetPasswordCount() == 0 || !$user->EmailVerified) {
            $user->EmailVerified = false;
            $this->_identityService->UpdateUser($user);
            $view = new NewUserVerificationEmailView($this->_identityService->GetEmailVerificationUrl($this->request, $user, true), $user->Name);
            $this->_userNotificationService->SendEmailToUser($user, $view);
            return new JsonResponse(
                new ErrorMessage('session-0006', "Invalid parameter \"password\"", 'Account creation is incomplete. A validation email has been re-sent to your email address.'),
                status: StatusCode::BadRequest,
            );
        }
        
        if (!$user->CheckPassword($this->request->Args['password'])) {
            $user->FailedLoginAttempts++;
            $this->_identityService->UpdateUser($user);
            return new JsonResponse(
                new ErrorMessage('session-0007', "Invalid parameter \"password\"", 'The provided password is incorrect.'),
                status: StatusCode::BadRequest,
            );
        }

        if (!$user->Enabled || $user->NeedsReview) {
            return new JsonResponse(
                new ErrorMessage('session-0008', "Invalid parameter \"email\"", 'Unable to log in. Please contact the administrator.'),
                status: StatusCode::BadRequest,
            );
        }

        // reset failed login attempts
        $user->FailedLoginAttempts = 0;
        $this->_identityService->UpdateUser($user);

        $session = $this->_identityService->StartSession($user, $this->request);

        if ($session === null) {
            return new JsonResponse(
                new ErrorMessage('session-0009', "Failed to login", 'Unable to log in. Please contact the administrator.'),
                status: StatusCode::InternalServerError,
            );
        }

        setcookie('sridkey', $session->RandomId . ';' . $session->Key, $session->ExpireTime->getTimestamp(), '/', httponly: true);

        $tidal_token = null;
        if ($this->_tidalService->IsTidalRunning()) {
            $tidal_token = $this->_tidalService->CreateToken($session->ExpireTime, $user)->token;
        }

        return new JsonResponse(
            [
                'authenticated' => true,
                'challenge_required' => ($user->GetUserRole()->ChallengeIntervalMinutes>0),
                'password_change_required' => $user->IsPasswordChangeRequired(),
                'tidal_token' => $tidal_token,
            ],
            status: StatusCode::OK,
        );
    }

    #[Route(Method::GET, 'users/{uuid}/sessions')]
    public function UserGetSessions(): Response
    {
        // need to have either viewusersessions permission or be requestion own user's sessions
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ViewUserSessions->value) ||
            $requestUser->Id == $this->request->Args['id']
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']);
        if ($user === null) {
            return new Response(status: StatusCode::NotFound);
        }

        $sessions = $user->GetSessions();

        return new JsonResponse($sessions);
    }
    
    #[Route(Method::DELETE, 'sessions/{sessionid}')]
    public function UserExpireSession(): Response
    {
        $session = $this->_identityService->GetSessionFromRandomId($this->request->Args['sessionid']);
        // need to have either endusersessions permission or be requesting on own user's sessions
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::EndUserSessions->value) ||
            ($session !== null && $requestUser->Id == $session->GetUser()->Id)
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        if ($session === null) {
            return new JsonResponse(
                new ErrorMessage('session-0010', "Invalid parameter \"sessionid\"", 'Session not found or already deleted.'),
                status: StatusCode::NotFound,
            );
        }

        if (!$this->_identityService->ExpireSession($session)) {
            return new JsonResponse(
                new ErrorMessage('session-0011', "Failed to terminate session", 'An internal error prevented termination of the session.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::GET, '~login')]
    public function GetLoginView(): Response
    {
        $session = $this->_identityService->GetSessionFromRequest($this->request);
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        // check if already logged in. If there is a ?next= arg, redirect to that path. Otherwise, redirect to ~/
        //  unless password change is required, then display the password change screen.
        //  TODO if 2FA challenge is required, then display the 2FA challenge screen.
        $view = new LoginView();
        if ($session !== null) {
            if (!$requestUser->IsPasswordChangeRequired()) {
                return new Response(
                    status: StatusCode::Found,
                    headers: [
                        'Location' => $this->request->Args['next']??'/',
                    ],
                );
            }

            $view->DefaultPage = 'changepassword';
        }

        return new Response(
            content: $view->Render($this->_app)
        );
    }

    #[Route(Method::GET, '~logout')]
    public function Logout(): Response
    {
        $session = $this->_identityService->GetSessionFromRequest($this->request);

        if ($session !== null) {
            $this->_identityService->ExpireSession($session);
            setcookie('sridkey', '', time()-3600, '/');
        }

        return new Response(
            status: StatusCode::Found,
            headers: [
                'Location' => '/login',
            ],
        );
    }
}