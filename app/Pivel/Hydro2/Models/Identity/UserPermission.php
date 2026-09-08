<?php

namespace Pivel\Hydro2\Models\Identity;

use JsonSerializable;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;

#[Entity(CollectionName: 'hydro2_user_permissions')]
class UserPermission implements JsonSerializable
{
    #[EntityField(FieldName: 'id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public ?int $Id = null;
    #[EntityField(FieldName: 'user_role_id')]
    #[ForeignEntityManyToOne(OnDelete: ReferenceBehaviour::CASCADE)]
    private ?UserRole $userRole;
    #[EntityField(FieldName: 'permission_key')]
    public ?string $PermissionKey;

    public function __construct(?UserRole $userRole=null, ?string $permissionKey=null)
    {
        $this->userRole = $userRole;
        $this->PermissionKey = $permissionKey;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'key' => $this->PermissionKey,
        ];
    }

    public function GetUserRole(): UserRole
    {
        return $this->userRole;
    }
}