<?php

namespace NilMcp;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 带缓存的 Schema 生成器(装饰器)
 *
 * 为什么需要:
 * 手动注册(addTool)的工具走 ReflectedElementLoader, 每次 Registry::load() 都会对
 * 每个工具做一遍「new ReflectionMethod + DocBlock 解析 + 逐参数反射 + schema 生成」,
 * 官网内置的 CachedDiscoverer 只覆盖属性自动发现(#[McpTool])那条路, 手动注册用不上。
 *
 * 官方给手动注册留的扩展点是 Builder::setSchemaGenerator(SchemaGeneratorInterface),
 * 本类就是它的实现: 包一层官方 SchemaGenerator, 把 generate() 的纯数组结果缓存起来。
 * 属于官方扩展点 + PSR-16 缓存, 与 CachedDiscoverer 同一套模式, 不是改 SDK。
 *
 * 缓存 key = 类名::方法名 + 源码文件 mtime:
 * 工具代码一改(mtime 变)就自动走新 key, 旧 key 自然过期, 不需要手动清缓存。
 *
 * 用法:
 *   $builder->setSchemaGenerator(CachedSchemaGenerator::create($cache));
 */
class CachedSchemaGenerator implements SchemaGeneratorInterface
{
    /** 默认缓存 key 前缀(与 session 的 'mcp-' 前缀互不干扰) */
    public const string PREFIX = 'mcp_schema_';

    /** 默认缓存有效期(秒) */
    public const int TTL = 86400;

    public function __construct(
        protected SchemaGeneratorInterface $inner,
        protected CacheInterface $cache,
        protected string $prefix = self::PREFIX,
        protected int $ttl = self::TTL,
    ) {
    }

    /**
     * 默认包装官方反射生成器
     */
    public static function create(
        CacheInterface $cache,
        ?SchemaGeneratorInterface $inner = null,
        int $ttl = self::TTL,
    ): static {
        return new static(
            $inner ?? new SchemaGenerator(new DocBlockParser()),
            $cache,
            self::PREFIX,
            $ttl,
        );
    }

    /**
     * 生成输入 schema(命中缓存时直接返回数组, 不再反射)
     */
    public function generate(\Reflector $reflection): array
    {
        $key = $this->cacheKey($reflection);
        if (null === $key) {
            return $this->inner->generate($reflection);
        }

        $schema = $this->cache->get($key);
        if (\is_array($schema)) {
            return $schema;
        }

        $schema = $this->inner->generate($reflection);
        $this->cache->set($key, $schema, $this->ttl);

        return $schema;
    }

    /**
     * 输出 schema 只在 #[McpTool] 属性上显式声明时才有值,
     * 本项目由 addTool(outputSchema:) 显式传入, 不走这里, 直接透传。
     */
    public function generateOutputSchema(\Reflector $reflection): ?array
    {
        return $this->inner->generateOutputSchema($reflection);
    }

    /**
     * 缓存 key: 类名::方法名 + 文件 mtime
     * 返回 null 表示无法稳定缓存(闭包等), 直接走原生成器。
     */
    protected function cacheKey(\Reflector $reflection): ?string
    {
        if (!$reflection instanceof \ReflectionMethod) {
            return null;
        }

        $class = $reflection->getDeclaringClass();
        $file = (string) $class->getFileName();
        $mtime = '' === $file ? 0 : (@filemtime($file) ?: 0);

        return $this->prefix . md5(
            $class->getName() . '::' . $reflection->getName() . '#' . $mtime
        );
    }
}
