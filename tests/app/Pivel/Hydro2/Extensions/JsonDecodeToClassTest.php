<?php

namespace Tests;

use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Pivel\Hydro2\Extensions\JsonDeserializable\JsonDecodeToClass;
use Pivel\Hydro2\Extensions\JsonDeserializable\JsonDeserializable;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Order;

#[CoversClass(JsonDecodeToClass::class)]
#[UsesClass(JsonDecodeToClass::class)]
class JsonDecodeToClassTest extends TestCase
{
    public function testDeserializeWellFormedJsonShouldSucceed()
    {
        $json = "{\"name\":\"John Doe\",\"age\":35,\"profession\":\"Software Developer\"}";

        /** @var Person */
        $person = JsonDecodeToClass::json_decode_to_class($json, Person::class);

        $this->assertInstanceOf(Person::class, $person);
        $this->assertEquals("John Doe", $person->Name);
        $this->assertEquals(35, $person->Age);
        $this->assertEquals("Software Developer", $person->Profession);
    }

    public function testDeserializeBadJsonShouldReturnNull()
    {
        $json = "invalid";

        /** @var Person */
        $person = JsonDecodeToClass::json_decode_to_class($json, Person::class);

        $this->assertNull($person);
    }

    public function testDeserializeToClassNotImplementingInterfaceShouldThrow()
    {
        $json = "{\"name\":\"John Doe\"}";

        $this->expectException(InvalidArgumentException::class);

        /** @var Pet */
        $pet = JsonDecodeToClass::json_decode_to_class($json, Pet::class);
    }

    public function testDeserializeMissingPropertyShouldReturnNull()
    {
        $json = "{\"name\":\"John Doe\",\"age\":35}";

        /** @var Person */
        $person = JsonDecodeToClass::json_decode_to_class($json, Person::class);

        $this->assertNull($person);
    }
}

class Person implements JsonDeserializable
{
    public string $Name;
    public int $Age;
    public string $Profession;

    public static function jsonDeserialize(mixed $object): ?self
    {
        if (!is_array($object)) {
            return null;
        }

        $instance = new self();
        $instance->Name = $object["name"] ?? throw new Exception();
        $instance->Age = $object["age"] ?? throw new Exception();
        $instance->Profession = $object["profession"] ?? throw new Exception();
        
        return $instance;
    }
}

class Pet
{
    public string $Name;
}