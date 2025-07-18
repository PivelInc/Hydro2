<?php

namespace Pivel\Hydro2\Services;

use DateTime;
use DateTimeZone;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\Identity\PasswordResetToken;
use Pivel\Hydro2\Models\Identity\Permission;
use Pivel\Hydro2\Models\Identity\Session;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserRole;
use Pivel\Hydro2\Services\Entity\IEntityRepository;
use Pivel\Hydro2\Services\Entity\IEntityService;
use Pivel\Hydro2\Services\Identity\IIdentityService;

class IdentityService implements IIdentityService
{
    private PackageManifestService $_manifestService;
    private ILoggerService $_logger;
    private IEntityService $_entityService;

    private IEntityRepository $userRepository;
    private IEntityRepository $userRoleRepository;
    private IEntityRepository $sessionRepository;
    private IEntityRepository $resetTokenRepository;

    public function __construct(
        PackageManifestService $manifestService,
        ILoggerService $logger,
        IEntityService $entityService,
    ) {
        $this->_manifestService = $manifestService;
        $this->_logger = $logger;
        $this->_entityService = $entityService;

        $this->userRepository = $this->_entityService->GetRepository(User::class);
        $this->userRoleRepository = $this->_entityService->GetRepository(UserRole::class);
        $this->sessionRepository = $this->_entityService->GetRepository(Session::class);
        $this->resetTokenRepository = $this->_entityService->GetRepository(PasswordResetToken::class);
    }

    // ==== User-related methods ====

    public function GetVisitorUser(): User
    {
        $role = $this->GetVisitorUserRole();

        // TODO look up visitor user ID and get user with matching ID.
        $user = new User(name: 'Site Visitor', role: $role);

        return $user;
    }

    public function GetUserFromRequestOrVisitor(Request $request): User
    {
        $session = $this->GetSessionFromRequest($request);

        if ($session == null) {
            return $this->GetVisitorUser();
        }

        return $session->GetUser();
    }

    public function IsEmailInUseByUser(string $email): bool
    {
        $query = (new Query())->Equal('email', $email);
        $qty = $this->userRepository->Count($query);

        return $qty > 0;
    }

    public function CreateNewUser(string $email, string $name, bool $isEnabled, UserRole $role): ?User
    {
        $user = new User(email: $email, name: $name, enabled: $isEnabled, role: $role);
        $user->GenerateEmailVerificationToken();

        if (!$this->userRepository->Create($user)) {
            $this->_logger->Error('Pivel/Hydro2', "Failed to create new user with email address {$email}.");
            return null;
        }

        $this->_logger->Info('Pivel/Hydro2', "Created new user with email address {$email} and id {$user->Id}.");

        return $user;
    }

    public function UpdateUser(User &$user): bool
    {
        $success = $this->userRepository->Update($user);

        if ($success) {
            $this->_logger->Info('Pivel/Hydro2', "Updated user {$user->Id}.");
        } else {
            $this->_logger->Error('Pivel/Hydro2', "Failed to update user {$user->Id}.");
        }

        return $success;
    }

    public function DeleteUser(User $user): bool
    {
        // TODO check that this user is not:
        //  a) the user currently set as the Site Visitor
        //  b) the only remaining user with permission to both manage users and manage user roles (effectively, the global administrator)

        $success = $this->userRepository->Delete($user);

        if ($success) {
            $this->_logger->Warn('Pivel/Hydro2', "Deleted user {$user->Id}.");
        } else {
            $this->_logger->Error('Pivel/Hydro2', "Failed to delete user {$user->Id}.");
        }

        return $success;
    }

    /**
     * @return User[]
     */
    public function GetUsersMatchingQuery(Query $query): array
    {
        return $this->userRepository->Read($query);
    }

    public function GetEmailVerificationUrl(Request $request, User $user, bool $regenerate=false): string
    {
        if ($regenerate) {
            $user->GenerateEmailVerificationToken();
            $this->userRepository->Update($user);
            $this->_logger->Info('Pivel/Hydro2', "Generated new email verification URL for user {$user->Email}.");
        }
        $token = $user->GetEmailVerificationToken();
        $url = "{$request->baseUrl}/verifyuseremail/{$user->Id}?token={$token}";
        return $url;
    }

    public function GetUserFromId(string $uuid): ?User
    {
        $users = $this->GetUsersMatchingQuery((new Query())->Equal('uuid', $uuid));

        if (count($users) != 1) {
            return null;
        }

        return $users[0];
    }

    public function GetUserFromEmail(string $email): ?User
    {
        $users = $this->GetUsersMatchingQuery((new Query())->Equal('email', $email));

        if (count($users) != 1) {
            $this->_logger->Debug("Pivel/Hydro2", "Unable to find a user matching \"{$email}\"");
            return null;
        }

        return $users[0];
    }

    // ==== Session-related methods ====

    public function GetSessionFromRequest(Request $request, $random_id=null, $key=null, $ignore_browser=false): ?Session
    {
        $random_id_and_key = explode(';', $request->getCookie('sridkey', ""), 2);

        if (count($random_id_and_key) != 2 && $random_id === null && $key === null) {
            $this->_logger->Warn('Pivel/Hydro2', "No valid session cookie was provided from {$request->getClientAddress()}.");
            setcookie('sridkey', '', time()-3600, '/');
            return null;
        }

        $random_id ??= $random_id_and_key[0];
        $key ??= $random_id_and_key[1];

        /** @var Session[] */
        $sessions = $this->sessionRepository->Read((new Query())->Equal('random_id', $random_id));
        if (count($sessions) != 1) {
            $this->_logger->Warn('Pivel/Hydro2', "A nonexistant Session ID ({$random_id}) was provided from {$request->getClientAddress()}.");
            setcookie('sridkey', '', time()-3600, '/');
            return null;
        }
        
        if (!$sessions[0]->CompareKey($key)) {
            $this->_logger->Warn('Pivel/Hydro2', "A Session ID with an incorrect key was provided from {$request->getClientAddress()}.");
            setcookie('sridkey', '', time()-3600, '/');
            return null;
        }

        if (!$ignore_browser && ($sessions[0]->Browser !== $request->UserAgent)) {
            $this->_logger->Warn('Pivel/Hydro2', "A session previously used on {$sessions[0]->Browser} was attempted to be used on {$request->UserAgent} by {$request->getClientAddress()}.");
            setcookie('sridkey', '', time()-3600, '/');
            return null;
        }

        if (!$sessions[0]->IsValid()) {
            $this->_logger->Warn('Pivel/Hydro2', "A session that is no longer valid was attempted to be used from {$request->getClientAddress()}.");
            setcookie('sridkey', '', time()-3600, '/');
            return null;
        }

        // check if rehash is required
        $sessions[0]->RehashKeyIfRequired($key);
        
        $sessions[0]->LastAccessTime = new DateTime(timezone:new DateTimeZone('UTC'));
        $sessions[0]->LastIP = $request->getClientAddress();
        $this->sessionRepository->Update($sessions[0]);

        return $sessions[0];
    }

    public function GetSessionFromRandomId(string $randomId): ?Session
    {
        /** @var Session[] */
        $sessions = $this->sessionRepository->Read((new Query())->Equal('random_id', $randomId));
        if (count($sessions) != 1) {
            return null;
        }

        return $sessions[0];
    }

    public function StartSession(User $user, Request $request): ?Session
    {
        $sessionStarts = new DateTime(timezone:new DateTimeZone('UTC'));
        $sessionExpires = (clone $sessionStarts)->modify("+{$user->GetUserRole()->MaxSessionLengthMinutes} minutes");
        $session2FAExpires = $user->GetUserRole()->ChallengeIntervalMinutes>0?(clone $sessionStarts):null;

        // create new session
        $session = new Session(
            user: $user,
            browser: $request->UserAgent,
            startTime: $sessionStarts,
            expireTime: $sessionExpires,
            expire2FATime: $session2FAExpires,
            lastAccessTime: $sessionStarts,
            startIP: $request->getClientAddress(),
            lastIP: $request->getClientAddress(),
        );

        if (!$this->sessionRepository->Create($session)) {
            return null;
        }

        $this->_logger->Debug('Pivel/Hydro2', "User {$user->Email} started a new session.");

        return $session;
    }

    public function ExpireSession(Session &$session): bool
    {
        $now = new DateTime(timezone:new DateTimeZone('UTC'));
        $session->ExpireTime = $now;
        $this->_logger->Debug('Pivel/Hydro2', "User {$session->GetUser()->Email}' session was terminated.");
        return $this->sessionRepository->Update($session);
    }

    // ==== UserRole-related methods ====

    public function GetVisitorUserRole(): UserRole
    {
        // TODO look up visitor user role and get role with matching ID.
        $role = new UserRole(name: 'Site Visitor');

        return $role;
    }

    public function GetDefaultNewUserRole(): UserRole
    {
        // TODO look up new user default user role and get role with matching ID.
        $role = new UserRole(name: 'Site Visitor');

        return $role;
    }

    public function GetUserRoleFromId(int $id): ?UserRole
    {
        return $this->userRoleRepository->ReadById($id);
    }

    /**
     * @return UserRole[]
     */
    public function GetUserRolesMatchingQuery(Query $query): array
    {
        return $this->userRoleRepository->Read($query);
    }

    public function CreateNewUserRole(UserRole &$role): ?UserRole
    {
        if (!$this->userRoleRepository->Create($role)) {
            $this->_logger->Error('Pivel/Hydro2', "Failed to create new user role {$role->Name}.");
            return null;
        }

        $this->_logger->Info('Pivel/Hydro2', "Created new user role {$role->Name}.");

        return $role;
    }

    public function UpdateUserRole(UserRole &$role): bool
    {
        $success = $this->userRoleRepository->Update($role);

        if ($success) {
            $this->_logger->Info('Pivel/Hydro2', "Updated user role {$role->Name}.");
        } else {
            $this->_logger->Error('Pivel/Hydro2', "Failed to update user role {$role->Name}.");
        }

        return $success;
    }

    public function DeleteUserRole(UserRole $role): bool
    {
        // check that this user role is not:
        //  a) in use by any users
        if ($role->GetUserCount() != 0) {
            return false;
        }

        $success = $this->userRoleRepository->Delete($role);

        if ($success) {
            $this->_logger->Warn('Pivel/Hydro2', "Deleted user role {$role->Name}.");
        } else {
            $this->_logger->Error('Pivel/Hydro2', "Failed to delete user role {$role->Name}.");
        }

        return $success;
    }

    // ==== PasswordResetToken-related methods ====

    public function GetPasswordResetTokenFromString(string $token): ?PasswordResetToken
    {
        /** @var PasswordResetToken[] */
        $tokenObjs = $this->resetTokenRepository->Read((new Query())->Equal('reset_token', $token)->Limit(1));
        if (count($tokenObjs) != 1) {
            return null;
        }

        return $tokenObjs[0];
    }

    public function GetPasswordResetUrl(Request $request, User $user, PasswordResetToken $token): string
    {
        return "{$request->baseUrl}/resetpassword/{$user->Id}?token={$token->ResetToken}";
    }

    // ============================

    /** @return Permission[] */
    public function GetAvailablePermissions(): array
    {
        /** @var Permission[] $permissions */
        $permissions = [];
        $packageManifest = $this->_manifestService->GetPackageManifest();
        foreach ($packageManifest as $vendorName => $vendorPackages) {
            foreach ($vendorPackages as $packageName => $package) {
                if (!isset($package['permissions'])) {
                    continue;
                }

                // pivel/hydro2/viewusers

                foreach ($package['permissions'] as $permission) {
                    $permissions[strtolower($vendorName . '/' . $packageName . '/' . $permission['key'])] = new Permission(
                        $vendorName,
                        $packageName,
                        strtolower($permission['key']),
                        $permission['name']??$permission['key'],
                        $permission['description']??$permission['key'],
                        $permission['requires']??[],
                    );
                }
            }
        }

        return $permissions;
    }
}