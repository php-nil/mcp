<?php

namespace NilMcp;

use Mcp\Server\Builder;

/**
 * MCP 注册抽象基类
 *
 * BUILDERS 即注册清单(tool/resource/resource_template/prompt), 顺序即注册顺序.
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
     * 已完成初始化的具体子类(按实际调用类隔离)
     *
     * FPM 下每个请求天然只执行一次; 常驻运行时(RoadRunner/Swoole 等)或同进程
     * 多次构建时, 据此保证每个子类的 _init() 仍只运行一次。
     *
     * @var array<string, true>
     */
    private static array $initialized = [];

    /**
     * 构建
     */
    public static function builder(Builder $builder, array $params): void
    {
        // 延迟实例化: 注册只登记类名, 工具对象在 callTool 时才由容器创建
        $builder->setContainer(new McpContainer($params));

        foreach (static::BUILDERS as $class) {
            // fail-fast: 类名拼错/继承错要在启动注册阶段直接暴露, 而不是静默漏注册
            if (!class_exists($class)) {
                throw new \LogicException(static::class . " 的 BUILDERS 包含不存在的类: {$class}");
            }
            if (!is_subclass_of($class, McpBuilderAbstract::class)) {
                throw new \LogicException($class . ' 必须继承 ' . McpBuilderAbstract::class);
            }

            $class::register($builder);
        }

        // 初始化 - 每个具体子类只运行一次
        if (!isset(self::$initialized[static::class])) {
            static::_init();
            self::$initialized[static::class] = true;
        }
    }

    /**
     * 初始化钩子 - 每个具体子类只运行一次
     */
    protected static function _init()
    {}
}