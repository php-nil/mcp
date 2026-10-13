<?php

namespace NilMcp;

use Nil\Kernel\Kernel;
use Mcp\Server;
use Mcp\Schema\Icon;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Schema\JsonRpc\Error;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\SimpleCache\CacheInterface;
use Monolog\Logger;

class McpServer
{
    /**
     * MCP 服务器前缀
     */
    public const string PREFIX = 'mcp_server';
    /**
     * MCP 会话有效期（秒）。每次读写都会刷新，实际是「空闲多久未活动即过期」。
     * 默认 7200（2 小时）。
     */
    public const int SESSION_TTL = 7200;

    /**
     * 工具 schema 缓存有效期（秒）。
     * key 里带了源码文件 mtime，改代码自动失效，这里只是兜底回收。
     */
    public const int SCHEMA_TTL = 86400;

    /**
     * 处理无效请求错误
     */
    public function errorInvalidRequest($message): JsonResponse
    {
        return $this->rpcError(Error::forInvalidRequest($message));
    }

    /**
     * 处理内部错误
     */
    public function errorInternalError($message): JsonResponse
    {
        return $this->rpcError(Error::forInternalError($message));
    }

    /**
     * 处理 RPC 错误
     *
     * 内部异常细节只写日志, 不进入响应体, 避免向客户端泄漏路径/SQL/连接串等信息
     */
    protected function rpcError(Error $error, ?\Throwable $exception = null): JsonResponse
    {
        $json = $error->jsonSerialize();

        $context = null !== $exception ? $json + ['exception' => $exception] : $json;
        $this->logger->error('rpc error', $context);

        return new JsonResponse($json, 400);
    }

    /**
     * 日志
     */
    protected Logger $logger;

    /**
     * 缓存
     */
    protected CacheInterface $cache;

    /**
     * MCP 服务器构建器
     */
    protected Server\Builder $builder;

    public function __construct(protected Request $request)
    {
        $this->logger = Kernel::getLog()->withStreamLogger(self::PREFIX);

        // 缓存适配器: session 与 schema 共用(靠 key 前缀区分)。
        // McpServer 运行于 FPM, 需应对并发; APCu 为进程间共享内存、
        // 无文件锁竞争, 且服务端必装, 故此处用 APCu 而非文件缓存。
        $this->cache = new Psr16Cache(
            Kernel::getCache()->createApcuAdapter(self::PREFIX)
        );

        $this->builder = Server::builder();
    }

    /**
     * 获取 MCP 服务器构建器
     * 
     * 可以在构建器上调用以下方法(建议使用)：
     *  setServerInfo($name, $version)
     *  setInstructions($instructions)
     */
    public function getBuilder(): Server\Builder
    {
        return $this->builder;
    }

    /**
     * 启动 MCP 服务器
     */
    public static function start(Request $request, string $class, array $params = []): Response
    {
        return new self($request)->run($class, $params);
    }

    /**
     * 处理 MCP 请求
     * 
     * @param string $class MCP 类名
     * @param array $params 参数数组
     */
    public function run(string $class, array $params = []): Response
    {
        if (!class_exists($class) || !is_subclass_of($class, McpInterface::class)) {
            return $this->rpcError(
                Error::forInternalError("server {$class} class is not valid MCP")
            );
        }

        // 域名
        $host = $this->request->getHost();
        if (empty($host)) {
            return $this->errorInvalidRequest('host is required');
        }

        // PSR-7 server request
        $psr17Factory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

        $psrRequest = $psrHttpFactory->createRequest($this->request);

        $transport = new StreamableHttpTransport(
            request: $psrRequest,
            responseFactory: $psr17Factory,
            streamFactory: $psr17Factory,
            middleware: [
                new CorsMiddleware(allowedOrigins: ['*']),
                new DnsRebindingProtectionMiddleware(allowedHosts: [$host, 'localhost', '127.0.0.1']),
            ],
            logger: $this->logger
        );

        // Build and run the server
        // 不调用 setProtocolVersion，交由 SDK 按时代自行处理：
        //  - 握手时代（2025-11-25 及更早）：InitializeHandler 按 initialize
        //    里的 protocolVersion 逐会话协商，缺省支持全部握手版本；
        //  - 现代（2026-07-28 起）没有 initialize，版本由每请求的
        //    params._meta 携带，builder 默认构建的 StatelessProtocol 直接处理。
        $builder = $this->builder
            ->setLogger($this->logger)
            // 缓存工具的反射 schema: 官方只给属性自动发现内置了缓存,
            // 手动注册(addTool)走这条扩展点, 命中后每个请求省掉全量反射
            ->setSchemaGenerator(
                CachedSchemaGenerator::create($this->cache, ttl: self::SCHEMA_TTL)
            )
            ->setSession(
                sessionStore: new Psr16SessionStore($this->cache, 'mcp-', self::SESSION_TTL),
            );

        // 设置服务器信息
        if (is_subclass_of($class, McpAbstract::class)) {
            // 设置指令
            if ($class::INSTRUCTIONS) {
                $builder->setInstructions($class::INSTRUCTIONS);
            }

            $icon = null === $class::SERVER_ICON ? null : [new Icon($class::SERVER_ICON)];

            // 设置服务器信息
            $builder->setServerInfo(
                $class::SERVER_NAME,
                $class::SERVER_VERSION,
                $class::SERVER_DESCRIPTION ?? $class::INSTRUCTIONS,
                $icon,
                $class::SERVER_WEBSITE_URL,
                $class::SERVER_TITLE ?? $class::SERVER_NAME,
            );
        }


        try {
            // 注册阶段也可能抛异常: BUILDERS fail-fast、register() 的必填常量校验、
            // addTool/addResource 的 schema 校验等。统一收敛为 JSON-RPC 错误,
            // 避免配置错误时端点吐出框架默认 HTML 错误页
            $class::builder($builder, $params);

            $server = $builder->build();

            $psrResponse = $server->run($transport);

            // Log the response body for debugging
            // $body = (string) $psrResponse->getBody();
            // $statusCode = $psrResponse->getStatusCode();
            // $headers = $psrResponse->getHeaders();
            // $logger->info('MCP response sent', [
            //     'status' => $statusCode,
            //     'headers' => array_keys($headers),
            //     'body' => $body,
            // ]);

            // Convert the PSR-7 response back to a Symfony HttpFoundation Response
            // Use streamed=false to ensure headers and body are sent properly
            $symfonyResponse = new HttpFoundationFactory()->createResponse($psrResponse, streamed: false);

            return $symfonyResponse;
        } catch (\Throwable $e) {
            // $e->getMessage() 可能含文件路径/SQL/连接串等内部细节,
            // 只写入服务端日志, 响应统一返回通用文案
            return $this->rpcError(
                Error::forServerError('internal server error'),
                $e
            );
        }
    }
}