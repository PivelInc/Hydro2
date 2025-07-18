<?php

namespace Pivel\Hydro2\Models\Identity;

use DateTime;
use DateTimeZone;
use JetBrains\PhpStorm\Deprecated;
use JsonSerializable;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;

#[Entity(CollectionName: 'hydro2_user_sessions')]
class Session implements JsonSerializable
{
    #[EntityField(FieldName: 'id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public ?int $Id = null;
    #[EntityField(FieldName: 'user_uuid')]
    #[ForeignEntityManyToOne(OnDelete: ReferenceBehaviour::CASCADE)]
    private ?User $user;
    #[EntityField(FieldName: 'random_id')]
    public ?string $RandomId = null;
    //session key hash
    #[EntityField(FieldName: 'key_hash')]
    public string $KeyHash;
    /**
     * Un-hashed key, only available after generating a new key to allow it to be sent to
     * the client since the un-hashed key is not stored on the server.
     */
    public ?string $Key = null;

    #[EntityField(FieldName: 'browser')]
    public ?string $Browser;
    #[EntityField(FieldName: 'start')]
    public ?DateTime $StartTime;
    #[EntityField(FieldName: 'expire')]
    public ?DateTime $ExpireTime;
    #[EntityField(FieldName: 'expire_2fa')]
    public ?DateTime $Expire2FATime;
    #[EntityField(FieldName: 'last_access')]
    public ?DateTime $LastAccessTime;
    #[EntityField(FieldName: 'start_ip')]
    public ?string $StartIP;
    #[EntityField(FieldName: 'last_ip')]
    public ?string $LastIP;

    private const KEY_COST = 11;

    public function __construct(
        ?User $user = null,
        ?string $browser = null,
        ?DateTime $startTime = null,
        ?DateTime $expireTime = null,
        ?DateTime $expire2FATime = null,
        ?DateTime $lastAccessTime = null,
        ?string $startIP = null,
        ?string $lastIP = null,
    ) {
        $this->user = $user;
        if ($this->user !== null) {
            $this->GenerateRandomId();
            $this->GenerateKey();
        }
        $this->Browser = $browser;
        $this->StartTime = $startTime??new DateTime(timezone:new DateTimeZone('UTC'));
        $this->ExpireTime = $expireTime;
        $this->Expire2FATime = $expire2FATime;
        $this->LastAccessTime = $lastAccessTime;
        $this->StartIP = $startIP;
        $this->LastIP = $lastIP;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'random_id' => $this->RandomId,
            'browser' => $this->Browser,
            'start' => $this->StartTime,
            'expire' => $this->ExpireTime,
            'last_access' => $this->LastAccessTime,
            'start_ip' => $this->StartIP,
            'last_ip' => $this->LastIP,
        ];
    }

    public function GetUser(): User
    {
        return $this->user;
    }

    public function IsValid(): bool
    {
        $now = new DateTime(timezone:new DateTimeZone('UTC'));
        if ($now <= $this->StartTime) {
            return false;
        }

        if ($this->ExpireTime <= $now) {
            return false;
        }

        return true;
    }

    private function GenerateRandomId(): void
    {
        $this->RandomId = md5(uniqid($this->GetUser()->Email, true));
    }

    private function GenerateKey(): void
    {
        $this->Key = bin2hex(random_bytes(16));
        $this->KeyHash = password_hash($this->Key, PASSWORD_DEFAULT, ['cost'=>self::KEY_COST]);
    }

    public function RehashKeyIfRequired(string $key): bool
    {
        if (password_needs_rehash($this->KeyHash, PASSWORD_DEFAULT, ['cost'=>self::KEY_COST])) {
            $this->KeyHash = password_hash($key, PASSWORD_DEFAULT, ['cost'=>self::KEY_COST]);
            return true;
        }

        return false;
    }

    public function CompareKey(string $key): bool
    {
        return password_verify($key, $this->KeyHash);
    }
}