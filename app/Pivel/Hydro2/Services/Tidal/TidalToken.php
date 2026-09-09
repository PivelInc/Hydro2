<?php

namespace Pivel\Hydro2\Services\Tidal;

use DateTime;
use Pivel\Hydro2\Attributes\Entity\Entity;
use Pivel\Hydro2\Attributes\Entity\EntityField;
use Pivel\Hydro2\Attributes\Entity\EntityPrimaryKey;
use Pivel\Hydro2\Attributes\Entity\ForeignEntityManyToOne;
use Pivel\Hydro2\Models\Database\ReferenceBehaviour;
use Pivel\Hydro2\Models\Identity\User;

#[Entity(CollectionName: 'hydro2_tidal_tokens')]
class TidalToken
{
    #[EntityField(FieldName: 'id', AutoIncrement: true)]
    #[EntityPrimaryKey]
    public int|null $Id = null;
    #[EntityField(FieldName: 'token')]
    public string|null $token;
    #[EntityField(FieldName: 'expires')]
    public DateTime $expires;
    #[EntityField(FieldName: 'user_uuid')]
    #[ForeignEntityManyToOne(OnDelete: ReferenceBehaviour::CASCADE)]
    public User|null $user;
    #[EntityField(FieldName: 'reference')]
    public string|null $reference;

    public function __construct(
        string|null $token = null,
        DateTime|null $expires = null,
        User|null $user = null,
        string|null $reference = null
    )
    {
        $this->token = $token;
        $this->expires = $expires ?? new DateTime();
        $this->user = $user;
        $this->reference = $reference;
    }
}