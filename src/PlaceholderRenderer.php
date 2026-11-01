<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Runtime\DeferredRenderer;
use Polidog\Rosanna\Runtime\Element;

/**
 * defer のプレースホルダー。属性名は rosanna-defer.js との契約。
 */
final readonly class PlaceholderRenderer implements DeferredRenderer
{
    public function __construct(private string $prefix = DeferredEndpoint::DEFAULT_PREFIX) {}

    public function renderDeferred(Element $element, \Closure $render): string
    {
        $name = $element->props['__name'] ?? null;
        if (!is_string($name) || !Defer::isValidName($name)) {
            throw new \RuntimeException(sprintf('Deferred component name must match %s, got %s.', Defer::NAME_PATTERN, self::quote($name)));
        }

        $params = $element->props['__params'] ?? [];
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

        $url = rtrim($this->prefix, '/') . '/' . rawurlencode($name);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $attributes = sprintf('data-rosanna-defer-url="%s"', self::escape($url));
        if (($element->props['__localCache'] ?? false) === true) {
            $attributes .= ' data-rosanna-defer-cache';
            $ttl = $element->props['__localCacheTtl'] ?? 0;
            if (is_int($ttl) && $ttl > 0) {
                $attributes .= sprintf(' data-rosanna-defer-cache-ttl="%d"', $ttl);
            }
        }
        if (($element->props['__reloadable'] ?? false) === true) {
            $attributes .= sprintf(' data-rosanna-defer-name="%s"', self::escape($name));
        }

        $fallback = $element->props['__fallback'] ?? null;

        return sprintf('<div %s>%s</div>', $attributes, $fallback instanceof Element ? $render($fallback) : '');
    }

    /** ENT_SUBSTITUTE がないと、不正な UTF-8 の 1 バイトで値全体が空になる (RT-3.1) */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function quote(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
