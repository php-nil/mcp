<?php

namespace NilMcp;

use Mcp\Capability\Registry\Container;
use Psr\Container\ContainerInterface;

/**
 * 工具容器: 在真正调用工具时才实例化, 并注入当前 MCP 实例 ID
 *
 * 配合 ToolAbstract::register() 的类名 handler, 实现延迟实例化:
 * - listTools 阶段: SDK 只反射 handler 生成 schema, 不创建工具实例
 * - callTool 阶段: SDK 通过本容器 get() 才 new 工具对象(注入 mcp_id)
 */
final class McpContainer implements ContainerInterface
{
    private Container $inner;

    public function __construct(
        private readonly array $params,
    ) {
        $this->inner = new Container();
    }

    public function has(string $id): bool
    {
        return is_subclass_of($id, McpBuilderAbstract::class) || $this->inner->has($id);
    }

    public function get(string $id): mixed
    {
        if (is_subclass_of($id, McpBuilderAbstract::class)) {
            // 实例化工具构建器, 并注入当前 MCP 实例 ID
            return new $id($this->params);
        }

        return $this->inner->get($id);
    }
}
