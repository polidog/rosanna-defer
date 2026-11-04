<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use function Polidog\Rosanna\Defer\Internal\Functions\splitRequestUri;

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
        [$path, $queryString] = splitRequestUri(self::server('REQUEST_URI') ?? '/');

        return new self(strtoupper(self::server('REQUEST_METHOD') ?? 'GET'), $path, $_GET, $queryString);
    }

    private static function server(string $name): ?string
    {
        $value = $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
