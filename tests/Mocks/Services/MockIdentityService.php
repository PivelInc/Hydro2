<?php

namespace Mocks\Services;

use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\Identity\PasswordResetToken;
use Pivel\Hydro2\Models\Identity\Permission;
use Pivel\Hydro2\Models\Identity\Session;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserRole;
use Pivel\Hydro2\Services\Identity\IIdentityService;

class MockIdentityService implements IIdentityService
{
    public User $user;
    /** @var User[] */
    public array $allUsers;
    /** @var UserRole[] */
    public array $allUserRoles;
    /** @var Permission[] */
    public array $availPermissions;

    public ?UserRole $userRole;

    public ?Query $lastRequestedQuery = null;

    public bool $beSuccessful = false;

    public int $pkNextId = 1;

    public string $fakePermissionToGrantNewRole = '';

    public function __construct()
    {
        $this->user = new User(role: $this->GetVisitorUserRole());
        $this->allUsers = [$this->user];
        $this->allUserRoles = [];
        $this->availPermissions = [];
        $this->userRole = null;
    }

    // ==== User-related methods ====
    public function GetVisitorUser(): User
    {
        return $this->user;
    }
    public function GetUserFromRequestOrVisitor(Request $request): User
    {
        return $this->user;
    }
    public function IsEmailInUseByUser(string $email): bool
    {
        return false;
    }
    public function CreateNewUser(string $email, string $name, bool $isEnabled, UserRole $role): ?User
    {
        //$role->Id = $this->pkNextId++;
        return null;
    }
    public function UpdateUser(User &$user): bool
    {
        return false;
    }
    public function DeleteUser(User $user): bool
    {
        return false;
    }
    public function GetUsersMatchingQuery(Query $query): array
    {
        $this->lastRequestedQuery = $query;
        return $this->allUsers;
    }
    public function GetEmailVerificationUrl(Request $request, User $user, bool $regenerate=false): string
    {
        return '';
    }
    public function GetUserFromRandomId(string $randomId): ?User
    {
        return $this->user;
    }
    public function GetUserFromEmail(string $email): ?User
    {
        return $this->user;
    }
    public function GetUserFromId(string $randomId): ?User
    {
        return $this->user;
    }
    
    // ==== Session-related methods ====
    public function GetSessionFromRequest(Request $request, $random_id=null, $key=null, $ignore_browser=false): ?Session
    {
        return null;
    }
    public function GetSessionFromRandomId(string $randomId): ?Session
    {
        return null;
    }
    public function StartSession(User $user, Request $request): Session
    {
        return new Session();
    }
    public function ExpireSession(Session &$session): bool
    {
        return false;
    }

    // ==== UserRole-related methods ====
    public function GetVisitorUserRole(): UserRole
    {
        return new UserRole();
    }
    public function GetDefaultNewUserRole(): UserRole
    {
        return new UserRole();
    }
    public function GetUserRoleFromId(int $id): ?UserRole
    {
        return $this->userRole;
    }
    public function GetUserRolesMatchingQuery(Query $query): array
    {
        $this->lastRequestedQuery = $query;
        return $this->allUserRoles;
    }
    public function CreateNewUserRole(UserRole &$role): ?UserRole
    {
        $role->Id = $this->pkNextId++;
        if ($this->beSuccessful) {
            $role->GrantPermission($this->fakePermissionToGrantNewRole);
        }

        return $this->beSuccessful ? $role : null;
    }
    public function UpdateUserRole(UserRole &$role): bool
    {
        return $this->beSuccessful;
    }
    public function DeleteUserRole(UserRole $role): bool
    {
        return $this->beSuccessful;
    }

    // ==== PasswordResetToken-related methods ====
    public function GetPasswordResetTokenFromString(string $token): ?PasswordResetToken
    {
        return null;
    }
    public function GetPasswordResetUrl(Request $request, User $user, PasswordResetToken $token): string
    {
        return '';
    }

    // ============================
    public function GetAvailablePermissions(): array
    {
        return $this->availPermissions;
    }
}