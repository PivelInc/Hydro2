<?php

namespace Pivel\Hydro2\Extensions;

use Attribute;
use Pivel\Hydro2\Models\HTTP\Method;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class TidalSubscription
{
    public function __construct(
        public string $event,
    ) {
    }
}