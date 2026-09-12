<?php

namespace Pivel\Hydro2\Extensions;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
class RequireScript
{
    public function __construct(
        public string $Path,
        public bool $Inline=true,
        public bool $IsModule=false,
    ) {
    }
}