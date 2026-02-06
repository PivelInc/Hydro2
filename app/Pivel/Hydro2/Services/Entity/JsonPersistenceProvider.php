<?php

namespace Pivel\Hydro2\Services\Entity;

use DateTime;
use DateTimeZone;
use Exception;
use Pivel\Hydro2\Exceptions\Database\TableNotFoundException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\EntityDefinition;
use Pivel\Hydro2\Models\EntityFieldDefinition;
use Pivel\Hydro2\Models\EntityPersistenceProfile;
use Pivel\Hydro2\Models\Geometry\Geometry;
use Pivel\Hydro2\Models\Uuid;

class JsonPersistenceProvider implements IEntityPersistenceProvider
{
    public static function GetFriendlyName() : string
    {
        return 'JSON';
    }

    private string $file;

    public function __construct(EntityPersistenceProfile $profile)
    {
        $this->file = $profile->GetHostOrPath();

        if (!file_exists($this->file)) {
            file_put_contents($this->file, "{}");
        }
    }

    public function __destruct()
    {
        
    }

    // Profile validation
    public function IsProfileValid() : bool
    {
        if (!file_exists($this->file)) {
            file_put_contents($this->file, "{}");
        }

        return file_exists($this->file);
    }

    // Schema manipulation
    public function CanCreateDatabaseSchemas() : bool
    {
        // Json doesn't have multiple schemas.
        return false;
    }

    public function GetDatabaseSchemas() : array {
        // Json doesn't have multiple schemas.
        return [];
    }

    public function CreateDatabaseSchema(string $schemaName) : bool
    {
        // Json doesn't have multiple schemas.
        return false;
    }

    // Collection manipulation
    public function CollectionExists(EntityDefinition $collection) : bool
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return isset($data[$collection->GetName()]);
    }

    public function CreateCollectionIfNotExists(EntityDefinition $collection) : bool
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($data[$collection->GetName()])) {
            $data[$collection->GetName()] = [];
            file_put_contents($this->file, json_encode($data));
        }

        return true;
    }

    // Entity/data manipulation
    public function Select(EntityDefinition $collection, ?Query $query) : array
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (!isset($data[$collection->GetName()])) {
            throw new TableNotFoundException("Collection {$collection->GetName()} doesn't exist.");
        }

        /** @var array */
        $results = $data[$collection->GetName()];

        if ($query !== null) {
            // Filter results that don't match the query's filter tree
            $results = array_filter($results, function($row) use ($query) {
                $r = $this->RowMatchesQuery($row, $query);
                return $r;
            });

            // Sort results according the the query's order tree
            usort($results, function($a, $b) use ($query) {
                foreach ($query->GetOrderTree() as $order) {
                    $d = $order['direction'] == Order::Ascending ? 1 : -1;
                    $r = ($a[$order['field']] <=> $b[$order['field']]) * $d;
                    if ($r != 0) {
                        return $r;
                    }
                }

                return 0;
            });

            // Slice the requested number of items
            $limit = $query->GetLimit();
            $results = array_slice($results, $query->GetOffset(), $limit <= -1 ? null : $limit);
        }

        return $results;
    }

    public function Count(EntityDefinition $collection, ?Query $query) : int
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($data[$collection->GetName()])) {
            throw new TableNotFoundException("Collection {$collection->GetName()} doesn't exist.");
        }

        /** @var array */
        $results = $data[$collection->GetName()];
        // Filter results that don't match the query's filter tree
        $results = array_filter($results, function($row) use ($query) {
            return $this->RowMatchesQuery($row, $query);
        });

        return count($results);
    }

    public function Insert(EntityDefinition $collection, array $fieldValues) : ?int
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (!isset($data[$collection->GetName()])) {
            throw new TableNotFoundException("Collection {$collection->GetName()} doesn't exist.");
        }

        // Emulate auto-increment
        $pk = null;
        $pkField = $collection->GetPrimaryKeyField();
        if ($pkField !== null && $pkField->AutoIncrement) {
            $nextPk = 1;
            foreach ($data[$collection->GetName()] as $row) {
                if ($row[$pkField->FieldName] > $nextPk) {
                    $nextPk = $row[$pkField->FieldName] + 1;
                }
            }
            $pk = $nextPk;
            $fieldValues[$pkField->FieldName] = $pk;
        }

        $data[$collection->GetName()][] = $fieldValues;
        file_put_contents($this->file, json_encode($data));

        if ($pkField->AutoIncrement) {
            return $pk;
        }

        return null;
    }

    public function InsertOrUpdate(EntityDefinition $collection, array $fieldValues) : ?int
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (!isset($data[$collection->GetName()])) {
            throw new TableNotFoundException("Collection {$collection->GetName()} doesn't exist.");
        }

        // If primary key is null, insert instead.
        $pkField = $collection->GetPrimaryKeyField();
        if ($pkField === null || $fieldValues[$pkField->FieldName] === null) {
            return $this->Insert($collection, $fieldValues);
        }
        
        $pkValue = $fieldValues[$pkField->FieldName];

        // find the row where the primary key matches.
        $idx = null;
        foreach ($data[$collection->GetName()] as $i => $row) {
            if ($row[$pkField->FieldName] !== $pkValue) {
                continue;
            }

            $idx = $i;
            break;
        }

        if ($idx === null) {
            return $this->Insert($collection, $fieldValues);
        }

        $data[$collection->GetName()][$idx] = $fieldValues;
        file_put_contents($this->file, json_encode($data));

        if ($pkField->AutoIncrement) {
            return $pkValue;
        }

        return null;
    }

    public function Delete(EntityDefinition $collection, Query $query) : int
    {
        $data = json_decode(file_get_contents($this->file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($data[$collection->GetName()])) {
            throw new TableNotFoundException("Collection {$collection->GetName()} doesn't exist.");
        }

        $deletedItems = 0;
        foreach ($data[$collection->GetName()] as $i => $row) {
            // Filter results that don't match the query's filter tree
            if (!$this->RowMatchesQuery($row, $query)) {
                continue;
            }

            unset($data[$collection->GetName()][$i]);
            $deletedItems++;
        }

        file_put_contents($this->file, json_encode($data));

        return $deletedItems;
    }

    private function RowMatchesQuery(array $row, ?Query $query) : bool
    {
        if ($query === null) {
            return true;
        }

        return self::RowMatchesFilterTree($row, $query->GetFilterTree(), $query->GetConvertedFilterParameters($this));
    }

    private static function RowMatchesFilterTree(array $row, array $filterTree, array $filterParameters) : bool
    {
        if (isset($filterTree['operator'])) {
            // not a group. Evaluate this specific condition.
            $value = $row[$filterTree['field']];
            $testValue = $filterParameters[$filterTree['parameterKey']];
            $op = $filterTree['operator'];
            $neg = $filterTree['negated'];

            if ($op == Query::EQUAL) {
                $r = (($value == $testValue) xor $neg) ? 'true' : 'false';
                return ($value == $testValue) xor $neg;
            }
            
            if ($filterTree['operator'] == Query::ST_WITHIN) {
                throw new Exception('ST_Within is not supported by JsonPersistenceProvider.');
            }

            if ($filterTree['operator'] == Query::ST_CONTAINS) {
                throw new Exception('ST_Contains is not supported by JsonPersistenceProvider.');
            }

            if ($op == Query::GREATER_THAN) {
                return ($value > $testValue) xor $neg;
            }

            if ($op == Query::GREATER_THAN_OR_EQUAL) {
                return ($value >= $testValue) xor $neg;
            }

            if ($op == Query::LESS_THAN) {
                return ($value < $testValue) xor $neg;
            }

            if ($op == Query::LESS_THAN_OR_EQUAL) {
                return ($value <= $testValue) xor $neg;
            }

            if ($op == Query::LIKE) {
                // test value is a string where:
                // _ = any one character
                // % = any 0, 1, or x characters
                // and any other character is itself
                return self::preg_sql_like($value, $testValue) xor $neg;
            }

            return false;
        }

        // Evaluate the group.
        $op = $filterTree['booloperator'];
        $result = $op == Query::AND;

        foreach ($filterTree['operands'] as $operand) {
            $operandResult = self::RowMatchesFilterTree($row, $operand, $filterParameters);
            if ($op == Query::AND) {
                $result = $result && $operandResult;
            } else {
                $result = $result || $operandResult;
            }
        }

        return $result;
    }

    /**
     * @see https://stackoverflow.com/questions/11434305/simulating-like-in-php
     * @author DaveRandom
     */
    private static function preg_sql_like ($input, $pattern, $escape = '\\') : bool {
        // Split the pattern into special sequences and the rest
        $expr = '/((?:'.preg_quote($escape, '/').')?(?:'.preg_quote($escape, '/').'|%|_))/';
        $parts = preg_split($expr, $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    
        // Loop the split parts and convert/escape as necessary to build regex
        $expr = '/^';
        $lastWasPercent = FALSE;
        foreach ($parts as $part) {
            switch ($part) {
                case $escape.$escape:
                    $expr .= preg_quote($escape, '/');
                    break;
                case $escape.'%':
                    $expr .= '%';
                    break;
                case $escape.'_':
                    $expr .= '_';
                    break;
                case '%':
                    if (!$lastWasPercent) {
                        $expr .= '.*?';
                    }
                    break;
                case '_':
                    $expr .= '.';
                    break;
                default:
                    $expr .= preg_quote($part, '/');
                    break;
            }
            $lastWasPercent = $part == '%';
        }
        $expr .= '$/i';
    
        // Look for a match and return bool
        return (bool) preg_match($expr, $input);
    }

    public static function ConvertValueToStorage(EntityFieldDefinition $field, mixed $value): mixed {
        if ($value instanceof DateTime) {
            /** @var DateTime $value */
            if ($field->IsNullable && $value == null) {
                return null;
            } else {
                return $value->setTimezone(new DateTimeZone('UTC'))->format('c');
            }
        }

        if ($value instanceof Uuid) { // uuid
            /** @var Uuid $value */
            $value = (string)$value;
        }

        if ($field->PropertyType == 'bool') {
            $value = $value ? 1 : 0;
        }

        if (is_subclass_of($field->PropertyType, Geometry::class) && is_subclass_of($value, Geometry::class)) {
            /** @var Geometry $value */
            return $value->jsonSerialize();
        }

        return $value;
    }

    public static function ConvertValueFromStorage(EntityFieldDefinition $field, mixed $value): mixed {
        if ($field->PropertyType == DateTime::class) {
            if ($field->IsNullable && $value == null) {
                return null;
            } else {
                return new DateTime($value, new DateTimeZone('UTC'));
            }
        }

        if ($field->PropertyType == Uuid::class) { // uuid
            return Uuid::ParseFromString($value);
        }

        if (is_subclass_of($field->PropertyType, Geometry::class)) {
            /** @var Geometry $value */
            return ($field->PropertyType)::jsonDeserialize($value);
        }

        return $value;
    }
}