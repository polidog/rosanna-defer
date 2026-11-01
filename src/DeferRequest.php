<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * エンドポイントが見るリクエストの部分。host は自分の Request からこれを作る。
 */
final readonly class DeferRequest
{
    /** @param array<array-key, mixed> $query */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public string $queryString = '',
    ) {}

    public static function fromGlobals(): self
    {
        $uri = explode('#', self::server('REQUEST_URI') ?? '/', 2)[0];

        // `//host/x` をオーソリティとして読むと、サーバーが見るパスと別のパスになるので parse_url() に渡さない (RQ-1)
        if (str_starts_with($uri, '/')) {
            $parts = explode('?', $uri, 2);
            $path = '/' . ltrim($parts[0], '/');
            $queryString = $parts[1] ?? '';
        } else {
            $parsed = parse_url($uri);
            $path = '/' . ltrim(is_array($parsed) ? ($parsed['path'] ?? '') : '', '/');
            $queryString = is_array($parsed) ? ($parsed['query'] ?? '') : '';
        }

        return new self(strtoupper(self::server('REQUEST_METHOD') ?? 'GET'), $path, $_GET, $queryString);
    }

    private static function server(string $name): ?string
    {
        $value = $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
