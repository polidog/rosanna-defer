<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Internal\Functions;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * REQUEST_URI をパスとクエリ文字列に分ける。フラグメントは捨てる。
 * `//host/x` をオーソリティとして読むと、サーバーが見るパスと別のパスになるので parse_url() に渡さない (RQ-1)
 *
 * @internal
 * @return array{string, string} パスとクエリ文字列
 */
function splitRequestUri(string $requestUri): array
{
    $uri = explode('#', $requestUri, 2)[0];

    if (str_starts_with($uri, '/')) {
        $parts = explode('?', $uri, 2);

        return ['/' . ltrim($parts[0], '/'), $parts[1] ?? ''];
    }

    $parsed = parse_url($uri);

    return [
        '/' . ltrim(is_array($parsed) ? ($parsed['path'] ?? '') : '', '/'),
        is_array($parsed) ? ($parsed['query'] ?? '') : '',
    ];
}

/**
 * DF-4。クエリを props にする。`key` がインスタンスの ID を決めるので、URL から他人の状態を選ばせない。
 * 値がスカラーかどうかは見ない
 *
 * @internal
 * @param array<array-key, mixed> $query
 * @return array<string, mixed>
 */
function deferredProps(array $query): array
{
    $props = [];
    foreach ($query as $param => $value) {
        $param = (string) $param;
        if ($param === 'key' || $param === 'fallback') {
            continue;
        }
        $props[$param] = $value;
    }

    return $props;
}
