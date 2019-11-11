<?php

namespace ItStably\ClickhouseBuilder\Query\Traits;

use ItStably\ClickhouseBuilder\Query\BaseBuilder;
use ItStably\ClickhouseBuilder\Query\Column;

trait ColumnsComponentCompiler
{
    use ColumnCompiler;

    /**
     * Compiles columns for select statement.
     *
     * @param BaseBuilder $builder
     * @param Column[]    $columns
     *
     * @return string
     */
    private function compileColumnsComponent(BaseBuilder $builder, array $columns) : string
    {
        $compiledColumns = [];
        
        foreach ($columns as $column) {
            $compiledColumns[] = $this->compileColumn($column);
        }

        return implode(', ', $compiledColumns);
    }
}
