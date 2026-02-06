<?php

namespace Pivel\Hydro2\Extensions;

use DateTime;
use Pivel\Hydro2\Models\Database\Order;
use Pivel\Hydro2\Models\EntityFieldDefinition;
use Pivel\Hydro2\Models\Geometry\Geometry;
use Pivel\Hydro2\Models\HTTP\Request;
use Pivel\Hydro2\Services\Entity\IEntityPersistenceProvider;

class Query
{
    public const AND = 'AND';
    public const OR = 'OR';
    public const EQUAL = '=';
    public const GREATER_THAN = '>';
    public const GREATER_THAN_OR_EQUAL = '>=';
    public const LESS_THAN = '<';
    public const LESS_THAN_OR_EQUAL = '<=';
    public const LIKE = 'LIKE';
    public const ST_WITHIN = 'ST_Within';
    public const ST_CONTAINS = 'ST_Contains';

    private int $offset;
    private int $limit;
    private array $filterParameters;
    /** @var array The outermost layer always has the booloperator set to AND. */
    private array $filterTree;
    // static so that it is unique across sub-trees. Don't need to worry about being thread safe as PHP is single-threaded.
    private static int $nextParameterNumber = 0;

    private array $order;

    public function __construct()
    {
        $this->offset = 0;
        $this->limit = -1;
        $this->filterParameters = [];
        $this->filterTree = [
            'booloperator' => self::AND,
            'operands' => [],
        ];
        $this->order = [];
    }

    public function GetRawFilterParameters() : array
    {
        return $this->filterParameters;
    }

    public function GetConvertedFilterParameters(IEntityPersistenceProvider $persistence_provider) : array
    {
        $convertedParameters = [];
        foreach ($this->filterParameters as $key => $value) {
            $field = new EntityFieldDefinition('', null);
            $field->PropertyType = gettype($value);
            if ($field->PropertyType === 'object') {
                $field->PropertyType = $value::class;
            }
            $convertedParameters[$key] = $persistence_provider::ConvertValueToStorage($field, $value);
        }

        return $convertedParameters;
    }

    public function GetFilterTree() : array
    {
        return $this->filterTree;
    }

    public function GetOrderTree() : array
    {
        return $this->order;
    }

    public function GetLimit() : int
    {
        return $this->limit;
    }

    public function GetOffset() : int
    {
        return $this->offset;
    }

    // filtering. Filter conditions are combined with AND.
    private function Condition(string $fieldName, mixed $value, string $operator, bool $negated=false) : Query
    {
        $parameterKey = 'p' . self::$nextParameterNumber++;
        $this->filterParameters[$parameterKey] = $value;

        $this->filterTree['operands'][] = [
            'operator' => $operator,
            'negated' => $negated,
            'field' => $fieldName,
            'parameterKey' => $parameterKey,
            'parameterValue' => $value,
        ];
        return $this;
    }

    public function Equal(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::EQUAL);
    }

    public function NotEqual(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::EQUAL, true);
    }

    public function GreaterThan(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::GREATER_THAN);
    }

    public function GreaterThanOrEqual(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::GREATER_THAN_OR_EQUAL);
    }

    public function LessThan(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::LESS_THAN);
    }

    public function LessThanOrEqual(string $fieldName, mixed $value) : Query
    {
        return $this->Condition($fieldName, $value, self::LESS_THAN_OR_EQUAL);
    }

    /**
     * @param string $pattern The pattern to search for. A % represents 0, 1, or multiple characters, and a _ represents a single character
     */
    public function Like(string $fieldName, string $pattern) : Query
    {
        return $this->Condition($fieldName, $pattern, self::LIKE);
    }

    /**
     * @param string $pattern The pattern to avoid. A % represents 0, 1, or multiple characters, and a _ represents a single character
     */
    public function NotLike(string $fieldName, string $pattern) : Query
    {
        return $this->Condition($fieldName, $pattern, self::LIKE, true);
    }

    // spatial operators

    public function SpatialWithin(string $fieldName, Geometry $geometry) : Query
    {
        return $this->Condition($fieldName, $geometry, self::ST_WITHIN);
    }

    public function SpatialContains(string $fieldName, Geometry $geometry) : Query
    {
        return $this->Condition($fieldName, $geometry, self::ST_CONTAINS);
    }

    // combination

    /**
     * Combines the filter tree of another query with AND. The other query's offset, limit, and order are used.
     */
    public function And(Query $query): Query
    {
        $newQuery = clone $this;
        $newQuery->filterParameters = array_merge($this->filterParameters, $query->GetRawFilterParameters());
        $newQuery->filterTree['operands'] = array_merge($this->filterTree['operands'], $query->GetFilterTree()['operands']);
        $newQuery->Slice($query->GetOffset(), $query->GetLimit());
        $newQuery->order = $query->GetOrderTree();

        return $newQuery;
    }

    /**
     * Combines the filter tree of another query with OR. The other query's offset, limit, and order are used.
     */
    public function Or(Query $query): Query
    {
        $newQuery = clone $this;
        $newQuery->filterParameters = array_merge($this->filterParameters, $query->GetRawFilterParameters());
        $newQuery->filterTree = [
            'booloperator' => self::AND,
            'operands' => [
                [
                    'booloperator' => self::OR,
                    'operands' => [
                        $this->filterTree,
                        $query->GetFilterTree(),
                    ],
                ],
            ],
        ];
        $newQuery->Slice($query->GetOffset(), $query->GetLimit());
        $newQuery->order = $query->GetOrderTree();

        return $newQuery;
    }

    // offset and limit

    /**
     * @param int $qty Limit resulting data set to $qty results. If -1, no limit.
     */
    public function Limit(int $qty) : Query
    {
        $this->limit = max(-1, $qty);

        return $this;
    }

    public function Offset(int $start) : Query
    {
        $this->offset = max(0, $start);
        return $this;
    }

    /**
     * Shorthand for setting both a limit and offset.
     * @param int $start
     * @param int $qty Limit resulting data set to $qty results. If -1, no limit.
     */
    public function Slice(int $start, int $qty) : Query
    {
        return $this->Offset($start)->Limit($qty);
    }

    // ordering

    public function OrderBy(string $fieldName, Order $direction) : Query
    {
        $this->order[] = [
            'field' => $fieldName,
            'direction' => $direction,
        ];

        return $this;
    }

    public static function SortSearchPageQueryFromRequest(
        Request $request,
        string $searchField="name",
        string $limitArg="limit",
        string $offsetArg="offset",
        string $sortByArg="sort_by",
        string $sortDirArg="sort_dir",
        string $searchArg="q",
    ) : Query
    {
        $query = new Query();
        $query->Limit($request->Args[$limitArg] ?? -1);
        $query->Offset($request->Args[$offsetArg] ?? 0);
        
        if (isset($request->Args[$sortByArg])) {
            $dir = Order::tryFrom(strtoupper($request->Args[$sortDirArg]??'asc'))??Order::Ascending;
            $query->OrderBy($request->Args[$sortByArg], $dir);
        }

        if (isset($request->Args[$searchArg]) && !empty($request->Args[$searchArg])) {
            $query->Like($searchField, '%' . str_replace('%', '\\%', str_replace('_', '\\_', $request->Args[$searchArg])) . '%');
        }

        return $query;
    }
}