<?php

namespace NilMcp;

use Nil\Nil;
use Nil\Kernel\Kernel;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Result\CallToolResult;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request;
use Monolog\Logger;

/**
 * MCP 客户端: 以 Streamable HTTP 调用远端 MCP 服务器的工具
 *
 * 典型用法:
 *   // 裸 URL(无额外认证)
 *   $client = new McpClient('https://example.com/mcp');
 *
 *   // 传入站 Request: endpoint 取其 URI, 并自动透传 Authorization/Cookie(auto 认证)
 *   $client = new McpClient($request);
 *
 *   // 注入已配置认证(签名/服务间 token 等)的 PSR-18 客户端
 *   $client = new McpClient('https://example.com/mcp', httpClient: $authedClient);
 *
 *   $result = $client->run('tool_name', ['foo' => 'bar']);
 *
 *   // 查看服务端工具清单与每个工具的参数要求(inputSchema 为 JSON Schema)
 *   $tools = $client->listTools();
 *
 * 工具清单与参数对齐:
 *  - run() 前会自动拉取(或读缓存)服务端 tools/list, 按目标工具的 inputSchema:
 *    1) 纠正弱类型入参(HTTP 表单全是 string: "123"→int、"true"→bool, 递归 array/object);
 *    2) 用与服务端相同的 opis JSON Schema 校验器本地预检, 缺必填/类型错在发请求前即抛异常;
 *  - 工具清单按「endpoint + 认证身份」缓存到文件(跨 CLI/FPM 共享, TTL 见 TOOLS_TTL),
 *    服务端返回 -32602(清单滞后)时自动强制刷新并重试一次;
 *  - 未知工具名本地直接抛 InvalidArgumentException(附带可用工具列表)。
 *
 * 会话复用(跨 CLI/FPM 生命周期自动持久化):
 *  - 首次连接走 initialize 握手, 服务端在 Mcp-Session-Id 响应头下发会话 ID;
 *  - 会话 ID 以「endpoint + 认证身份」为键存入文件缓存(CLI 与 FPM 共享),
 *    之后任意进程构造本类时自动取出复用, 无需调用方手动传递;
 *  - initialize 协商出的协议版本随会话一同缓存, 恢复时原样回填
 *    (服务端可能还价更旧版本, 不能硬按最新版本);
 *  - MCP 规范禁止 initialize 请求携带 session ID, 故复用会话时跳过握手;
 *  - 每次成功调用刷新缓存 TTL, 与服务端空闲过期(见 McpServer::SESSION_TTL)对齐;
 *  - 会话在服务端过期时自动删除旧缓存、重新握手并重试一次;
 *  - 调用结束不主动 DELETE 会话, 由服务端 TTL 回收。
 */
class McpClient
{
    /**
     * MCP 客户端前缀
     */
    public const string PREFIX = 'mcp_client';

    /**
     * initialize 握手超时（秒）
     */
    public const int CONNECT_TIMEOUT = 30;

    /**
     * 单次 RPC 请求超时（秒）
     */
    public const int REQUEST_TIMEOUT = 120;

    /**
     * 本地会话缓存有效期（秒）。
     * 每次成功调用都会刷新，实际语义为「空闲多久未活动即失效」。
     */
    public const int SESSION_TTL = 7200;

    /**
     * 工具清单缓存有效期（秒）。
     * 服务端工具变更后最多延迟这么久感知，-32602 时会立即强制刷新。
     */
    public const int TOOLS_TTL = 3600;

    /**
     * 会话 ID 响应头
     */
    public const string SESSION_HEADER = 'Mcp-Session-Id';

    /**
     * 从入站 Request 自动继承的认证头（auto 认证透传）
     */
    private const array AUTH_HEADERS = ['Authorization', 'Cookie'];

    protected Logger $logger;

    /**
     * MCP 服务端 endpoint URL
     */
    protected string $endpoint;

    /**
     * 会话 ID 缓存(文件缓存, CLI 与 FPM 进程共享)
     */
    protected CacheInterface $cache;

    /**
     * 本连接在会话缓存中的键(endpoint + 认证身份)
     */
    protected string $cacheKey;

    /**
     * initialize 握手协商出的协议版本(随会话一起持久化/恢复)
     *
     * 服务端可能不支持客户端提议的最新版本而还价更旧版本, 且服务端按会话
     * 记录协商结果(见 SDK InitializeHandler), 故恢复会话时必须还原真实值,
     * 不能假设为最新握手版本。
     */
    private ?ProtocolVersion $protocolVersion = null;

    /**
     * 工具清单缓存键(与 session 同身份隔离, 但不绑定具体会话)
     */
    protected string $toolsCacheKey;

    /**
     * 本次生命周期内已加载的工具清单: name => 工具定义
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $tools = null;

    /**
     * JSON Schema 校验器(与服务端同一个 opis 实现, 本地预检结论与服务端一致)
     */
    private ?SchemaValidator $validator = null;

    private ?Client $mcp = null;

    /**
     * 会话 ID 记录器(从响应头捕获, SDK 的 HttpTransport 未暴露该值)
     *
     * @var object{sessionId: ?string}
     */
    private object $recorder;

    /**
     * @param string|Request              $request    MCP 服务端地址；传入站 Symfony Request 时
     *                                                endpoint 取其 URI 并自动透传认证头
     * @param string|null                 $session    显式指定会话 ID；为 null 时自动从缓存恢复
     * @param array<string, string>       $headers    额外/覆盖的请求头（认证等）
     * @param ClientInterface|null        $httpClient 已配置认证的 PSR-18 客户端；
     *                                                为 null 时使用默认 Symfony 客户端
     */
    public function __construct(
        string|Request $request,
        private ?string $session = null,
        private array $headers = [],
        private ?ClientInterface $httpClient = null,
    ) {
        $this->logger = Kernel::getLog()->withStreamLogger(self::PREFIX);

        // 文件缓存而非 APCu: CLI 与 FPM 的 APCu 内存不共享, 文件才能跨进程复用会话
        $this->cache = new Psr16Cache(
            Kernel::getCache()->createPhpFilesAdapter(self::PREFIX . 'client')
        );

        if ($request instanceof Request) {
            $this->endpoint = $request->getUri();

            // auto 认证: 透传入站请求携带的身份凭证; $headers 显式传入者优先
            foreach (self::AUTH_HEADERS as $name) {
                if (!isset($this->headers[$name]) && $request->headers->has($name)) {
                    $this->headers[$name] = $request->headers->get($name);
                }
            }
        } else {
            $this->endpoint = $request;
        }

        // 缓存键包含认证身份, 避免不同用户/凭证串用同一份数据
        $identity = hash('sha256', $this->endpoint . '|' . json_encode($this->headers));
        $this->cacheKey = 'sess_' . $identity;
        $this->toolsCacheKey = 'tools_' . $identity;

        if (null === $this->session) {
            $cached = $this->cache->get($this->cacheKey);

            // 兼容旧格式(裸 sid 字符串): 那时不记录版本, 按最新握手版本处理
            if (\is_string($cached) && '' !== $cached) {
                $cached = ['id' => $cached, 'v' => ProtocolVersion::V2025_11_25->value];
            }

            if (\is_array($cached) && \is_string($cached['id'] ?? null) && '' !== $cached['id']) {
                $this->session = $cached['id'];
                $this->protocolVersion = ProtocolVersion::tryFrom((string) ($cached['v'] ?? ''))
                    ?? ProtocolVersion::V2025_11_25;
            }
        }
    }

    /**
     * 调用远端 MCP 工具
     *
     * @param string               $method 工具名
     * @param array<string, mixed> $params 工具参数
     * @return array<string, mixed>
     */
    public function run(string $method, array $params = []): array
    {
        $resumed = null !== $this->session;

        try {
            return $this->call($method, $params);
        } catch (ConnectionException|RequestException $e) {
            // 两类可恢复错误, 都只重试一次:
            //  1) 恢复的会话已被服务端回收(空闲超过 TTL) -> 重新握手;
            //  2) 本地缓存的工具清单滞后(服务端新增/改名/改参) -> 强制刷新清单。
            $recover = null;

            if ($resumed && $this->isDeadSession($e)) {
                $this->forgetSession();
                $this->mcp = null;
                $recover = '会话已失效，重新初始化后重试';
            } elseif ($this->isInvalidParams($e)) {
                $this->forgetTools();
                $recover = '服务端拒绝参数，刷新工具清单后重试';
            }

            if (null !== $recover) {
                $this->logger->warning('MCP ' . $recover, [
                    'tool' => $method,
                    'session' => $this->session,
                    'error' => $e->getMessage(),
                ]);

                return $this->call($method, $params);
            }

            throw $e;
        }
    }

    /**
     * 获取本次连接使用的会话 ID
     *
     * 正常情况下会话已自动持久化，无需调用方保存；此方法仅供排查/显式管理。
     */
    public function getSession(): ?string
    {
        return $this->session;
    }

    /**
     * 获取本次会话协商的协议版本(如 2025-11-25)
     *
     * 新握手前或尚未连接时返回 null。
     */
    public function getProtocolVersion(): ?string
    {
        return ($this->mcp?->getProtocolVersion() ?? $this->protocolVersion)?->value;
    }

    /**
     * 获取服务端工具清单(名称 => 工具定义, 含 description/inputSchema/outputSchema)
     *
     * 结果按「endpoint + 认证身份」缓存到文件(CLI 与 FPM 共享), 生命周期内只查一次;
     * 服务端工具变更后最迟 TOOLS_TTL 秒感知, 也可传 $refresh 强制拉取。
     *
     * @param bool $refresh 跳过缓存, 强制向服务端重新拉取
     * @return array<string, array{
     *     name: string,
     *     title?: ?string,
     *     description?: ?string,
     *     inputSchema: array<string, mixed>,
     *     outputSchema?: array<string, mixed>|null,
     * }>
     */
    public function listTools(bool $refresh = false): array
    {
        return $this->getTools($refresh);
    }

    /**
     * 建立连接（首次握手或恢复会话）并调用工具
     *
     * @param array<string, mixed> $params
     */
    private function call(string $method, array $params): array
    {
        if (null === $this->mcp || !$this->mcp->isConnected()) {
            $this->connect();
        }

        // 与服务端参数格式对齐: 按服务端 inputSchema 纠正类型并预检
        $params = $this->normalizeArguments($params, $this->getTool($method));

        $result = $this->mcp->callTool($method, $params);

        if (null !== $this->recorder->sessionId) {
            $this->session = $this->recorder->sessionId;
        }

        // 成功调用后续期会话缓存, 与服务端「读写刷新」对齐
        if (null !== $this->session) {
            $this->persistSession();
        }

        return $this->toArray($result);
    }

    /**
     * 持久化会话 ID 与本次握手协商的协议版本
     */
    private function persistSession(): void
    {
        // 以连接状态里的协商结果为准(新握手时由 SDK initialize 写入;
        // 恢复会话时由缓存版本回填), 与服务端会话记录保持一致
        $negotiated = $this->mcp?->getProtocolVersion();
        if (null !== $negotiated) {
            $this->protocolVersion = $negotiated;
        }

        $this->cache->set($this->cacheKey, [
            'id' => $this->session,
            'v' => ($this->protocolVersion ?? ProtocolVersion::V2025_11_25)->value,
        ], self::SESSION_TTL);
    }

    /**
     * 删除已持久化的会话
     */
    private function forgetSession(): void
    {
        $this->session = null;
        $this->protocolVersion = null;
        $this->cache->delete($this->cacheKey);
    }

    /**
     * 读取工具清单(内存 -> 文件缓存 -> 服务端)
     *
     * @return array<string, array<string, mixed>>
     */
    private function getTools(bool $refresh): array
    {
        if (!$refresh && null !== $this->tools) {
            return $this->tools;
        }

        if (!$refresh) {
            $cached = $this->cache->get($this->toolsCacheKey);
            if (\is_array($cached) && [] !== $cached) {
                return $this->tools = $cached;
            }
        }

        if (null === $this->mcp || !$this->mcp->isConnected()) {
            $this->connect();
        }

        $tools = $this->fetchTools();

        $this->cache->set($this->toolsCacheKey, $tools, self::TOOLS_TTL);

        return $this->tools = $tools;
    }

    /**
     * 分页拉取全部工具(服务端单页最多 20 个)
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchTools(): array
    {
        $tools = [];
        $cursor = null;

        do {
            $page = $this->mcp->listTools($cursor);

            foreach ($page->tools as $tool) {
                // 直接取 Tool 的规范化数组(空子 schema 为 stdClass),
                // 不做 JSON 往返: 那样会把 {} 变成 [] 使校验器误判
                $entry = [
                    'name' => $tool->name,
                    'title' => $tool->title,
                    'description' => $tool->description,
                    'inputSchema' => $tool->inputSchema,
                ];

                if (null !== $tool->outputSchema) {
                    $entry['outputSchema'] = $tool->outputSchema;
                }

                $tools[$tool->name] = $entry;
            }

            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $tools;
    }

    /**
     * 清空工具清单缓存(内存 + 文件)
     */
    private function forgetTools(): void
    {
        $this->tools = null;
        $this->cache->delete($this->toolsCacheKey);
    }

    /**
     * 按名称取工具定义; 本地清单未命中时强制刷新一次再确认
     *
     * @return array<string, mixed>
     */
    private function getTool(string $name): array
    {
        $tools = $this->getTools(false);

        if (!isset($tools[$name])) {
            // 服务端可能刚注册了新工具, 强刷后再判定不存在
            $tools = $this->getTools(true);
        }

        if (!isset($tools[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'MCP 工具 "%s" 不存在；服务端可用工具: %s',
                $name,
                [] === $tools ? '(无)' : implode('、', array_keys($tools))
            ));
        }

        return $tools[$name];
    }

    /**
     * 按服务端 inputSchema 对齐参数: 先类型纠正, 再用与服务端相同的
     * JSON Schema 校验器预检, 把错误挡在发请求之前
     *
     * @param array<string, mixed>      $params
     * @param array<string, mixed>      $tool   工具定义
     * @return array<string, mixed>
     */
    private function normalizeArguments(array $params, array $tool): array
    {
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = $tool['inputSchema'] ?? ['type' => 'object'];

        $params = $this->coerceObject($params, $inputSchema);

        $errors = $this->validator()->validateAgainstJsonSchema($params, $inputSchema);

        if ([] !== $errors) {
            $messages = [];
            foreach (\array_slice($errors, 0, 5) as $error) {
                $pointer = $error['pointer'] ?? '';
                $prefix = ('/' !== $pointer && '' !== $pointer) ? "属性 '{$pointer}': " : '';
                $messages[] = $prefix . ($error['message'] ?? '校验失败');
            }

            throw new \InvalidArgumentException(sprintf(
                "MCP 工具 '%s' 参数不合法: %s",
                $tool['name'] ?? '?',
                implode('; ', $messages)
            ));
        }

        return $params;
    }

    /**
     * 按 object schema 逐属性纠正类型
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function coerceObject(array $value, array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        if (!\is_array($properties)) {
            return $value;
        }

        foreach ($properties as $name => $propertySchema) {
            if (\is_array($propertySchema) && \array_key_exists($name, $value)) {
                $value[$name] = $this->coerceValue($value[$name], $propertySchema);
            }
        }

        return $value;
    }

    /**
     * 按属性 schema 纠正单个值的类型
     *
     * 只在目标类型唯一且值可安全转换时处理(HTTP 入参常全是 string);
     * 多类型联合(type 为数组)不猜测, 交给校验器判定。
     */
    private function coerceValue(mixed $value, array $schema): mixed
    {
        if (null === $value) {
            return null;
        }

        $type = $schema['type'] ?? null;

        if (!\is_string($type)) {
            return $value;
        }

        switch ($type) {
            case 'integer':
                return \is_string($value) && 1 === preg_match('/^-?\d+$/', $value)
                    ? (int) $value
                    : $value;

            case 'number':
                if (\is_string($value) && \is_numeric($value)) {
                    return strpbrk($value, '.eE') === false ? (int) $value : (float) $value;
                }

                return $value;

            case 'boolean':
                return match (true) {
                    \is_bool($value) => $value,
                    \in_array($value, ['true', '1', 1], true) => true,
                    \in_array($value, ['false', '0', 0], true) => false,
                    default => $value,
                };

            case 'array':
                if (!\is_array($value)) {
                    return $value;
                }

                $items = $schema['items'] ?? null;
                if (!\is_array($items)) {
                    return $value;
                }

                // items 为关联数组=单元素 schema; 索引数组=draft-07 元组
                if (array_is_list($items)) {
                    foreach ($items as $i => $itemSchema) {
                        if (\is_array($itemSchema) && \array_key_exists($i, $value)) {
                            $value[$i] = $this->coerceValue($value[$i], $itemSchema);
                        }
                    }
                } else {
                    foreach ($value as $i => $item) {
                        $value[$i] = $this->coerceValue($item, $items);
                    }
                }

                return $value;

            case 'object':
                return \is_array($value) ? $this->coerceObject($value, $schema) : $value;

            default:
                return $value;
        }
    }

    private function validator(): SchemaValidator
    {
        return $this->validator ??= new SchemaValidator($this->logger);
    }

    /**
     * 构建客户端与 HTTP 传输并建立连接
     *
     * 是否跳过握手(恢复会话)由传输层依据 $this->session 是否为 null 自行判断
     */
    private function connect(): void
    {
        // PSR-17 工厂(与实际传输客户端分离: 注入的 httpClient 只负责发请求)
        $psr17 = new Psr18Client();
        $httpClient = $this->httpClient ?? $psr17;

        // 装饰 PSR-18: 从响应头捕获会话 ID，并关联「无 id 错误」
        $this->recorder = new class($httpClient, $psr17) implements ClientInterface {
            public ?string $sessionId = null;

            public function __construct(
                private readonly ClientInterface $inner,
                private readonly StreamFactoryInterface $streamFactory,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = $this->inner->sendRequest($request);

                if ($response->hasHeader(McpClient::SESSION_HEADER)) {
                    $this->sessionId = $response->getHeaderLine(McpClient::SESSION_HEADER);
                }

                // 会话解析失败(如恢复的会话已过期)时, 服务端会下发不携带
                // 请求 id 的错误, SDK 无法关联它, 只会干等到请求超时。
                // 这里把错误响应的 id 回填为当前请求 id, 让调用方立刻收到异常。
                if (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
                    $body = (string) $response->getBody();

                    if ('' !== $body && str_contains($body, '"error"')) {
                        $data = json_decode($body, true);
                        $requestId = json_decode((string) $request->getBody(), true)['id'] ?? null;

                        if (\is_array($data)
                            && isset($data['error'])
                            && ($data['id'] ?? null) === null
                            && null !== $requestId
                        ) {
                            $data['id'] = $requestId;
                            $body = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
                        }
                    }

                    // 上面已读取过流, 统一重建, 保证 SDK 能再次读到完整 body
                    $response = $response->withBody($this->streamFactory->createStream($body));
                }

                return $response;
            }
        };

        // 恢复会话时跳过 initialize 握手: 仅把协议状态标记为已就绪,
        // 会话 ID 通过请求头携带。状态($state)由 Protocol::connect()
        // 在 Client::connect() 调用本传输 connect() 之前注入。
        $transport = new class($this->endpoint, $this->session, $this->protocolVersion, $this->headers, $this->recorder, $psr17, $this->logger) extends HttpTransport {
            private bool $resume;

            /**
             * 该会话在 initialize 中协商出的版本(恢复时必须与服务端会话记录一致)
             */
            private ProtocolVersion $version;

            public function __construct(
                string $endpoint,
                ?string $sessionId,
                ?ProtocolVersion $version,
                array $headers,
                ClientInterface $httpClient,
                Psr18Client $psr17,
                ?LoggerInterface $logger,
            ) {
                $this->resume = null !== $sessionId;
                $this->version = $version ?? ProtocolVersion::V2025_11_25;

                parent::__construct(
                    endpoint: $endpoint,
                    headers: $this->resume
                        ? ([McpClient::SESSION_HEADER => $sessionId] + $headers)
                        : $headers,
                    httpClient: $httpClient,
                    requestFactory: $psr17,
                    streamFactory: $psr17,
                    logger: $logger,
                );
            }

            public function connect(): void
            {
                if (!$this->resume) {
                    parent::connect();

                    return;
                }

                \assert(null !== $this->state);

                $this->state->setInitialized(true);
                $this->state->setProtocolVersion($this->version);
            }
        };

        $this->mcp = Client::builder()
            ->setClientInfo('nil-mcp-client', '1.0.0')
            ->setInitTimeout(self::CONNECT_TIMEOUT)
            ->setRequestTimeout(self::REQUEST_TIMEOUT)
            ->setLogger($this->logger)
            ->build();

        $this->mcp->connect($transport);
    }

    /**
     * 判断异常是否为「会话不存在或已过期」
     */
    private function isDeadSession(\Throwable $e): bool
    {
        if (!$e instanceof RequestException || null === $e->getError()) {
            return false;
        }

        return 1 === \preg_match('/session not found|session.*expired|valid session id/i', $e->getError()->message);
    }

    /**
     * 判断异常是否为「参数/工具名不被接受」(-32602)
     *
     * 服务端对未知工具名与参数校验失败都回这个码, 通常意味着本地工具清单已滞后。
     */
    private function isInvalidParams(\Throwable $e): bool
    {
        return $e instanceof RequestException
            && null !== $e->getError()
            && Error::INVALID_PARAMS === $e->getError()->code;
    }

    /**
     * 把 CallToolResult 规范为数组
     *
     * 优先取 structuredContent(声明了 outputSchema 的工具走这条);
     * 否则尝试从唯一的文本内容还原 JSON, 再退化为 content 列表。
     *
     * @return array<string, mixed>
     */
    private function toArray(CallToolResult $result): array
    {
        if ($result->isError) {
            throw new \RuntimeException('MCP 工具调用失败: ' . $this->textOf($result));
        }

        if (null !== $result->structuredContent) {
            return \is_array($result->structuredContent)
                ? $result->structuredContent
                : ['result' => $result->structuredContent];
        }

        $texts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $texts[] = (string) $content->text;
            }
        }

        if (1 === \count($texts)) {
            $decoded = \json_decode($texts[0], true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return ['content' => $texts];
    }

    /**
     * 提取结果中的全部文本（换行拼接）
     */
    private function textOf(CallToolResult $result): string
    {
        $texts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $texts[] = (string) $content->text;
            }
        }

        return \implode("\n", $texts);
    }
}
