<?php

namespace Pivel\Hydro2\Models;

use InvalidArgumentException;
use JsonSerializable;

class Uuid implements JsonSerializable
{
    private string $uuidData;

    /**
     * @param string|null $uuid_data 16 bytes of raw UUID data. If null, initializes to all zeroes.
     */
    public function __construct(
        ?string $uuid_data=null,
    )
    {
        if ($uuid_data === null) {
            $uuid_data = str_repeat("\0", 16);
        }
        $this->uuidData = $uuid_data;
    }

    public function IsEmpty(): bool
    {
        return $this->uuidData === str_repeat("\0", 16);
    }

    public function __toString(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($this->uuidData), 4));
    }

    public function jsonSerialize(): mixed
    {
        return (string)$this;
    }

    public static function ParseFromString(string $uuid_string): static
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid_string)) {
            throw new InvalidArgumentException('Invalid UUID string format');
        }
        $hex = str_replace('-', '', $uuid_string);
        return new static(hex2bin($hex));
    }

    public static function GenerateV4(): static
    {
        $data = random_bytes(16);
        // Set version to 0100
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return new static($data);
    }
}