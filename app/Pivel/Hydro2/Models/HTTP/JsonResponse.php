<?php

namespace Pivel\Hydro2\Models\HTTP;

class JsonResponse extends Response
{
    public function __construct(mixed $data=null, StatusCode $status=StatusCode::OK, array $headers=[])
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        parent::__construct(json_encode($data), true, $status, $headers);
    }
}