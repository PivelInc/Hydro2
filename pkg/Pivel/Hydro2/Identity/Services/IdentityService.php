<?php

namespace Package\Pivel\Hydro2\Identity\Services;

use DateTime;
use DateTimeZone;
use Package\Pivel\Hydro2\Core\Models\Request;
use Package\Pivel\Hydro2\Core\Utilities;
use Package\Pivel\Hydro2\Email\Models\EmailAddress;
use Package\Pivel\Hydro2\Email\Models\EmailMessage;
use Package\Pivel\Hydro2\Email\Services\EmailService;
use Package\Pivel\Hydro2\Email\Views\BaseEmailView;
use Package\Pivel\Hydro2\Identity\Models\PasswordResetToken;
use Package\Pivel\Hydro2\Identity\Models\Permission;
use Package\Pivel\Hydro2\Identity\Models\Permissions;
use Package\Pivel\Hydro2\Identity\Models\Session;
use Package\Pivel\Hydro2\Identity\Models\User;
use Package\Pivel\Hydro2\Identity\Models\UserRole;

class IdentityService
{
    private static ?User $requestUser = null;
    public static function GetRequestUser(Request $request) : User {
        if (self::$requestUser === null) {
            self::$requestUser = self::GetDefaultVisitorUser();
            $session = self::GetRequestSession($request);
            if ($session !== false) {
                $user = $session->GetUser();
                if ($user !== null) {
                    self::$requestUser = $user;
                }
            }
        }

        return self::$requestUser;
    }

    private static Session|false $requestSession = false;
    public static function GetRequestSession(Request $request) : Session|false {
        if (self::$requestSession === false) {
            self::$requestSession = Session::LoadAndValidateFromRequest($request);
        }

        if (self::$requestSession === false) {
            setcookie('sridkey', '', time()-3600, '/');
        }

        return self::$requestSession;
    }

    // TODO get this from some kind of settings/configuration
    public static function GetDefaultUserRole() : UserRole {
        $role = UserRole::LoadFromId(1);
        if ($role == null) {
            $role = new UserRole('Default','Default Role');
            $role->Id = 1;
            $role->AddPermission(Permissions::ViewUsers->value);
            $role->AddPermission(Permissions::ManageUsers->value);
            $role->AddPermission(Permissions::CreateUsers->value);
            $role->AddPermission(Permissions::CreateUserRoles->value);
            $role->AddPermission(Permissions::ManageUserRoles->value);
            $role->AddPermission(Permissions::ViewUserSessions->value);
            $role->AddPermission(Permissions::EndUserSessions->value);
            $role->Save();
        }
        return $role;
    }

    public static function GetDefaultVisitorUser() {
        return new User(name:'Site Visitor',role:(new UserRole(name:'Site Visitor')));
    }

    public static function GetEmailVerificationUrl(Request $request, User $user, bool $regenerate=false) {
        if ($regenerate) {
            $user->GenerateEmailVerificationToken();
        }
        $token = $user->GetEmailVerificationToken();
        echo 'Base URL: "'.$request->baseUrl.'"';
        $url = "{$request->baseUrl}/verifyuseremail/{$user->RandomId}?token={$token}";
        return $url;
    }

    public static function SendEmailToUser(User $user, BaseEmailView $emailView) : bool {
        $emailProfileProvider = EmailService::GetOutboundEmailProviderInstance('noreply');
        if ($emailProfileProvider === null) {
            return false;
        }

        $emailMessage = new EmailMessage($emailView, [new EmailAddress($user->Email, $user->Name)]);
        return $emailProfileProvider->SendEmail($emailMessage);
    }

    public static function IsPasswordResetTokenValid(string $token, User $user) : bool {
        $token_obj = PasswordResetToken::LoadFromToken($token);
        if ($token_obj === null) {
            return false;
        }

        if (!$token_obj->CompareToken($token)) {
            return false;
        }

        if ($token_obj->UserId !== $user->Id) {
            return false;
        }

        $token_obj->Used = true;
        $token_obj->Save();

        return true;
    }

    public static function GetPasswordResetUrl(Request $request, User $user, PasswordResetToken $token) {
        $url = "{$request->baseUrl}/resetpassword/{$user->RandomId}?token={$token->ResetToken}";
        return $url;
    }

    /** @return Permission[] */
    public static function GetAvailablePermissions() : array {
        /** @var Permission[] $permissions */
        $permissions = [];
        $packageManifest = Utilities::getPackageManifest();
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