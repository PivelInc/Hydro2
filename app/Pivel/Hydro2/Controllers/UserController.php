<?php

namespace Pivel\Hydro2\Controllers;

use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserRole;
use Pivel\Hydro2\Models\Permissions;
use Pivel\Hydro2\Services\Identity\IIdentityService;
use Pivel\Hydro2\Services\ILoggerService;
use Pivel\Hydro2\Services\UserNotificationService;
use Pivel\Hydro2\Views\EmailViews\Identity\NewEmailNotificationEmailView;
use Pivel\Hydro2\Views\EmailViews\Identity\NewUserVerificationEmailView;
use Pivel\Hydro2\Views\EmailViews\Identity\PasswordChangedNotificationEmailView;
use Pivel\Hydro2\Views\EmailViews\Identity\PasswordResetEmailView;
use Pivel\Hydro2\Views\Identity\ResetView;
use Pivel\Hydro2\Views\Identity\VerifyView;

#[RoutePrefix('api/hydro2/identity/users')]
class UserController extends BaseController
{
    private Hydro2 $_app;
    private ILoggerService $_logger;
    private IIdentityService $_identityService;
    private UserNotificationService $_userNotificationService;

    public function __construct(
        Hydro2 $app,
        ILoggerService $logger,
        IIdentityService $identityService,
        UserNotificationService $userNotificationService,
        Request $request,
    ) {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_identityService = $identityService;
        $this->_userNotificationService = $userNotificationService;
        parent::__construct($request);
    }
    
    #[Route(Method::GET, '')]
    public function ListUsers(): Response
    {
        // if current user doesn't have permission pivel/hydro2/viewusers/, return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ViewUsers->value)) {
            return new Response(status: StatusCode::NotFound);
        }
        
        if (isset($this->request->Args['sort_by'])) {
            if ($this->request->Args['sort_by'] == 'role') {
                $this->request->Args['sort_by'] = 'user_role_id';
            }
            if ($this->request->Args['sort_by'] == 'created') {
                $this->request->Args['sort_by'] = 'inserted';
            }
        }

        $query = Query::SortSearchPageQueryFromRequest($this->request, searchField:"name");

        // TODO implement filtering for more fields?
        if (isset($this->request->Args['role_id'])) {
            $query->Equal('user_role_id', $this->request->Args['role_id']);
        }

        $users = $this->_identityService->GetUsersMatchingQuery($query);

        return new JsonResponse($users);
    }

    #[Route(Method::CLI, '~CreateAdminUser')]
    public function CreateAdminUserCLI(): void
    {
        // this should only be used on initial setup to create the first user with sufficient permissions to manage users/roles
        //  or for recovery purposes if unable to access a user account with sufficient privileges.
        // this function does not create a security vulnerability, because an actor that is able to run this command could also
        //  just read the database credentials directly.
        if (!isset($this->request->Args['email']) || !isset($this->request->Args['password'])) {
            $this->_logger->Error("Pivel/Hydro2", "CreateAdminUser command was run without required arguments. Exiting.");
            echo("Error: Missing required arguments. Usage: php hydro2 CreateAdminUser email=<email> password=<password>\n");
            return;
        }
        $email = $this->request->Args['email'];
        $password = $this->request->Args['password'];

        // 2.a. check if user with this email already exists. If so, return error.
        if ($this->_identityService->IsEmailInUseByUser($email)) {
            $this->_logger->Error("Pivel/Hydro2", "Error creating user. A user with this email already exists. Exiting.");
            echo("Error creating user. A user with this email already exists. Exiting.\n");
            return;
        }

        // 1. create a user role with ViewUsers, CreateUsers, ManageUsers, CreateUserRoles, ManageUserRoles, ManageOutboundEmailProfiles
        //     creating a new user role each time is OK, since it should either be the first/Admin role anyways or the user can delete it later.
        $this->_logger->Warn("Pivel/Hydro2", "CreateAdminUser command was run. If this was not intentional, please check your server logs and consider changing your database credentials.");
        $this->_logger->Info("Pivel/Hydro2", "Creating role \"CLI Admin\" with permissions ViewUsers, CreateUsers, ManageUsers, CreateUserRoles, ManageUserRoles, ManageOutboundEmailProfiles...");
        echo("Creating role \"CLI Admin\" with permissions ViewUsers, CreateUsers, ManageUsers, CreateUserRoles, ManageUserRoles, ManageOutboundEmailProfiles...\n");
        $role = new UserRole(
            name: "CLI Admin",
        );
        if ($this->_identityService->CreateNewUserRole($role) === null) {
            $this->_logger->Error("Pivel/Hydro2", "Error creating role. Exiting.");
            echo("Error creating role. Exiting.\n");
            return;
        }

        if (
            !$role->GrantPermission(Permissions::ViewAdminPanel->value) ||
            !$role->GrantPermission(Permissions::ViewUsers->value) ||
            !$role->GrantPermission(Permissions::CreateUsers->value) ||
            !$role->GrantPermission(Permissions::ManageUsers->value) ||
            !$role->GrantPermission(Permissions::CreateUserRoles->value) ||
            !$role->GrantPermission(Permissions::ManageUserRoles->value) ||
            !$role->GrantPermission(Permissions::ManageOutboundEmailProfiles->value)
            ) {
            $this->_logger->Error("Pivel/Hydro2", "Error granting permissions to role. Exiting.");
            echo("Error granting permissions to role. Exiting.\n");
            return;
        }

        $this->_logger->Info("Pivel/Hydro2", "Successfully created role \"CLI Admin\".");
        echo("Successfully created role \"CLI Admin\" with Id {$role->Id}.\n");

        // 2. create a user with the user role, with verification manually passed.
        $this->_logger->Info("Pivel/Hydro2", "Creating user \"CLI Admin\" with email \"{$email}\"...");
        echo("Creating user with email \"{$email}\"...\n");
        $user = $this->_identityService->CreateNewUser(
            email: $email,
            name: "CLI Admin",
            role: $role,
            isEnabled: true,
        );
        if ($user === null) {
            $this->_logger->Error("Pivel/Hydro2", "Error creating user. Exiting.");
            echo("Error creating user. Exiting.\n");
            return;
        }
        $user->EmailVerified = true;
        if (!$this->_identityService->UpdateUser($user)) {
            $this->_logger->Error("Pivel/Hydro2", "Error trying to skip email verification. Exiting.");
            echo("Error trying to skip email verification. Exiting.\n");
            return;
        }

        $createdUser = $this->_identityService->GetUserFromId($user->Id);

        // 3. Set password
        if (!$createdUser->SetNewPassword($password)) {
             $this->_logger->Error("Pivel/Hydro2", "Error setting user password. Exiting.");
             echo("Error setting user password. Exiting.\n");
             return;
        }
        // TODO send an email to a server notifications channel to alert that this command was used
        
        $this->_logger->Warn("Pivel/Hydro2", "Successfully created user \"CLI Admin\".");
        echo("Successfully created user \"CLI Admin\".\n");
    }

    #[Route(Method::POST, '')]
    public function CreateUser(): Response
    {
        // if current user doesn't have permission pivel/hydro2/viewusers/, return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::CreateUsers->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        // need to provide email, name, and role (optional)
        if (empty($this->request->Args['email'])) {
            return new JsonResponse(
                new ErrorMessage('users-0001', 'Missing parameter "email"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }
        if (!isset($this->request->Args['name'])) {
            return new JsonResponse(
                new ErrorMessage('users-0002', 'Missing parameter "name"', 'Argument is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        // check that provided role (optional) exists. If not, return error. If so, proceed using the default new user role.
        $roleId = $this->request->Args['role_id']??null;
        $role = null;
        if ($roleId !== null) {
            $role = $this->_identityService->GetUserRoleFromId($roleId);
            if ($role === null) {
                return new JsonResponse(
                    new ErrorMessage('users-0003', 'Invalid parameter "role_id"', 'This user role doesn\'t exist.'),
                    status: StatusCode::UnprocessableEntity,
                );
            }
        } else {
            $role = $this->_identityService->GetDefaultNewUserRole();
        }

        // check that there isn't already a User with this email
        if ($this->_identityService->IsEmailInUseByUser($this->request->Args['email'])) {
            return new JsonResponse(
                new ErrorMessage('users-0004', 'Invalid parameter "email"', 'A user already exists with this email.'),
                status: StatusCode::BadRequest,
            );
        }

        $newUser = $this->_identityService->CreateNewUser(
            email: $this->request->Args['email'],
            name: $this->request->Args['name'],
            isEnabled: $this->request->Args['enabled']??true,
            role: $role,
        );

        if ($newUser === null) {
            return new JsonResponse(
                new ErrorMessage('users-0005', 'Error creating user', 'Error creating user.'),
                status: StatusCode::InternalServerError,
            );
        }

        $newUser->NeedsReview = $this->request->Args['needs_review']??false;

        $view = new NewUserVerificationEmailView($this->_identityService->GetEmailVerificationUrl($this->request, $newUser, true), $newUser->Name);
        if (!$this->_userNotificationService->SendEmailToUser($newUser, $view)) {
            $this->_logger->Error("Pivel/Hydro2", "Unable to send validation email for user {$newUser->Id}.");
        }

        return new JsonResponse(
            $newUser,
            status: StatusCode::Created,
            headers: [
                'Location' => $this->request->fullUrl . "/{$newUser->Id}",
            ],
        );
    }

    #[Route(Method::GET, '{uuid}')]
    public function ListUser(): Response
    {
        // if current user doesn't have permission pivel/hydro2/viewusers/, return 404,
        //  unless trying to get info about self which is always allowed
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ViewUsers->value) ||
            $requestUser->Id === ($this->request->Args['uuid'])
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']);

        if ($user === null) {
            return new Response(status: StatusCode::NotFound);
        }

        return new JsonResponse($user);
    }

    #[Route(Method::POST, '{uuid}')]
    public function UpdateUser(): Response
    {
        // if current user doesn't have permission pivel/hydro2/manageuser, return 404,
        //  unless trying to modify self (if without manageuser permission, can only edit a restricted set of properties)
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUsers->value) ||
            $requestUser->Id === ($this->request->Args['uuid'])
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']);

        if ($user === null) {
            return new Response(status: StatusCode::NotFound);
        }

        $email = empty($this->request->Args['email'])?$user->Email:$this->request->Args['email'];
        $emailChanged = $email !== $user->Email;
        // check that there isn't already a User with this email
        if ($emailChanged && $this->_identityService->IsEmailInUseByUser($email)) {
            return new JsonResponse(
                new ErrorMessage('users-0004', 'Invalid parameter "email"', 'A user already exists with this email.'),
                status: StatusCode::BadRequest,
            );
        }
        $oldEmail = $user->Email;
        $user->Email = $email;
        $user->EmailVerified = $user->EmailVerified && (!$emailChanged);
        $user->Name = $this->request->Args['name']??$user->Name;

        // the following fields cannot be edited by self/without manageuser permissions:
        //  Role
        //  NeedsReview
        //  Enabled
        //  FailedLogin/2FAAttempts (to unlock a user's login attempts)        
        if ($requestUser->GetUserRole()->HasPermission(Permissions::ManageUsers->value)) {
            $roleId = $this->request->Args['role_id']??null;
            if ($roleId !== null) {
                $user->SetUserRole($this->_identityService->GetUserRoleFromId($roleId));
                if ($user->GetUserRole() === null) {
                    return new JsonResponse(
                        new ErrorMessage('users-0007', 'Invalid parameter "role_id"', 'This user role doesn\'t exist.'),
                        status: StatusCode::BadRequest,
                    );
                }
            }

            $user->NeedsReview = $this->request->Args['needs_review']??$user->NeedsReview;
            $user->Enabled = $this->request->Args['enabled']??$user->Enabled;

            if ($this->request->Args['reset_failed_login_attempts']??false) {
                $user->FailedLoginAttempts = 0;
            }

            if ($this->request->Args['reset_failed_2fa_attempts']??false) {
                $user->Failed2FAAttempts = 0;
            }
        }

        if (!$this->_identityService->UpdateUser($user)) {
            return new JsonResponse(
                new ErrorMessage('users-0008', 'Failed to update user', 'Failed to update user.'),
                status: StatusCode::InternalServerError,
            );
        }

        if ($emailChanged) {
            $newEmailView = new NewUserVerificationEmailView($this->_identityService->GetEmailVerificationUrl($this->request, $user, true), $user->Name);
            $oldEmailView = new NewEmailNotificationEmailView($user->Name, $oldEmail, $user->Email);
            if (!(
                $this->_userNotificationService->SendEmailToUser($user, $newEmailView) &&
                $this->_userNotificationService->SendEmailToUser(new User($oldEmail, $user->Name), $oldEmailView)
            )) {
                $this->_logger->Error("Pivel/Hydro2", "Unable to send validation email for user {$user->Id}.");
            }
        }

        return new JsonResponse(status: StatusCode::NoContent);
    }

    #[Route(Method::DELETE, '{uuid}')]
    public function DeleteUser(): Response
    {
        // if current user doesn't have permission pivel/hydro2/viewusers/, return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageUsers->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']);

        if ($user === null) {
            return new Response(status: StatusCode::NotFound);
        }

        if (!$this->_identityService->DeleteUser($user)) {
            return new JsonResponse(
                new ErrorMessage('users-0009', 'Failed to delete user', 'Failed to delete user.'),
                status: StatusCode::InternalServerError,
            );
        }

        // TODO send notification email to user when account is deleted.

        return new Response(status: StatusCode::NoContent);
    }

    // TODO add 2FA for changing passwords if set up
    #[Route(Method::POST, '{uuid}/changepassword')]
    #[Route(Method::POST, '~api/hydro2/identity/changeuserpassword')]
    public function UserChangePassword(): Response
    {
        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']??'');
        if ($user === null) {
            $user = $this->_identityService->GetUserFromEmail($this->request->Args['email']??'');
        }
        if ($user === null) {
            $user = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        }

        if ($user === null || $user->Id == $this->_identityService->GetVisitorUser()->Id) {
            return new Response(status: StatusCode::NotFound);
        }

        // check that either the existing password or a valid passwordreset token was provided
        $password = $this->request->Args['password']??null;
        $reset_token = $this->request->Args['reset_token']??null;
        if (!($password === null || $reset_token === null)) {
            return new JsonResponse(
                [
                    new ErrorMessage('users-0010', 'Missing argument "password"', 'Either the user\'s current password or a valid reset token are required.'),
                    new ErrorMessage('users-0011', 'Missing argument "reset_token"', 'Either the user\'s current password or a valid reset token are required.'),
                ],
                status: StatusCode::BadRequest,
            );
        }
        if ($password !== null && !$user->CheckPassword($password)) {
            return new JsonResponse(
                new ErrorMessage('users-0012', 'Invalid argument "password"', 'The provided password is incorrect.'),
                status: StatusCode::BadRequest,
            );
        }
        if ($reset_token !== null && !$user->CheckPasswordResetToken($reset_token)) {
            return new JsonResponse(
                new ErrorMessage('users-0013', 'Invalid argument "reset_token"', 'The provided password reset token is incorrect, expired, or already used.'),
                status: StatusCode::BadRequest,
            );
        }

        if ($reset_token !== null) {
            // need to set the token as used.
            $user->SetPasswordResetTokenAsUsed($reset_token);
        }

        // check that the new password was provided.
        if (!isset($this->request->Args['new_password'])) {
            return new JsonResponse(
                new ErrorMessage('users-0014', 'Missing argument "new_password"', 'The user\'s new password is missing.'),
                status: StatusCode::BadRequest,
            );
        }

        // send notification email. If unable to send, fail to change password.
        $emailView = new PasswordChangedNotificationEmailView($user->Name);
        if (!$this->_userNotificationService->SendEmailToUser($user, $emailView)) {
            return new JsonResponse(
                new ErrorMessage('users-0015', 'Failed to send email', 'Failed to send notification email.'),
                status: StatusCode::InternalServerError,
            );
        }

        if (!$user->SetNewPassword($this->request->Args['new_password'])) {
            return new JsonResponse(
                new ErrorMessage('users-0016', 'Failed to change password', 'Failed to change password.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::POST, '{uuid}/sendpasswordreset')]
    #[Route(Method::POST, '~api/hydro2/identity/sendpasswordreset')]
    public function UserSendResetPassword(): Response
    {
        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']??'');
        if ($user === null) {
            $this->_logger->Debug("Pivel/Hydro2", "Finding user from email \"{$this->request->Args['email']}\"");
            $user = $this->_identityService->GetUserFromEmail($this->request->Args['email']??'');
        }

        if ($user === null) {
            return new Response(status: StatusCode::NotFound);
        }

        $token = $user->CreateNewPasswordResetToken();
        if ($token === null) {
            return new JsonResponse(
                new ErrorMessage('users-0017', 'Failed to send password reset', 'Failed to send password reset.'),
                status: StatusCode::InternalServerError,
            );
        }

        // send reset email
        $emailView = new PasswordResetEmailView($this->_identityService->GetPasswordResetUrl($this->request, $user, $token), $user->Name, 10);
        if (!$this->_userNotificationService->SendEmailToUser($user, $emailView)) {
            return new JsonResponse(
                new ErrorMessage('users-0018', 'Failed to send password reset', 'Failed to send password reset.'),
                status: StatusCode::InternalServerError,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::GET, '~verifyuseremail/{uuid}')]
    #[Route(Method::GET, '~verifyuseremail')]
    public function UserVerify(): Response {
        $view = new VerifyView(false);
        if (!isset($this->request->Args['token'])) {
            // missing argument
            return new Response(
                content:$view->Render($this->_app),
            );
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']??'');

        if ($user === null) {
            return new Response(
                content:$view->Render($this->_app),
            );
        }
        
        $view->SetUserId($this->request->Args['uuid']);

        if (!$user->ValidateEmailVerificationToken($this->request->Args['token'])) {
            return new Response(
                content:$view->Render($this->_app),
            );
        }

        $user->EmailVerified = true;

        if (!$this->_identityService->UpdateUser($user)) {
            return new Response(
                status:StatusCode::InternalServerError,
                content:"This verification link is valid, but there was a problem with the database.",
            );
        }

        // If the user has not yet set a password, generate password reset token.
        if ($user->IsPasswordChangeRequired()) {
            $token = $user->CreateNewPasswordResetToken();
            if ($token == null) {
                return new Response(
                    status:StatusCode::InternalServerError,
                    content:"This verification link is valid, but there was a problem with the database.",
                );
            }

            $view->SetIsPasswordChangeRequired(true);
            $view->SetPasswordResetToken($token);
        }

        $view->SetIsValid(true);

        return new Response(
            content:$view->Render($this->_app),
        );
    }

    #[Route(Method::GET, '~resetpassword/{uuid}')]
    #[Route(Method::GET, '~resetpassword')]
    public function UserResetPasswordView() : Response {
        $view = new ResetView(false);
        if (!isset($this->request->Args['token'])) {
            // missing argument
            return new Response(
                content:$view->Render($this->_app),
            );
        }

        $user = $this->_identityService->GetUserFromId($this->request->Args['uuid']??'');
        if ($user === null) {
            return new Response(
                content:$view->Render($this->_app),
            );
        }
        
        $view->SetUserId($user->Id);

        if (!$user->CheckPasswordResetToken($this->request->Args['token']??'')) {
            return new Response(
                content:$view->Render($this->_app),
            );
        }

        $view->SetPasswordResetToken($this->_identityService->GetPasswordResetTokenFromString($this->request->Args['token']??''));
        $view->SetIsValid(true);

        return new Response(
            content:$view->Render($this->_app),
        );
    }
}