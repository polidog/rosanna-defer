<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RenderScope;

/**
 * 状態層なしの描画。セッションも描画パスも使わない。
 *
 * @internal
 */
final readonly class PsxFragmentRenderer implements FragmentRenderer
{
    public function __construct(private Rosanna $rosanna) {}

    public function render(string $name, string $component, array $props, DeferRequest $request): string
    {
        $psx = $this->rosanna->psx();
        $scope = new RenderScope($psx, new EndpointTarget($name, $component, $psx));

        return RenderContext::run($scope, fn(): string => $this->rosanna->renderElement($psx->render($component, $props)));
    }
}
