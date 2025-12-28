<?php

namespace Pivel\Hydro2\Models\Identity;

use DateTime;
use DateTimeZone;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;

#[Entity(CollectionName: 'hydro2_user_passwords')]
class UserPassword
{
    #[EntityField(FieldName: 'id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public ?int $Id = null;
    #[EntityField(FieldName: 'user_uuid')]
    #[ForeignEntityManyToOne(OnDelete: ReferenceBehaviour::CASCADE)]
    private ?User $user;
    #[EntityField(FieldName: 'password_hash')]
    public string $PasswordHash;
    #[EntityField(FieldName: 'start')]
    public ?DateTime $StartTime;
    #[EntityField(FieldName: 'expire')] // if within 5 days, prompt to change on login. if past, require change on login. if null, doesn't expire
    public ?DateTime $ExpireTime;

    private const PASSWORD_COST = 11;

    public function __construct(
        ?User $user = null,
        string $password = '',
        ?DateTime $startTime = null,
        ?DateTime $expireTime = null,
    ) {
        $this->user = $user;
        $this->SetPassword($password);
        $this->StartTime = $startTime;
        $this->ExpireTime = $expireTime;
    }

    public function GetUser(): User
    {
        return $this->user;
    }

    public function IsExpired(): bool
    {
        if ($this->ExpireTime === null) {
            return false;
        }

        $now = new DateTime(timezone: new DateTimeZone('UTC'));
        return $now >= $this->ExpireTime;
    }

    public function SetPassword(string $password): void
    {
        $this->PasswordHash = password_hash($password, PASSWORD_DEFAULT, ['cost'=>self::PASSWORD_COST]);
    }

    public function ComparePassword(string $password): bool
    {
        return password_verify($password, $this->PasswordHash);
    }

    public function RehashPasswordIfRequired(string $password): bool
    {
        if (!password_needs_rehash($this->PasswordHash, PASSWORD_DEFAULT, ['cost'=>self::PASSWORD_COST])) {
            return false;
        }

        $this->SetPassword($password);
        return true;
    }
}