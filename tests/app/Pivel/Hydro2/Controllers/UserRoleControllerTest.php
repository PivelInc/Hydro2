<?php

namespace Tests;

use Mocks\Services\MockIdentityService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Pivel\Hydro2\Controllers\BaseController;
use Pivel\Hydro2\Controllers\UserRoleController;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Models\HTTP\StatusCode;
use Pivel\Hydro2\Models\Identity\Permission;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Identity\UserRole;
use Pivel\Hydro2\Models\Permissions;
use ReflectionClass;

#[CoversClass(UserRoleController::class)]
#[CoversClass(BaseController::class)]
#[UsesClass(UserRoleController::class)]
class UserRoleControllerTest extends TestCase
{
    public function testConstruction()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $this->assertInstanceOf(UserRoleController::class, $result);
    }

    // ==== GetUserRoles ====

    public function testGetUserRolesShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRoles();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testGetUserRolesShouldReturnArray()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->allUserRoles = [
            new UserRole('Fake Role 1'),
            new UserRole('Fake Role 2'),
        ];

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRoles();

        $this->assertEquals(StatusCode::OK, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertIsArray($responseValue);
    }

    public function testGetUserRolesShouldSetSortDirectionCorrectly()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['sort_by' => 'id', 'sort_dir' => 'desc']);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->allUserRoles = [
            $role,
            new UserRole('Fake Role 2'),
        ];

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRoles();

        $this->assertEquals(StatusCode::OK, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertIsArray($responseValue);
        $this->assertInstanceOf(Query::class, $mockIdentityService->lastRequestedQuery);
        $this->assertEquals(Order::Descending, $mockIdentityService->lastRequestedQuery->GetOrderTree()[0]['direction']);
    }

    // ==== CreateUserRole ====

    public function testCreateUserRoleShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->CreateUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testCreateUserRoleShouldReturnUnprocessableEntityIfInvalidPermission()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['permissions' => ['fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::CreateUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->CreateUserRole();

        $this->assertEquals(StatusCode::UnprocessableEntity, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0001", $responseValue[0]['code']);
    }

    public function testCreateUserRoleShouldReturnServerErrorIfCantCreate()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['permissions' => ['vendor/package/fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::CreateUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;
        $mockIdentityService->availPermissions = ['vendor/package/fakeValue' => new Permission('vendor','package','fakeValue','','')];

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->CreateUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0002", $responseValue['code']);
    }

    public function testCreateUserRoleShouldReturnServerErrorIfCantGrantPermissions()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['permissions' => ['vendor/package/fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::CreateUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;
        $mockIdentityService->availPermissions = ['vendor/package/fakeValue' => new Permission('vendor','package','fakeValue','','')];
        $mockIdentityService->beSuccessful = true;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->CreateUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0003", $responseValue['code']);
    }

    public function testCreateUserRoleShouldReturnArray()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['permissions' => ['vendor/package/fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::CreateUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;
        $mockIdentityService->availPermissions = ['vendor/package/fakeValue' => new Permission('vendor','package','fakeValue','','')];
        $mockIdentityService->beSuccessful = true;
        $mockIdentityService->fakePermissionToGrantNewRole = 'vendor/package/fakeValue';

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->CreateUserRole();

        $this->assertEquals(StatusCode::Created, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertIsArray($responseValue);
    }

    // ==== GetUserRole ====

    public function testGetUserRoleShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }
    
    public function testGetUserRoleShouldReturnNotFoundIfInvalidId()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }
    
    public function testGetUserRoleShouldReturnArray()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = $role;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetUserRole();

        $this->assertEquals(StatusCode::OK, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertIsArray($responseValue);
    }

    // ==== UpdateUserRole ====

    public function testUpdateUserRoleShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testUpdateUserRoleShouldReturnNotFoundIfInvalidRoleId()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testUpdateUserRoleShouldReturnUnprocessableEntityIfInvalidPermission()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1, 'permissions' => ['fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::UnprocessableEntity, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0001", $responseValue[0]["code"]);
    }

    public function testUpdateUserRoleShouldReturnServerErrorIfCantSave()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0004", $responseValue['code']);
    }

    public function testUpdateUserRoleShouldReturnServerErrorIfCantGrantPermissions()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1, 'permissions' => ['vendor/package/fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();
        //$mockIdentityService->userRole->GrantPermission('vendor/package/fakeValue2');
        $mockIdentityService->availPermissions = ['vendor/package/fakeValue' => new Permission('vendor','package','fakeValue','','')];
        $mockIdentityService->beSuccessful = true;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0003", $responseValue["code"]);
    }

    public function testUpdateUserRoleShouldReturnServerErrorIfCantDenyPermissions()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1, 'permissions' => []]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();
        $mockIdentityService->userRole->GrantPermission('vendor/package/fakeValue');
        $mockIdentityService->beSuccessful = true;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0005", $responseValue['code']);
    }

    public function testUpdateUserRoleShouldReturnNoContent()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1, 'permissions' => ['vendor/package/fakeValue']]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->availPermissions = ['vendor/package/fakeValue' => new Permission('vendor','package','fakeValue','','')];
        $mockIdentityService->userRole = new UserRole();
        $mockIdentityService->userRole->GrantPermission('vendor/package/fakeValue');
        $mockIdentityService->beSuccessful = true;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->UpdateUserRole();

        $this->assertEquals(StatusCode::NoContent, $response->getStatus());
    }

    // ==== DeleteUserRole ====

    public function testDeleteUserRoleShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->DeleteUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testDeleteUserRoleShouldReturnNotFoundIfInvalidRoleId()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = null;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->DeleteUserRole();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testDeleteUserRoleShouldReturnConflictIfUsersExist()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();
        $rc = new ReflectionClass(UserRole::class);
        $p = $rc->getProperty('tempUsers');
        $p->setValue($mockIdentityService->userRole, [$mockIdentityService->user]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->DeleteUserRole();

        $this->assertEquals(StatusCode::Conflict, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0006", $responseValue["code"]);
    }

    public function testDeleteUserRoleShouldReturnServerErrorIfCantDelete()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->DeleteUserRole();

        $this->assertEquals(StatusCode::InternalServerError, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertEquals("userroles-0007", $responseValue['code']);
    }

    public function testDeleteUserRoleShouldReturnStatusNoContent()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([], post: ['id' => 1]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );
        $mockIdentityService->userRole = new UserRole();
        $mockIdentityService->beSuccessful = true;

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->DeleteUserRole();

        $this->assertEquals(StatusCode::NoContent, $response->getStatus());
    }

    // ==== GetPermissions ====

    public function testGetPermissionsShouldReturnNotFoundWithoutAuthentication()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetPermissions();

        $this->assertEquals(StatusCode::NotFound, $response->getStatus());
    }

    public function testGetPermissionsShouldReturnArray()
    {
        $mockIdentityService = new MockIdentityService();
        $mockRequest = new Request([]);

        $role = new UserRole();
        $role->GrantPermission(Permissions::ManageUserRoles->value);

        $mockIdentityService->user = new User(
            role: $role,
        );

        $mockIdentityService->availPermissions = [
            new Permission('vendor', 'package', 'key', 'name', 'description', []),
        ];

        $result = new UserRoleController(
            $mockIdentityService,
            $mockRequest,
        );

        $response = $result->GetPermissions();

        $this->assertEquals(StatusCode::OK, $response->getStatus());
        $responseValue = json_decode($response->getContent(), true);
        $this->assertIsArray($responseValue);
        $this->assertCount(1, $responseValue);
    }
}