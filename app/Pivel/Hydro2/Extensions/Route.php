<?php

namespace Pivel\Hydro2\Extensions;

use Attribute;
use Pivel\Hydro2\Models\HTTP\Method;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class Route {
    public Method $method;
    public string $path;
    public string $order;

    public function __construct(Method $method, string $path, int $order=0)
    {
        $this->method = $method;
        $this->path = $path;
        $this->order = $order;
    }
}