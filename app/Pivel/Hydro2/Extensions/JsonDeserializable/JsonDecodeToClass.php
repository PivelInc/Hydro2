<?php

namespace Pivel\Hydro2\Extensions\JsonDeserializable;

use Error;
use Exception;
use InvalidArgumentException;

class JsonDecodeToClass
{
    /**
     * @template T
     * @param string|array $json accepts either a json strin which will be decoded or an array
     * @param class-string<T> $class
     * @return ?T If unable to decode, returns null
     */
    public static function json_decode_to_class(string|array $json, string $class) : mixed
    {
        $object = $json;
        
        if (!is_array($object)) {
            $object = json_decode($json, true);
            if ($object === null) {
                return null;
            }
        }

        if (!is_a($class, JsonDeserializable::class, true)) {
            throw new InvalidArgumentException("{$class} does not implement JsonDeserializable");
        }

        try {
            return $class::jsonDeserialize($object);
        } catch (Exception $e) {
            return null;
        }
        
    }
}