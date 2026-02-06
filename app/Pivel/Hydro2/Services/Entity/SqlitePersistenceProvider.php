<?php

namespace Pivel\Hydro2\Services\Entity;

use DateTime;
use DateTimeZone;
use Exception;
use PDO;
use PDOException;
use Pivel\Hydro2\Exceptions\Database\TableNotFoundException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Type;
use Pivel\Hydro2\Models\EntityDefinition;
use Pivel\Hydro2\Models\EntityFieldDefinition;
use Pivel\Hydro2\Models\EntityPersistenceProfile;
use Pivel\Hydro2\Models\Geometry\Geometry;
use Pivel\Hydro2\Models\Uuid;

class SqlitePersistenceProvider implements IEntityPersistenceProvider
{
    public static function GetFriendlyName() : string
    {
        return 'Sqlite';
    }

    private string $file;
    private bool $connected;
    private ?PDO $pdo;

    public function __construct(EntityPersistenceProfile $profile)
    {
        $this->file = $profile->GetHostOrPath();
        $this->connected = false;
        $this->pdo = null;
    }

    public function __destruct()
    {
        $this->CloseConnection();
    }

    private function OpenConnection() : bool
    {
        if ($this->connected) {
            return true; // Already connected.
        }

        try {
            $this->pdo = new PDO(
                "sqlite:" . $this->file
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Sqlite disables foreign keys by default; they must be enabled in each session.
            $stmt = $this->pdo->prepare("PRAGMA foreign_keys = ON;");
            $stmt->execute();
        } catch(PDOException $e) {
            return false;
        }

        $this->connected = true;
        return true;
    }

    private function CloseConnection() : void
    {
        $this->connected = false;
        $this->pdo = null;
    }

    // Profile validation
    public function IsProfileValid() : bool
    {
        return $this->OpenConnection();
    }

    // Schema manipulation
    public function CanCreateDatabaseSchemas() : bool
    {
        // Sqlite doesn't have multiple schemas.
        return false;
    }

    public function GetDatabaseSchemas() : array {
        // Sqlite doesn't have multiple schemas.
        return [];
    }

    public function CreateDatabaseSchema(string $schemaName) : bool
    {
        // Sqlite doesn't have multiple schemas.
        return false;
    }

    // Collection manipulation
    public function CollectionExists(EntityDefinition $collection) : bool
    {
        if (!$this->OpenConnection()) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=':tblname'");
        $stmt->execute(['tblname'=>$collection->GetName()]);
        $res = $stmt->fetchAll();
        return count($res) == 1;
    }

    public function CreateCollectionIfNotExists(EntityDefinition $collection) : bool
    {
        if (!$this->OpenConnection()) {
            return false;
        }

        $columnStructureString = implode(',',array_map(function(EntityFieldDefinition $field) {
            return self::getColumnSQL($field);
        },$collection->GetFields()));

        $constraintStructureString = implode(',',array_map(function($field) {
            return self::getConstraintSQL($field);
        },array_filter($collection->GetFields(),fn(EntityFieldDefinition $field)=>($field->IsPrimaryKey||$field->IsForeignKey)&&self::getConstraintSQL($field)!==null)));

        if ($constraintStructureString != '') {
            $columnStructureString .= ','.$constraintStructureString;
        }

        try {
            $stmt = $this->pdo->prepare("CREATE TABLE IF NOT EXISTS {$collection->GetName()} ({$columnStructureString})");
            $stmt->execute();
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    // Entity/data manipulation
    public function Select(EntityDefinition $collection, ?Query $query) : array
    {
        if (!$this->OpenConnection()) {
            return [];
        }

        $columnsString = implode(',',array_map(fn(EntityFieldDefinition $field):string=>$field->FieldName,$collection->GetFields()));
        $queryString = 'SELECT '.$columnsString.' FROM '.$collection->GetName();
        if ($query !== null) {
            $queryString .= self::GetWhereStringFromQuery($query);
            $queryString .= self::GetOrderStringFromQuery($query);
            $queryString .= (($query->GetLimit()<=-1&&$query->GetOffset()==0)?'':' LIMIT '.($query->GetOffset()==0?'':''.$query->GetOffset().', ').$query->GetLimit());
        }
        try {
            $stmt = $this->pdo->prepare($queryString);
            $stmt->execute($query==null?[]:$query->GetConvertedFilterParameters($this));
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1 && str_contains($e->errorInfo[2], 'no such table')) {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }
    }

    public function Count(EntityDefinition $collection, ?Query $query) : int
    {
        if (!$this->OpenConnection()) {
            return 0;
        }
        
        $queryString = 'SELECT COUNT(*) FROM '.$collection->GetName();
        $queryString .= self::GetWhereStringFromQuery($query);
        try {
            $stmt = $this->pdo->prepare($queryString);
            $stmt->execute($query==null?[]:$query->GetConvertedFilterParameters($this));
            return $stmt->fetchAll()[0][0];
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1 && str_contains($e->errorInfo[2], 'no such table')) {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }
    }

    public function Insert(EntityDefinition $collection, array $fieldValues) : ?int
    {
        if (!$this->OpenConnection()) {
            return null;
        }

        // Need to filter out any auto-increment columns
        $fieldNamesExceptAutoIncrement = array_map(
            fn(EntityFieldDefinition $field):string=>$field->FieldName,
            array_filter(
                $collection->GetFields(),
                fn(EntityFieldDefinition $field):bool=>!$field->AutoIncrement,
            ),
        );
        $fieldValuesExceptAutoIncrement = array_filter(
            $fieldValues,
            fn($k):bool=>in_array($k, $fieldNamesExceptAutoIncrement),
            ARRAY_FILTER_USE_KEY,
        );
        $columnsString = implode(',', array_keys($fieldValuesExceptAutoIncrement));
        $valuePlaceholdersString = implode(',', array_map(
            fn($v):string=>':'.$v,
            array_keys($fieldValuesExceptAutoIncrement),
        ));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO ".$collection->GetName()." (".$columnsString.") VALUES (".$valuePlaceholdersString.")");
            $stmt->execute($fieldValuesExceptAutoIncrement);
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1 && str_contains($e->errorInfo[2], 'no such table')) {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }

        if ($collection->GetPrimaryKeyField() == null) {
            return null;
        }

        return intval($this->pdo->lastInsertId());
    }

    public function InsertOrUpdate(EntityDefinition $collection, array $fieldValues) : ?int
    {
        if (!$this->OpenConnection()) {
            return null;
        }

        $columnsString = implode(',', array_keys($fieldValues));
        $valuePlaceholdersString = implode(',', array_map(fn($k):string=>':'.$k,array_keys($fieldValues)));
        $pkField = $collection->GetPrimaryKeyField();
        try {
            $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO ".$collection->GetName()." (".$columnsString.") VALUES (".$valuePlaceholdersString.")");
            $stmt->execute($fieldValues);
            if ($pkField !== null) {
                $updateValuesPlaceholderString = implode(',', array_map(fn($k):string=>'`'.$k.'`=:'.$k,array_filter(array_keys($fieldValues),fn($k)=>$k!=$pkField->FieldName)));
                $stmt = $this->pdo->prepare("UPDATE ".$collection->GetName()." SET ".$updateValuesPlaceholderString." WHERE `".$pkField->FieldName."`=:".$pkField->FieldName);
                $stmt->execute($fieldValues);
            }
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1 && str_contains($e->errorInfo[2], 'no such table')) {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }

        if ($pkField == null) {
            return null;
        }

        $lastInsertId = $this->pdo->lastInsertId();
        return $lastInsertId ? intval($lastInsertId) : null;
    }

    public function Delete(EntityDefinition $collection, Query $query) : int
    {
        if (!$this->OpenConnection()) {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare("DELETE FROM " . $collection->GetName() . self::GetWhereStringFromQuery($query));
            $stmt->execute($query->GetConvertedFilterParameters($this));
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1 && str_contains($e->errorInfo[2], 'no such table')) {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }

        return $stmt->rowCount();
    }

    // Helpers
    private static function getColumnSQL(EntityFieldDefinition $field) : string {
        // column_name [def] [PRIMARY KEY|FOREIGN KEY]
        $s = $field->FieldName . ' ' . self::GetSQLType($field) . (($field->AutoIncrement||$field->IsPrimaryKey)?' PRIMARY KEY':'').(($field->AutoIncrement)?' AUTOINCREMENT':'');
        return $s;
    }

    private static function getConstraintSQL(EntityFieldDefinition $field) : null|string {
        // column_name [def] [PRIMARY KEY|FOREIGN KEY]
        $constraints = [];
        if ($field->IsPrimaryKey) {
            //do nothing. sqlite primary keys are declared inline.
        }

        if ($field->IsForeignKey && $field->ForeignKeyCollectionField !== null) {
            $s = 'FOREIGN KEY ('.$field->FieldName.') REFERENCES '.$field->ForeignKeyCollectionName.'('.$field->ForeignKeyCollectionField->FieldName.')';
            $s .= ' ON UPDATE '.$field->ForeignKeyOnUpdate->value.' ON DELETE '.$field->ForeignKeyOnDelete->value;
            $constraints[] = $s;
        }

        if (count($constraints) == 0) {
        	return null;
        }
        return implode(',', $constraints);
    }

    //self::GetWhereStringFromQuery($query); // ($query->GetFilterTree()==null?'':' '.$where->GetParameterizedQueryString());
    private static function GetWhereStringFromQuery(?Query $query) : string
    {
        if ($query === null) {
            return '';
        }

        $filterTree = $query->GetFilterTree();

        if (count($filterTree['operands']) == 0) {
            return '';
        }

        return ' WHERE ' . self::GetWhereStringPortionFromFilterTree($filterTree);
    }

    private static function GetWhereStringPortionFromFilterTree(array $filterTree) : string
    {
        if (isset($filterTree['operator'])) {
            // this is a condition, not a group.
            if ($filterTree['operator'] == Query::ST_WITHIN) {
                throw new Exception('ST_Within is not supported by SqlitePersistenceProvider.');
            }
            if ($filterTree['operator'] == Query::ST_CONTAINS) {
                throw new Exception('ST_Contains is not supported by SqlitePersistenceProvider.');
            }
            return ($filterTree['negated'] ? 'NOT ' : '') . $filterTree['field'] . ' ' . $filterTree['operator'] . ' :' . $filterTree['parameterKey'];
        }

        $queryString = implode(' ' . $filterTree['booloperator'] . ' ', array_map(function($operand){
            return self::GetWhereStringPortionFromFilterTree($operand);
        }, $filterTree['operands']));

        if (count($filterTree['operands']) > 1) {
            $queryString = '(' . $queryString . ')';
        }

        return $queryString;
    }

    private static function GetOrderStringFromQuery(?Query $query) : string
    {
        if ($query === null) {
            return '';
        }

        $orderTree = $query->GetOrderTree();
        if (count($orderTree) == 0) {
            return '';
        }

        return ' ORDER BY ' . implode(',', array_map(function($o){
            return $o['field'] . ' ' . $o['direction']->value;
        }, $orderTree));
    }

    public static function ConvertValueToStorage(EntityFieldDefinition $field, mixed $value): mixed {
        $sqlType = self::GetSQLType($field);
        if ($sqlType == "DATETIME" && $value instanceof DateTime) {
            /** @var DateTime $value */
            if ($field->IsNullable && $value == null) {
                return null;
            } else {
                return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        if ($value instanceof Uuid && $sqlType == "CHAR(36)") { // uuid
            /** @var Uuid $value */
            return (string)$value;
        }

        if ($sqlType == "BOOLEAN") {
            return $value ? 1 : 0;
        }

        if (is_subclass_of($field->PropertyType, Geometry::class) && $value instanceof Geometry) {
            /** @var Geometry $value */
            return $value->ToWKT();
        }

        return $value;
    }

    public static function ConvertValueFromStorage(EntityFieldDefinition $field, mixed $value): mixed {
        $sqlType = self::GetSQLType($field);

        if ($sqlType == "DATETIME") {
            if ($field->IsNullable && $value == null) {
                return null;
            } else {
                return new DateTime($value.'+00:00');
            }
        }

        if ($field->PropertyType == Uuid::class && $sqlType == "CHAR(36)") { // uuid
            return Uuid::ParseFromString($value);
        }

        if (is_subclass_of($field->PropertyType, Geometry::class)) {
            /** @var Geometry $value */
            return ($field->PropertyType)::FromWKT($value);
        }

        return $value;
    }

    private static function GetSQLType(EntityFieldDefinition $field): string {
        if ($field->IsForeignKey) {
            $sqlType = self::GetSQLType($field->ForeignKeyCollectionField);
        }

        $sqlType = "TEXT";
        switch ($field->PropertyType) {
            case 'int':
                $sqlType = "INTEGER";
                break;
            case 'float':
                $sqlType = "DOUBLE";
                break;
            case 'bool':
                $sqlType = "BOOLEAN";
                break;
            case DateTime::class:
                $sqlType = "DATETIME";
                break;
            case Uuid::class:
                $sqlType = "CHAR(36)"; // UUID
                break;
            case 'mixed':
            case 'string':
            default:
                $sqlType = "TEXT";
        }

        if (is_subclass_of($field->PropertyType, Geometry::class)) {
            $sqlType = "TEXT";
        }

        if ($field->IsPrimaryKey && $sqlType == "TEXT") {
            $sqlType = "VARCHAR(255)";
        }

        return $sqlType;
    }
}