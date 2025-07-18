<?php

namespace Pivel\Hydro2\Services\Identity;

use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\Identity\PasswordResetToken;
use Pivel\Hydro2\Models\Identity\Session;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserRole;

interface IIdentityService
{
    // ==== User-related methods ====
    public function GetVisitorUser(): User;
    public function GetUserFromRequestOrVisitor(Request $request): User;
    public function IsEmailInUseByUser(string $email): bool;
    public function CreateNewUser(string $email, string $name, bool $isEnabled, UserRole $role): ?User;
    public function UpdateUser(User &$user): bool;
    public function DeleteUser(User $user): bool;
    /**
     * @return User[]
     */
    public function GetUsersMatchingQuery(Query $query): array;
    public function GetEmailVerificationUrl(Request $request, User $user, bool $regenerate=false): string;
    public function GetUserFromId(string $randomId): ?User;
    public function GetUserFromEmail(string $email): ?User;
    
    // ==== Session-related methods ====
    public function GetSessionFromRequest(Request $request, $random_id=null, $key=null, $ignore_browser=false): ?Session;
    public function GetSessionFromRandomId(string $randomId): ?Session;
    public function StartSession(User $user, Request $request): ?Session;
    public function ExpireSession(Session &$session): bool;

    // ==== UserRole-related methods ====
    public function GetVisitorUserRole(): UserRole;
    public function GetDefaultNewUserRole(): UserRole;
    public function GetUserRoleFromId(int $id): ?UserRole;
    /**
     * @return UserRole[]
     */
    public function GetUserRolesMatchingQuery(Query $query): array;
    public function CreateNewUserRole(UserRole &$role): ?UserRole;
    public function UpdateUserRole(UserRole &$role): bool;
    public function DeleteUserRole(UserRole $role): bool;

    // ==== PasswordResetToken-related methods ====
    public function GetPasswordResetTokenFromString(string $token): ?PasswordResetToken;
    public function GetPasswordResetUrl(Request $request, User $user, PasswordResetToken $token): string;

    // ============================
    /** @return Permission[] */
    public function GetAvailablePermissions(): array;
}