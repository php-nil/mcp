# nil-mcp

基于 [Nil 框架](https://github.com/nil/framework) 与官方 **PHP MCP SDK**（`Mcp\`）的 MCP 客户端 / 服务器封装包。以 Streamable HTTP 传输提供「注册工具」「远端调用工具」「会话与工具清单缓存」三大能力，统一收敛会话治理、参数对齐、类型纠正等样板逻辑。

命名空间：`NilMcp`，通过 `Nil-boot.php` 以 `Nil::lazyLoad()` 声明 `NilMcp\ → src` 的 PSR-4 映射。

## 组件一览

| 类 | 职责 |
| --- | --- |
| `McpClient` | 以 Streamable HTTP 调用远端 MCP 服务器的工具，自动管理会话与工具清单 |
| `McpServer` | 以 Symfony `Request` 起步、走 `StreamableHttpTransport` 的 MCP 服务器入口 |
| `McpInterface` | 服务器端 MCP 契约，暴露 `builder()` |
| `McpAbstract` | `McpInterface` 的默认实现，按 `BUILDERS` 清单注册并做延迟实例化 |
| `McpBuilderAbstract` | 统一注册 `tool / resource / resource_template / prompt` 四种组件的基类 |
| `McpContainer` | 延迟实例化容器，`callTool` 时才 `new` 工具对象并注入 `params` |
| `CachedSchemaGenerator` | 带缓存的输入 schema 生成器（包装官方 `SchemaGenerator`） |
| `TestMcp` / `TestToolBuilder` | 最小可运行示例，演示 server 侧工具声明 |

## 快速开始

### 服务器端

```php
use NilMcp\McpServer;
use NilMcp\McpAbstract;
use NilMcp\McpBuilderAbstract;
use Mcp\Server\Builder;

// 1. 声明一个工具
class EchoTool extends McpBuilderAbstract
{
    public const string TYPE = 'tool';
    public const string NAME = 'echo';
    public const string DESCRIPTION = '原样返回输入';
    public const array OUTPUT = [
        'type' => 'object',
        'properties' => ['echo' => ['type' => 'string']],
    ];

    public function __invoke(string $text): array
    {
        return ['echo' => $text];
    }
}

// 2. 声明 MCP 服务（BUILDERS 顺序即注册顺序）
class MyMcp extends McpAbstract
{
    protected const array BUILDERS = [EchoTool::class];
}

// 3. 处理入站请求
$response = McpServer::start(\Symfony\Component\HttpFoundation\Request::createFromGlobals(), MyMcp::class);
$response->send();
```

### 客户端

```php
use NilMcp\McpClient;

// 裸 URL（无额外认证）
$client = new McpClient('https://example.com/mcp');

// 传入站 Request：endpoint 取其 URI，并自动透传 Authorization / Cookie
$client = new McpClient($inboundRequest);

$result = $client->run('echo', ['text' => 'hello']);

// 查看服务端工具清单（含 inputSchema / outputSchema）
$tools = $client->listTools();
```

## 客户端参数对齐与自愈

`run()` 在发请求前会拉取（或读缓存）目标工具的 `inputSchema`，并完成两件事：

1. **类型纠正**：把 HTTP 弱类型入参（`"123"`→`int`、`"true"`→`bool`，递归 array/object）纠正为 schema 声明的类型。
2. **本地预检**：用与服务端相同的 opis JSON Schema 校验器预检，缺必填 / 类型错在发请求前即抛 `InvalidArgumentException`。

自愈策略（均只重试一次）：

- 恢复的会话已过期 → 删除旧缓存、重新握手后重试；
- 服务端返回 `-32602`（清单滞后）→ 强制刷新工具清单后重试。

## 会话与缓存语义

| 项 | 位置 | TTL | 说明 |
| --- | --- | --- | --- |
| 客户端会话 ID | 文件缓存（`PhpFilesAdapter`） | `SESSION_TTL` = 7200s | 跨 CLI/FPM 进程共享，空闲过期后自动重握手 |
| 服务器会话状态 | APCu（`ApcuAdapter`） | `SESSION_TTL` = 7200s | FPM 多 worker 共享内存，无文件锁竞争，匹配并发场景 |
| 客户端工具清单 | 文件缓存 | `TOOLS_TTL` = 3600s | 按「endpoint + 认证身份」隔离 |
| 输入 schema | APCu（`ApcuAdapter`） | `SCHEMA_TTL` = 86400s | key 含源码 mtime，改代码自动失效 |

> 服务端（`McpServer`）运行于 FPM 需应对并发，故会话 / schema 统一走 APCu 共享内存
> （无文件锁竞争、服务端必装）。客户端（`McpClient`）需跨 CLI/FPM 进程复用会话，故走文件缓存。

## 关键约束

- 所有非 initialize 请求必须携带从初始化响应获得的 `Mcp-Session-Id` 头；复用会话时跳过握手。
- 会话缓存键使用 `sha256(endpoint | headers)`，实现「endpoint + 认证身份」隔离。
- 工具清单需分页游标拉取（服务端单页 20 个）。
- 会话数据修改后需显式 `SessionInterface::save()` 保存。