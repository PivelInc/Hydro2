<?php

namespace Pivel\Hydro2\Controllers;

use LightningTransport\TaxiSite\Views\NotFound;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Extensions\Route;
use Pivel\Hydro2\Extensions\RoutePrefix;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\ErrorMessage;
use Pivel\Hydro2\Models\HTTP\JsonResponse;
use Pivel\Hydro2\Models\HTTP\Method;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\Response;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Models\Identity\UserPermission;
use Pivel\Hydro2\Models\Identity\UserRole;
use Pivel\Hydro2\Models\Permissions;
use Pivel\Hydro2\Services\Identity\IIdentityService;

#[RoutePrefix('api/hydro2/identity/userroles')]
class UserRoleController extends BaseController
{
    protected IIdentityService $_identityService;

    public function __construct(
        IIdentityService $identityService,
        Request $request,
    )
    {
        $this->_identityService = $identityService;
        parent::__construct($request);
    }
    
    #[Route(Method::GET, '')]
    public function GetUserRoles(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUserRoles->value) ||
            $requestUser->GetUserRole()->HasPermission(Permissions::CreateUsers->value) ||
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUsers->value)
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $query = Query::SortSearchPageQueryFromRequest($this->request, searchField:"name");

        $userRoles = $this->_identityService->GetUserRolesMatchingQuery($query);

        return new JsonResponse($userRoles);
    }

    #[Route(Method::POST, '')]
    public function CreateUserRole(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::CreateUserRoles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $userRole = new UserRole(
            name: $this->request->Args['name'] ?? 'New Role',
            description: $this->request->Args['description'] ?? '',
            maxLoginAttempts: intval($this->request->Args['max_login_attempts'] ?? 5),
            maxSessionLengthMinutes: intval($this->request->Args['max_session_length_minutes'] ?? 43200),
            daysUntil2FASetupRequired: intval($this->request->Args['days_until_2fa_setup_required'] ?? 3),
            challengeIntervalMinutes: intval($this->request->Args['challenge_interval'] ?? 21600),
            max2FAAttempts: intval($this->request->Args['max_2fa_attempts'] ?? 5),
        );

        $requestedPermissions = $this->request->Args['permissions']??[];
        $availablePermissions = $this->_identityService->GetAvailablePermissions();

        // validate permissions. can't add yet since we don't know that the new UserRole's ID will be.
        $missingPermissions = [];
        foreach ($requestedPermissions as $permission) {
            if (!isset($availablePermissions[$permission])) {
                $missingPermissions[] = new ErrorMessage('userroles-0001', 'Invalid parameter "permission"', "The permission '{$permission}' doesn't exist.");
            }
        }
        if (count($missingPermissions) > 0) {
            return new JsonResponse(
                $missingPermissions,
                status: StatusCode::UnprocessableEntity,
            );
        }

        if ($this->_identityService->CreateNewUserRole($userRole) === null) {
            return new JsonResponse(
                new ErrorMessage('userroles-0002', 'Failed to create user role', "Failed to create user role."),
                status: StatusCode::InternalServerError,
            );
        }

        // now we can add the permissions.
        foreach ($requestedPermissions as $permission) {
            if (!$userRole->GrantPermission($permission)) {
                return new JsonResponse(
                    new ErrorMessage('userroles-0003', 'Failed to grant permission', "Failed to grant permission '{$permission}'."),
                    status: StatusCode::InternalServerError,
                );
            }
        }

        return new JsonResponse(
            $userRole,
            status: StatusCode::Created,
            headers: [
                'Location' => $this->request->fullUrl . "/{$userRole->Id}",
            ],
        );
    }

    #[Route(Method::GET, '{id}')]
    public function GetUserRole(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUserRoles->value) ||
            $requestUser->GetUserRole()->HasPermission(Permissions::CreateUsers->value) ||
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUsers->value)
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $userRole = $this->_identityService->GetUserRoleFromId($this->request->Args['id']);

        if ($userRole === null) {
            return new Response(status: StatusCode::NotFound);
        }

        return new JsonResponse($userRole);
    }

    #[Route(Method::POST, '{id}')]
    public function UpdateUserRole(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageUserRoles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $userRole = $this->_identityService->GetUserRoleFromId($this->request->Args['id']);

        if ($userRole === null) {
            return new Response(status: StatusCode::NotFound);
        }

        $userRole->Name = $this->request->Args['name']??$userRole->Name;
        $userRole->Description = $this->request->Args['description']??$userRole->Description;
        $userRole->MaxLoginAttempts = intval($this->request->Args['max_login_attempts']??$userRole->MaxLoginAttempts);
        $userRole->MaxSessionLengthMinutes = intval($this->request->Args['max_session_length_minutes']??$userRole->MaxSessionLengthMinutes);
        $userRole->DaysUntil2FASetupRequired = intval($this->request->Args['days_until_2fa_setup_required']??$userRole->DaysUntil2FASetupRequired);
        $userRole->ChallengeIntervalMinutes = intval($this->request->Args['challenge_interval']??$userRole->ChallengeIntervalMinutes);
        $userRole->Max2FAAttempts = intval($this->request->Args['max_2fa_attempts']??$userRole->Max2FAAttempts);

        $requestedPermissions = $this->request->Args['permissions']??[];
        $availablePermissions = $this->_identityService->GetAvailablePermissions();

        $missingPermissions = [];
        foreach ($requestedPermissions as $permission) {
            if (!isset($availablePermissions[$permission])) {
                $missingPermissions[] = new ErrorMessage('userroles-0001', 'Invalid parameter "permission"', "The permission '{$permission}' doesn't exist.");
            }
        }
        if (count($missingPermissions) > 0) {
            return new JsonResponse(
                $missingPermissions,
                status: StatusCode::UnprocessableEntity,
            );
        }

        if (!$this->_identityService->UpdateUserRole($userRole)) {
            return new JsonResponse(
                new ErrorMessage('userroles-0004', 'Failed to update user role', "Failed to update user role."),
                status: StatusCode::InternalServerError,
            );
        }

        // add new permissions
        foreach ($requestedPermissions as $permissionKey) {
            if (!$userRole->GrantPermission($permissionKey)) {
                return new JsonResponse(
                    new ErrorMessage('userroles-0003', 'Failed to grant permission', "Failed to grant permission '{$permission}'."),
                    status: StatusCode::InternalServerError,
                );
            }
        }
        // remove permissions
        foreach ($userRole->GetPermissions() as $permission) {
            if (in_array($permission->PermissionKey, $requestedPermissions)) {
                continue;
            }
            if (!$userRole->DenyPermission($permission->PermissionKey)) {
                return new JsonResponse(
                    new ErrorMessage('userroles-0005', 'Failed to deny permission', "Failed to deny permission '{$permission->PermissionKey}'."),
                    status: StatusCode::InternalServerError,
                );
            }
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::DELETE, '{id}')]
    public function DeleteUserRole(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!$requestUser->GetUserRole()->HasPermission(Permissions::ManageUserRoles->value)) {
            return new Response(status: StatusCode::NotFound);
        }

        $userRole = $this->_identityService->GetUserRoleFromId($this->request->Args['id']);

        if ($userRole === null) {
            return new Response(status: StatusCode::NotFound);
        }

        if ($userRole->GetUserCount() != 0) {
            return new JsonResponse(
                new ErrorMessage('userroles-0006', 'Unable to delete user role', "Cannot delete a role while there are users with this role."),
                status: StatusCode::Conflict,
            );
        }

        if (!$this->_identityService->DeleteUserRole($userRole)) {
            return new JsonResponse(
                new ErrorMessage('userroles-0007', 'Failed to delete user role', "Failed to delete user role."),
                status: StatusCode::InternalServerError,
            );
        }

        return new Response(status: StatusCode::NoContent);
    }

    #[Route(Method::GET, '~api/hydro2/identity/permissions')]
    public function GetPermissions(): Response
    {
        // if current user doesn't have permission , return 404
        $requestUser = $this->_identityService->GetUserFromRequestOrVisitor($this->request);
        if (!(
            $requestUser->GetUserRole()->HasPermission(Permissions::ManageUserRoles->value) ||
            $requestUser->GetUserRole()->HasPermission(Permissions::CreateUserRoles->value)
        )) {
            return new Response(status: StatusCode::NotFound);
        }

        $permissions = $this->_identityService->GetAvailablePermissions();

        return new JsonResponse(array_values($permissions));
    }
}