<?php

namespace Pivel\Hydro2\Extensions;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
class RoutePrefix
{
    public function __construct(
        public string $pathPrefix
    ) {
    }
}