<?php

namespace Pivel\Hydro2\Models\Email;

use JsonSerializable;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Extensions\Database\TableColumn;
use Pivel\Hydro2\Extensions\Database\TableName;
use Pivel\Hydro2\Extensions\Database\TablePrimaryKey;
use Pivel\Hydro2\Extensions\Database\Where;
use Pivel\Hydro2\Models\Database\BaseObject;

#[Entity('hydro2_outbound_email_profiles')]
class OutboundEmailProfile implements JsonSerializable
{
    #[EntityField('id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public ?int $Id;
    #[EntityField('key')]
    public string $Key;
    #[EntityField('label')]
    public string $Label;
    #[EntityField('type')]
    public string $Type;
    #[EntityField('sender_address')]
    public string $SenderAddress;
    #[EntityField('sender_name')]
    public string $SenderName;
    #[EntityField('require_auth')]
    public bool $RequireAuth;
    #[EntityField('username')]
    public ?string $Username;
    #[EntityField('password')]
    public ?string $Password;
    #[EntityField('host')]
    public string $Host;
    #[EntityField('port')]
    public int $Port;
    #[EntityField('secure')]
    public string $Secure;

    public const SECURE_NONE = '';
    public const SECURE_TLS_REQUIRE = 'TLS_REQUIRE';
    public const SECURE_TLS_AUTO = 'TLS_AUTO';
    public const SECURE_SSL = 'SSL';

    public function __construct(
        ?int $id=null,
        string $key='',
        string $label='',
        string $type='smtp',
        ?EmailAddress $sender=null,
        bool $requireAuth=false,
        ?string $username='',
        ?string $password='',
        string $host='',
        int $port=25,
        string $secure=self::SECURE_NONE,
    ) {
        $sender = $sender??new EmailAddress('', '');
        $this->Id = $id;
        $this->Key = $key;
        $this->Label = $label;
        $this->Type = $type;
        $this->SenderAddress = $sender->Address;
        $this->SenderName = $sender->Name;
        $this->RequireAuth = $requireAuth;
        $this->Username = $username;
        $this->Password = $password;
        $this->Host = $host;
        $this->Port = $port;
        $this->Secure = $secure;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'key' => $this->Key,
            'label' => $this->Label,
            'type' => $this->Type,
            'sender' => $this->GetSender(),
            'require_auth' => $this->RequireAuth,
            'username' => $this->Username,
            // don't provide password
            'host' => $this->Host,
            'port' => $this->Port,
            'secure' => $this->Secure,
        ];
    }

    public function GetSender() : EmailAddress {
        return new EmailAddress($this->SenderAddress, $this->SenderName);
    }
}