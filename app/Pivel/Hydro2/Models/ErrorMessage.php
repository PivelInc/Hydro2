<?php

namespace Pivel\Hydro2\Models;

use JsonSerializable;
use Pivel\Hydro2\Extensions\JsonDeserializable\JsonDeserializable;

class ErrorMessage implements JsonSerializable, JsonDeserializable
{
    public function __construct(
        public string $Code = '',
        public ?string $Message = null,
        public ?string $Detail = null,
        public ?string $Help = null,
    ) {
        
    }

    public function jsonSerialize(): mixed
    {
        return [
            'code' => $this->Code,
            'message' => $this->Message,
            'detail' => $this->Detail,
            'help' => $this->Help,
        ];
    }

    public static function jsonDeserialize(mixed $object): ?self
    {
        if (!is_array($object)) {
            return null;
        }

        return new ErrorMessage(
            Code: $object['code'] ?? '',
            Message: $object['message'] ?? null,
            Detail: $object['detail'] ?? null,
            Help: $object['help'] ?? null,
        );
    }
}