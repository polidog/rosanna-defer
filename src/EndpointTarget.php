<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Runtime\PsxRenderer;

/**
 * 状態層なしのエンドポイントの描画で `RenderScope::$state` に置く値。
 *
 * @internal
 */
final readonly class EndpointTarget implements EndpointTargetScope
{
    public function __construct(
        private string $name,
        private string $component,
        private PsxRenderer $psx,
    ) {}

    public function isDeferredEndpointTarget(DeclaresDefer $component): bool
    {
        return $component->deferDeclaration()?->name === $this->name
            || $this->psx->loaded($this->component) === $component;
    }
}
