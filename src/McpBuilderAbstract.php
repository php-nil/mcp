<?php

namespace NilMcp;

use Mcp\Server\Builder;

/**
 * MCP 注册抽象基类(通用)
 *
 * 支持注册 tool / resource / resource_template / prompt 四种 MCP 组件:
 * - 子类通过 TYPE 常量声明注册类型, 覆写对应常量并实现 handler 方法
 * - 注册逻辑由基类 register() 统一分发, 新增工具/资源 = 新建一个文件
 * - handler 传类名而非实例: SDK 反射生成 schema 时加载类文件, 实例推迟到真正调用时由容器创建
 * - 需要 mcp_id 的组件通过 $this->mcp_id 访问(由容器 McpContainer 构造注入)
 *
 * 各类型必填/可选常量:
 * - tool:               NAME, OUTPUT 必填; INPUT 可选(显式输入 schema, 默认反射 __invoke 参数)
 * - resource:           URI 必填; NAME/DESCRIPTION/MIME_TYPE 可选
 * - resource_template:  URI_TEMPLATE 必填; NAME/DESCRIPTION/MIME_TYPE 可选
 * - prompt:             NAME 必填; DESCRIPTION 可选
 * - 通用:               HANDLER 可选(handler 方法名, 默认 __invoke)
 */
abstract class McpBuilderAbstract
{
    /** 注册类型: tool | resource | resource_template | prompt (子类必须覆写) */
    public const string TYPE = 'tool';

    /** 组件名 (tool/resource/prompt 使用, tool/prompt 必填) */
    public const string NAME = '';

    /** 组件描述 (可选) */
    public const string DESCRIPTION = '';

    /** 输出标准 (tool 必填) */
    public const array OUTPUT = [];

    /** 显式输入 schema (tool 可选, 空则 SDK 反射 __invoke 参数自动生成) */
    public const array INPUT = [];

    /** handler 方法名 (默认 __invoke) */
    public const string HANDLER = '__invoke';

    /** 资源 URI (resource 必填) */
    public const string URI = '';

    /** 资源 URI 模板 (resource_template 必填) */
    public const string URI_TEMPLATE = '';

    /** MIME 类型 (resource / resource_template 可选) */
    public const string MIME_TYPE = '';

    /**
     * @param array $params 参数数组
     */
    public function __construct(
        protected array $params,
    ) {
    }

    /**
     * 注册到 MCP(按 TYPE 分发)
     */
    public static function register(Builder $builder): void
    {
        if (!method_exists(static::class, static::HANDLER)) {
            throw new \LogicException(static::class . ' 必须实现 ' . static::HANDLER . '()');
        }

        $handler = [static::class, static::HANDLER];

        match (static::TYPE) {
            'tool' => self::registerTool($builder, $handler),
            'resource' => self::registerResource($builder, $handler),
            'resource_template' => self::registerResourceTemplate($builder, $handler),
            'prompt' => self::registerPrompt($builder, $handler),
            default => throw new \LogicException(static::class . " 的 TYPE '" . static::TYPE . "' 不支持(仅 tool/resource/resource_template/prompt)"),
        };
    }

    /**
     * 注册工具
     */
    private static function registerTool(Builder $builder, array $handler): void
    {
        if (static::NAME === '') {
            throw new \LogicException(static::class . ' 必须覆写 NAME 常量');
        }
        if (static::OUTPUT === []) {
            throw new \LogicException(static::class . ' 必须覆写 OUTPUT 常量');
        }

        $builder->addTool(
            handler: $handler,
            name: static::NAME,
            description: static::DESCRIPTION ?: null,
            inputSchema: static::INPUT !== [] ? static::INPUT : null,
            outputSchema: static::OUTPUT,
        );
    }

    /**
     * 注册资源
     */
    private static function registerResource(Builder $builder, array $handler): void
    {
        if (static::URI === '') {
            throw new \LogicException(static::class . ' 必须覆写 URI 常量');
        }

        $builder->addResource(
            handler: $handler,
            uri: static::URI,
            name: static::NAME !== '' ? static::NAME : null,
            description: static::DESCRIPTION !== '' ? static::DESCRIPTION : null,
            mimeType: static::MIME_TYPE !== '' ? static::MIME_TYPE : null,
        );
    }

    /**
     * 注册资源模板
     */
    private static function registerResourceTemplate(Builder $builder, array $handler): void
    {
        if (static::URI_TEMPLATE === '') {
            throw new \LogicException(static::class . ' 必须覆写 URI_TEMPLATE 常量');
        }

        $builder->addResourceTemplate(
            handler: $handler,
            uriTemplate: static::URI_TEMPLATE,
            name: static::NAME !== '' ? static::NAME : null,
            description: static::DESCRIPTION !== '' ? static::DESCRIPTION : null,
            mimeType: static::MIME_TYPE !== '' ? static::MIME_TYPE : null,
        );
    }

    /**
     * 注册提示模板
     */
    private static function registerPrompt(Builder $builder, array $handler): void
    {
        if (static::NAME === '') {
            throw new \LogicException(static::class . ' 必须覆写 NAME 常量');
        }

        $builder->addPrompt(
            handler: $handler,
            name: static::NAME,
            description: static::DESCRIPTION !== '' ? static::DESCRIPTION : null,
        );
    }
}
