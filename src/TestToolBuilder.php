<?php

namespace NilMcp;

use Mcp\Capability\Attribute\Schema;
/**
 * MCP 注册抽象基类(通用)
 */
class TestToolBuilder extends McpBuilderAbstract
{
    /** 组件名 (tool/resource/prompt 使用, tool/prompt 必填) */
    public const string NAME = 'test_tool';

    /** 组件描述 (tool 必填) */
    public const string DESCRIPTION = '这是一个测试工具';

    /** 输出标准 (tool 必填) */
    public const array OUTPUT = [
        'type' => 'object',
        'properties' => [
            'params' => ['type' => 'object', 'description' => '输入参数对象'],
            'input' => ['type' => 'string', 'description' => '输入参数'],
            'message' => ['type' => 'string', 'description' => '操作结果说明'],
        ],
        'required' => ['message', 'input', 'params'],
    ];

    public function __invoke(
        #[Schema(type: 'string', description: '随机一个字符串')]
        string $input
    ): array {
        return [
            'params' => $this->params,
            'input' => $input,
            'message' => '这是一个测试工具，时间：' . date('Y-m-d H:i:s')
        ];
    }
}
