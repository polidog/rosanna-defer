<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Runtime\AcceptsArgumentResolver;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;

/**
 * `deferred()` が返す、state を持たないコンポーネント。ページではプレースホルダーになり、
 * エンドポイントの描画でだけ中身を描く。
 */
final class Deferred implements AcceptsArgumentResolver, DeclaresDefer
{
    /** @var (\Closure(array<string, mixed>): list<mixed>)|null */
    private ?\Closure $argumentResolver = null;

    public function __construct(
        public readonly \Closure $inner,
        public readonly Defer $defer,
    ) {}

    public function deferDeclaration(): Defer
    {
        return $this->defer;
    }

    /**
     * 第 2 引数以降を解決する口 (RT-11.3)。
     *
     * @internal PsxRenderer のため
     * @param \Closure(array<string, mixed>): list<mixed> $resolver
     */
    public function withArgumentResolver(\Closure $resolver): self
    {
        $component = clone $this;
        $component->argumentResolver = $resolver;

        return $component;
    }

    /**
     * @param array<string, mixed> $props
     * @throws \InvalidArgumentException プレースホルダーの props が不正なとき
     */
    public function __invoke(array $props = []): Element
    {
        $scope = RenderContext::currentScope()?->state;
        if (!$scope instanceof EndpointTargetScope || !$scope->isDeferredEndpointTarget($this)) {
            return $this->defer->buildPlaceholder($props);
        }

        $arguments = $this->argumentResolver === null ? [] : ($this->argumentResolver)($props);
        $result = ($this->inner)($props, ...$arguments);
        if (!$result instanceof Element) {
            throw new \RuntimeException(sprintf("Deferred component '%s' must return an Element; got %s.", $this->defer->name, get_debug_type($result)));
        }

        return $result;
    }
}
