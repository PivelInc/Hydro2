<?php

namespace Pivel\Hydro2\Extensions\JsonDeserializable;

use Error;
use Exception;

class JsonDecodeToClass
{
    /**
     * @template T
     * @param string $json
     * @param class-string<T> $class
     * @return ?T If unable to decode, returns null
     */
    public static function json_decode_to_class(string $json, string $class) : mixed
    {
        $object = json_decode($json, true);
        if ($object === null) {
            return null;
        }

        if (!is_a($class, JsonDeserializable::class, true)) {
            return null;
        }

        try {
            return $class::jsonDeserialize($object);
        } catch (Exception) {
            return null;
        }
        
    }
}