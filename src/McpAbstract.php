<?php

namespace NilMcp;

use Mcp\Server\Builder;

/**
 * MCP 注册抽象基类
 *
 * TOOLS 列表即注册清单, 顺序即注册顺序.
 */
class McpAbstract implements McpInterface
{
    /**
     * 服务器名称
     */
    public const string SERVER_NAME = 'app';

    /**
     * 版本号
     */
    public const string SERVER_VERSION = 'DEV';

    /**
     * 服务器描述
     */
    public const ?string SERVER_DESCRIPTION = null;

    /**
     * 图标
     */
    public const ?string SERVER_ICON = null;

    /**
     * websiteUrl
     */
    public const ?string SERVER_WEBSITE_URL = null;

    /**
     * title 服务器标题
     */
    public const ?string SERVER_TITLE = null;

    /**
     * 指令
     */
    public const ?string INSTRUCTIONS = null;

    /**
     * 工具注册清单(顺序即注册顺序)
     */
    protected const array BUILDERS = [
    ];

    /**
     * 构建
     */
    public static function builder(Builder $builder, array $params)
    {
        // 延迟实例化: 注册只登记类名, 工具对象在 callTool 时才由容器创建
        $builder->setContainer(new McpContainer($params));

        foreach (static::BUILDERS as $class) {
            if (is_subclass_of($class, McpBuilderAbstract::class)) {
                $class::register($builder);
            }
        }

        // 初始化
        static::_init();
    }

    /**
     * 初始化 - 只运行一次
     */
    protected static function _init()
    {}
}