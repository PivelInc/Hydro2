<?php

namespace Pivel\Hydro2\Extensions\JsonDeserializable;

interface JsonDeserializable
{
    public static function jsonDeserialize(mixed $object): ?self;
}