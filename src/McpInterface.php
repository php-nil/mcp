<?php

namespace NilMcp;

use Mcp\Server\Builder;

interface McpInterface
{
    /**
     * 构建
     */
    public static function builder(Builder $builder, array $params);
}
