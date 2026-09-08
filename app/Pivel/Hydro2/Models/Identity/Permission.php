<?php

namespace Pivel\Hydro2\Models\Identity;

use JsonSerializable;

class Permission implements JsonSerializable
{
    public string $FullKey;

    /** @var string[] $Requires Full Keys of permissions required when this permission is granted */
    public function __construct(
        public string $Vendor,
        public string $Package,
        public string $Key,
        public string $Name,
        public string $Description,
        public array $Requires=[],
    )
    {
        $this->FullKey = strtolower($Vendor . '/' . $Package . '/' . $Key);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'vendor' => $this->Vendor,
            'package' => $this->Package,
            'key' => $this->Key,
            'fullkey' => $this->FullKey,
            'name' => $this->Name,
            'description' => $this->Description,
            'requires' => $this->Requires,
        ];
    }
}