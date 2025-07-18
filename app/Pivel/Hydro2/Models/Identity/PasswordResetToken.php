<?php

namespace Pivel\Hydro2\Models\Identity;

use DateTime;
use DateTimeZone;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;

#[Entity(CollectionName: 'hydro2_user_password_reset_tokens')]
class PasswordResetToken
{
    #[EntityField(FieldName: 'id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public ?int $Id = null;
    #[EntityField(FieldName: 'user_uuid')]
    #[ForeignEntityManyToOne(OnDelete: ReferenceBehaviour::CASCADE)]
    private ?User $user;
    #[EntityField(FieldName: 'reset_token')]
    public string $ResetToken;
    #[EntityField(FieldName: 'start')]
    public ?DateTime $StartTime;
    #[EntityField(FieldName: 'expire')]
    public ?DateTime $ExpireTime;
    #[EntityField(FieldName: 'used')]
    public bool $Used;

    public function __construct(
        ?User $user = null,
        ?DateTime $startTime = null,
        int $expireAfterMinutes = 10,
    ) {
        $this->user = $user;
        $this->GenerateToken();
        $this->StartTime = $startTime??new DateTime(timezone:new DateTimeZone('UTC'));
        $this->ExpireTime = (clone $this->StartTime)->modify(($expireAfterMinutes>=0?"+":"")."{$expireAfterMinutes} minutes");
        $this->Used = false;
    }

    public function GetUser(): User
    {
        return $this->user;
    }

    private function GenerateToken(): void
    {
        $this->ResetToken = bin2hex(random_bytes(16));
    }

    public function CompareToken(string $token): bool
    {
        if ($this->Used) {
            return false;
        }

        if ($this->ExpireTime < new DateTime(timezone:new DateTimeZone('UTC'))) {
            return false;
        }
        
        return $token === $this->ResetToken;
    }
}