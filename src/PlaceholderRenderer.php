<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use function Polidog\Rosanna\Defer\Internal\Functions\deferUrl;
use function Polidog\Rosanna\Defer\Internal\Functions\escape;
use function Polidog\Rosanna\Defer\Internal\Functions\quote;

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
            throw new \RuntimeException(sprintf('Deferred component name must match %s, got %s.', Defer::NAME_PATTERN, quote($name)));
        }

        $url = deferUrl($this->prefix, $name, $element->props['__params'] ?? []);

        $attributes = sprintf('data-rosanna-defer-url="%s"', escape($url));
        if (($element->props['__localCache'] ?? false) === true) {
            $attributes .= ' data-rosanna-defer-cache';
            $ttl = $element->props['__localCacheTtl'] ?? 0;
            if (is_int($ttl) && $ttl > 0) {
                $attributes .= sprintf(' data-rosanna-defer-cache-ttl="%d"', $ttl);
            }
        }
        if (($element->props['__reloadable'] ?? false) === true) {
            $attributes .= sprintf(' data-rosanna-defer-name="%s"', escape($name));
        }

        $fallback = $element->props['__fallback'] ?? null;

        return sprintf('<div %s>%s</div>', $attributes, $fallback instanceof Element ? $render($fallback) : '');
    }
}
