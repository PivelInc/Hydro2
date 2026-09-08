<?php

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Pivel\Hydro2\Extensions\Query;
use Pivel\Hydro2\Models\Database\Order;

#[CoversClass(Query::class)]
#[UsesClass(Query::class)]
class QueryTest extends TestCase
{
    public function testConstruction()
    {
        $query = new Query();

        $this->assertInstanceOf(Query::class, $query);
        $this->assertEquals(0, $query->GetOffset());
        $this->assertEquals(-1, $query->GetLimit());
        $this->assertEquals([], $query->GetRawFilterParameters());
        $this->assertEquals([], $query->GetOrderTree());
        $this->assertEquals($query::AND, $query->GetFilterTree()['booloperator']);
        $this->assertEquals([], $query->GetFilterTree()['operands']);
    }

    public function testEqual()
    {
        $query = new Query();

        $query->Equal('fakeField', 'fakeValue');

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::EQUAL, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals('fakeValue', $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testNotEqual()
    {
        $query = new Query();

        $query->NotEqual('fakeField', 'fakeValue');

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::EQUAL, $operands[0]['operator']);
        $this->assertEquals(true, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals('fakeValue', $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testGreaterThan()
    {
        $query = new Query();

        $query->GreaterThan('fakeField', 0);

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::GREATER_THAN, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals(0, $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testGreaterThanOrEqual()
    {
        $query = new Query();

        $query->GreaterThanOrEqual('fakeField', 0);

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::GREATER_THAN_OR_EQUAL, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals(0, $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testLessThan()
    {
        $query = new Query();

        $query->LessThan('fakeField', 0);

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::LESS_THAN, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals(0, $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testLessThanOrEqual()
    {
        $query = new Query();

        $query->LessThanOrEqual('fakeField', 0);

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::LESS_THAN_OR_EQUAL, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals(0, $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }
    
    public function testLike()
    {
        $query = new Query();

        $query->Like('fakeField', 'fakeValue');

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::LIKE, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals('fakeValue', $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }
    
    public function testNotLike()
    {
        $query = new Query();

        $query->NotLike('fakeField', 'fakeValue');

        $operands = $query->GetFilterTree()['operands'];

        $this->assertCount(1, $query->GetRawFilterParameters());
        $this->assertCount(1, $operands);
        $this->assertEquals(Query::LIKE, $operands[0]['operator']);
        $this->assertEquals(true, $operands[0]['negated']);
        $this->assertEquals('fakeField', $operands[0]['field']);
        $this->assertEquals('fakeValue', $query->GetRawFilterParameters()[$operands[0]['parameterKey']]);
    }

    public function testLimit()
    {
        $query = new Query();

        $query->Limit(1);

        $this->assertEquals(1, $query->GetLimit());
    }

    public function testOffset()
    {
        $query = new Query();

        $query->Offset(1);

        $this->assertEquals(1, $query->GetOffset());
    }

    public function testSlice()
    {
        $query = new Query();

        $query->Slice(1, 2);

        $this->assertEquals(1, $query->GetOffset());
        $this->assertEquals(2, $query->GetLimit());
    }

    public function testOrder()
    {
        $query = new Query();

        $query->OrderBy('fakeField', Order::Ascending);

        $orderTree = $query->GetOrderTree();

        $this->assertCount(1, $orderTree);
        $this->assertEquals('fakeField', $orderTree[0]['field']);
        $this->assertEquals(Order::Ascending, $orderTree[0]['direction']);
    }

    public function testAnd()
    {
        $query1 = new Query();
        $query2 = new Query();
        
        $query1->Equal('fakeField1', 1);
        $query1->Limit(1);
        $query1->Offset(2);
        $query1->OrderBy('fakeField2', Order::Ascending);

        $query2->NotEqual('fakeField3', 2);
        $query2->Limit(3);
        $query2->Offset(4);
        $query2->OrderBy('fakeField4', Order::Descending);

        $combinedQuery = $query1->And($query2);

        $operands = $combinedQuery->GetFilterTree()['operands'];

        $this->assertCount(2, $combinedQuery->GetRawFilterParameters());
        $this->assertEquals(Query::AND, $combinedQuery->GetFilterTree()['booloperator']);
        $this->assertCount(2, $operands);

        $this->assertEquals(Query::EQUAL, $operands[0]['operator']);
        $this->assertEquals(false, $operands[0]['negated']);
        $this->assertEquals('fakeField1', $operands[0]['field']);
        $this->assertEquals(1, $combinedQuery->GetRawFilterParameters()[$operands[0]['parameterKey']]);
        
        $this->assertEquals(Query::EQUAL, $operands[1]['operator']);
        $this->assertEquals(true, $operands[1]['negated']);
        $this->assertEquals('fakeField3', $operands[1]['field']);
        $this->assertEquals(2, $combinedQuery->GetRawFilterParameters()[$operands[1]['parameterKey']]);

        $orderTree = $combinedQuery->GetOrderTree();

        $this->assertCount(1, $orderTree);
        $this->assertEquals('fakeField4', $orderTree[0]['field']);
        $this->assertEquals(Order::Descending, $orderTree[0]['direction']);
        
        $this->assertEquals(3, $combinedQuery->GetLimit());
        $this->assertEquals(4, $combinedQuery->GetOffset());
    }

    public function testOr()
    {
        $query1 = new Query();
        $query2 = new Query();
        
        $query1->Equal('fakeField1', 1);
        $query1->Limit(1);
        $query1->Offset(2);
        $query1->OrderBy('fakeField2', Order::Ascending);

        $query2->NotEqual('fakeField3', 2);
        $query2->Limit(3);
        $query2->Offset(4);
        $query2->OrderBy('fakeField4', Order::Descending);

        $combinedQuery = $query1->Or($query2);

        $outerOperands = $combinedQuery->GetFilterTree()['operands'];
        $this->assertCount(2, $combinedQuery->GetRawFilterParameters());
        $this->assertEquals(Query::AND, $combinedQuery->GetFilterTree()['booloperator']);
        $this->assertCount(1, $outerOperands);

        $orOperands = $outerOperands[0]['operands'];
        $this->assertEquals(Query::OR, $outerOperands[0]['booloperator']);
        $this->assertCount(2, $orOperands);

        $firstOperands = $orOperands[0]['operands'];
        $this->assertCount(1, $firstOperands);
        $this->assertEquals(Query::AND, $orOperands[0]['booloperator']);
        $this->assertEquals(Query::EQUAL, $firstOperands[0]['operator']);
        $this->assertEquals(false, $firstOperands[0]['negated']);
        $this->assertEquals('fakeField1', $firstOperands[0]['field']);
        $this->assertEquals(1, $combinedQuery->GetRawFilterParameters()[$firstOperands[0]['parameterKey']]);
        
        $secondOperands = $orOperands[1]['operands'];
        $this->assertCount(1, $secondOperands);
        $this->assertEquals(Query::AND, $orOperands[1]['booloperator']);
        $this->assertEquals(Query::EQUAL, $secondOperands[0]['operator']);
        $this->assertEquals(true, $secondOperands[0]['negated']);
        $this->assertEquals('fakeField3', $secondOperands[0]['field']);
        $this->assertEquals(2, $combinedQuery->GetRawFilterParameters()[$secondOperands[0]['parameterKey']]);

        $orderTree = $combinedQuery->GetOrderTree();

        $this->assertCount(1, $orderTree);
        $this->assertEquals('fakeField4', $orderTree[0]['field']);
        $this->assertEquals(Order::Descending, $orderTree[0]['direction']);
        
        $this->assertEquals(3, $combinedQuery->GetLimit());
        $this->assertEquals(4, $combinedQuery->GetOffset());
    }
}