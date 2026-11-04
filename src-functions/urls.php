<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Internal\Functions;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * 末尾の `/` を除く。`//` で始まるとオーソリティとして読まれるので受けない
 *
 * @internal
 * @throws \InvalidArgumentException `/` で始まらないか、ルートそのものを指すとき
 */
function normalizePrefix(string $prefix): string
{
    $normalized = rtrim($prefix, '/');
    if ($normalized === '' || !str_starts_with($normalized, '/') || str_starts_with($normalized, '//')) {
        throw new \InvalidArgumentException("Defer prefix must be a path like '/_defer', got: '{$prefix}'");
    }

    return $normalized;
}

/**
 * DF-1。パスが prefix か prefix 配下なら、prefix の後ろをデコードした名前を返す。prefix そのものは空の名前になる。
 * 名前の検証はしない
 *
 * @internal
 */
function deferredNameFromPath(string $prefix, string $path): ?string
{
    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
        return null;
    }

    return rawurldecode(substr($path, strlen($prefix) + 1));
}

/**
 * プレースホルダーが取得する `{prefix}/{name}?{params}`。null の値は送らず、真偽値は `1` と `0` にする
 *
 * @internal
 * @throws \RuntimeException params のキーが空でない文字列でないか、値がスカラーでないとき
 */
function deferUrl(string $prefix, string $name, mixed $params): string
{
    $query = [];
    foreach (is_array($params) ? $params : [] as $key => $value) {
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException("Deferred component '{$name}' params must use non-empty string keys.");
        }
        if ($value === null) {
            continue;
        }
        if (!is_scalar($value)) {
            throw new \RuntimeException("Deferred component '{$name}' param '{$key}' must be scalar; got " . get_debug_type($value));
        }
        $query[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    $url = rtrim($prefix, '/') . '/' . rawurlencode($name);
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}
