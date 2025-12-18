<?php

namespace Pivel\Hydro2\Services\Entity;

use PDO;
use PDOException;
use Pivel\Hydro2\Exceptions\Database\HostNotFoundException;
use Pivel\Hydro2\Exceptions\Database\InvalidUserException;
use Pivel\Hydro2\Exceptions\Database\TableNotFoundException;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\EntityDefinition;
use Pivel\Hydro2\Models\EntityFieldDefinition;
use Pivel\Hydro2\Models\EntityPersistenceProfile;

class MySqlPersistenceProvider implements IEntityPersistenceProvider
{
    public static function GetFriendlyName() : string
    {
        return 'MySQL';
    }

    private string $host;
    private ?string $database;
    private ?string $username;
    private ?string $password;
    private bool $connected;
    private ?PDO $pdo;

    public function __construct(EntityPersistenceProfile $profile)
    {
        $this->host = $profile->GetHostOrPath();
        $this->database = $profile->GetDatabaseSchema();
        $this->username = $profile->GetUsername();
        $this->password = $profile->GetPassword();
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
                "mysql:host=" . $this->host . ";dbname=" . $this->database,
                $this->username,
                $this->password,
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2002) {
                throw new HostNotFoundException($e->getMessage(), 0);
            }
            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1045) {
                throw new InvalidUserException($e->getMessage(), 0);
            }
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
        if (!$this->OpenConnection()) {
            return false;
        }
        
        $grants = $this->GetUserGrants($this->username);
        foreach ($grants as $grant) {
            if (strpos($grant, "GRANT ALL PRIVILEGES ON *.*") === 0) {
                return true;
            }
        }
        
        return false;
    }

    public function GetDatabaseSchemas() : array
    {
        if (!$this->OpenConnection()) {
            return [];
        }

        $grants = $this->GetUserGrants();
        // check where user has all privileges
        $dbsWithPrivileges = [];
        foreach ($grants as $grant) {
            if (strpos($grant, "GRANT ALL PRIVILEGES ON") === 0) {
                $matches = [];
                if (!preg_match("/(?<= )(`.*`|\*)(?=\.\*)/", $grant, $matches)) {
                    continue;
                }
                $match = trim($matches[0], '`');
                if ($match == '*') {
                    $stmt = $this->pdo->prepare('SHOW DATABASES;');
                    $stmt->execute();
                    $dbs = $stmt->fetchAll();
                    return array_map(fn($d)=>$d['Database'],$dbs);
                }
                array_push($dbsWithPrivileges, $match);
            }
        }
        return $dbsWithPrivileges;
    }

    public function CreateDatabaseSchema(string $schemaName) : bool
    {
        if (!$this->OpenConnection()) {
            return false;
        }

        if (!$this->CanCreateDatabaseSchemas()) {
            return false;
        }

        $stmt = $this->pdo->prepare("CREATE DATABASE IF NOT EXISTS {$schemaName};");
        $stmt->execute();

        return true;
    }

    /** @return string[] */
    private function GetUserGrants(?string $username=null) : array {
        $stmt = $this->pdo->prepare('SHOW GRANTS FOR '.($username??'CURRENT_USER'));
        $stmt->execute();
        $grants = $stmt->fetchAll();
        return array_map(fn($g)=>$g[0],$grants);
    }

    // Collection manipulation
    public function CollectionExists(EntityDefinition $collection) : bool
    {
        if (!$this->OpenConnection()) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT(IF(EXISTS(SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = :tblname),1,0))");
        $stmt->execute(['dbname'=>$this->database,'tblname'=>$collection->GetName()]);
        $res = $stmt->fetchAll();
        return $res[0][0] == 1;
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

        $stmt = $this->pdo->prepare("CREATE TABLE IF NOT EXISTS `{$collection->GetName()}` ({$columnStructureString})");
        $stmt->execute();

        return true;
    }

    // Entity/data manipulation
    public function Select(EntityDefinition $collection, ?Query $query) : array
    {
        if (!$this->OpenConnection()) {
            return [];
        }

        $columnsString = implode(',',array_map(fn(EntityFieldDefinition $field):string=>"`{$field->FieldName}`",$collection->GetFields()));
        $queryString = 'SELECT '.$columnsString.' FROM `'.$collection->GetName().'`';
        $queryString .= self::GetWhereStringFromQuery($query);
        $queryString .= self::GetOrderStringFromQuery($query);
        $queryString .= (($query->GetLimit()<=-1&&$query->GetOffset()==0)?'':' LIMIT '.($query->GetOffset()==0?'':''.$query->GetOffset().', ').$query->GetLimit());
        try {
            $stmt = $this->pdo->prepare($queryString);
            $stmt->execute($query==null?[]:$query->GetFilterParameters());
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == '42S02') {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }
        return [];
    }

    public function Count(EntityDefinition $collection, ?Query $query) : int
    {
        if (!$this->OpenConnection()) {
            return false;
        }
        
        $queryString = 'SELECT COUNT(*) FROM `'.$collection->GetName().'`';
        $queryString .= self::GetWhereStringFromQuery($query);
        try {
            $stmt = $this->pdo->prepare($queryString);
            $stmt->execute($query==null?[]:$query->GetFilterParameters());
            return $stmt->fetchAll()[0][0];
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == '42S02') {
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
        $columnsString = implode(',',array_map(fn(EntityFieldDefinition $field):string=>"`{$field->FieldName}`",array_keys($fieldValuesExceptAutoIncrement)));
        $valuePlaceholdersString = implode(',', array_map(
            fn($v):string=>':'.$v,
            array_keys($fieldValuesExceptAutoIncrement),
        ));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO `".$collection->GetName()."` (".$columnsString.") VALUES (".$valuePlaceholdersString.")");
            $stmt->execute($fieldValuesExceptAutoIncrement);
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == '42S02') {
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

        $columnsString = implode(',',array_map(fn(EntityFieldDefinition $field):string=>"`{$field->FieldName}`",$collection->GetFields()));
        $valuePlaceholdersString = implode(',', array_map(fn($k):string=>':'.$k,array_keys($fieldValues)));
        $pkField = $collection->GetPrimaryKeyField();
        $pkFieldName = $pkField == null ? null : $pkField->FieldName;
        $updateValuesPlaceholderString = implode(',', array_map(fn($k):string=>'`'.$k.'`=:'.$k,array_filter(array_keys($fieldValues),fn($k)=>$k!=$pkFieldName)));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO `".$collection->GetName()."` (".$columnsString.") VALUES (".$valuePlaceholdersString.") ON DUPLICATE KEY UPDATE ".$updateValuesPlaceholderString);
            $stmt->execute($fieldValues);
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == '42S02') {
                throw new TableNotFoundException($e->getMessage(), 0);
            } else {
                throw $e;
            }
        }

        if ($pkField == null) {
            return null;
        }

        $lastInsertId = $this->pdo->lastInsertId();
        if ($lastInsertId == 0) {
            return null;
        }
        return $lastInsertId ? null : intval($lastInsertId);
    }

    public function Delete(EntityDefinition $collection, Query $query) : int
    {
        if (!$this->OpenConnection()) {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare("DELETE FROM " . $collection->GetName() . self::GetWhereStringFromQuery($query));
            $stmt->execute($query->GetFilterParameters());
        } catch (PDOException $e) {
            if ($e->errorInfo[0] == '42S02') {
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
        $s = $field->FieldName.' '.$field->FieldType->value.($field->AutoIncrement?' AUTO_INCREMENT':'');
        return $s;
    }

    private static function getConstraintSQL(EntityFieldDefinition $field) : null|string {
        // column_name [def] [PRIMARY KEY|FOREIGN KEY]
        $constraints = [];
        if ($field->IsPrimaryKey) {
            $constraints[] = 'PRIMARY KEY (`'.$field->FieldName.'`)';
        }

        if ($field->IsForeignKey) {
            $s = 'FOREIGN KEY (`'.$field->FieldName.'`) REFERENCES `'.$field->ForeignKeyCollectionName.'`.`'.$field->foreignKeyCollectionFieldName . '`';
            $s .= ' ON UPDATE '.$field->ForeignKeyOnUpdate->value.' ON DELETE '.$field->ForeignKeyOnDelete->value;
            $constraints[] = $s;
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
            return ($filterTree['negated'] ? 'NOT ' : '') . '`' . $filterTree['field'] . '`' . ' ' . $filterTree['operator'] . ' :' . $filterTree['parameterKey'];
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
            return '`' . $o['field'] . '` ' . $o['direction']->value;
        }, $query->GetOrderTree()));
    }
}