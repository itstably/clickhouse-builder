<?php

namespace ItStably\ClickhouseBuilder;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use ItStably\Clickhouse\Client;
use ItStably\ClickhouseBuilder\Exceptions\BuilderException;
use ItStably\ClickhouseBuilder\Exceptions\GrammarException;
use ItStably\ClickhouseBuilder\Exceptions\NotSupportedException;
use ItStably\ClickhouseBuilder\Query\Builder;
use ItStably\ClickhouseBuilder\Query\From;
use ItStably\ClickhouseBuilder\Query\JoinClause;

class ExceptionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function getBuilder() : Builder
    {
        return new Builder(m::mock(Client::class));
    }

    public function testBuilderException()
    {
        $e = BuilderException::cannotDetermineAliasForColumn();
        $this->assertInstanceOf(BuilderException::class, $e);
    }

    public function testGrammarException()
    {
        $e = GrammarException::missedTableForInsert();
        $this->assertInstanceOf(GrammarException::class, $e);

        $from = new From($this->getBuilder());

        $e = GrammarException::wrongFrom($from);
        $this->assertInstanceOf(GrammarException::class, $e);

        $join = new JoinClause($this->getBuilder());

        $e = GrammarException::wrongJoin($join);
        $this->assertInstanceOf(GrammarException::class, $e);
    }

    public function testNotSupportedException()
    {
        $e = NotSupportedException::transactions();
        $this->assertInstanceOf(NotSupportedException::class, $e);

        $e = NotSupportedException::update();
        $this->assertInstanceOf(NotSupportedException::class, $e);
    }
}
