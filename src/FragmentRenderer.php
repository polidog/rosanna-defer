<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * エンドポイントの対象を HTML にする。状態層はここで署名の scope と描画パスを用意する。
 */
interface FragmentRenderer
{
    /**
     * @param string $name 登録名
     * @param string $component 登録の component (PSX の FQCN か、状態層のコンポーネント名)
     * @param array<string, mixed> $props
     * @throws \Throwable 描画の例外。エンドポイントが 500 にする (DF-7)
     */
    public function render(string $name, string $component, array $props, DeferRequest $request): string;
}
