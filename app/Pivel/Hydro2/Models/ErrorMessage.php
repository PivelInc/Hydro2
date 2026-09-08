<?php

namespace Pivel\Hydro2\Models;

use JsonSerializable;

class ErrorMessage implements JsonSerializable
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
}