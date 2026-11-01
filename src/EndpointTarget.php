<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Runtime\PsxRenderer;

/**
 * 状態層なしのエンドポイントの描画で `RenderScope::$state` に置く値。
 *
 * @internal
 */
final class EndpointTarget implements EndpointTargetScope
{
    private bool $claimed = false;

    public function __construct(
        private readonly string $name,
        private readonly string $component,
        private readonly PsxRenderer $psx,
    ) {}

    /** 最初の一致だけが対象。対象はまず描かれるので、同じコンポーネントが自分の中にあればそれは入れ子である (DF-6) */
    public function isDeferredEndpointTarget(DeclaresDefer $component): bool
    {
        if ($this->claimed) {
            return false;
        }

        return $this->claimed = $component->deferDeclaration()?->name === $this->name
            || $this->psx->loaded($this->component) === $component;
    }
}
