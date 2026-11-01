<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * `RenderScope::$state` に置く、「いま描いているのはどの defer の対象か」の答え。
 * 対象自身だけがプレースホルダーを飛ばし、入れ子の defer はプレースホルダーのままにする (RT-6.2、RT-9.4)。
 * コンポーネントの描画のたびに 1 回呼ばれる。対象が自分の中にもう 1 つあれば、2 回目以降は入れ子として false を返す (DF-6)。
 */
interface EndpointTargetScope
{
    public function isDeferredEndpointTarget(DeclaresDefer $component): bool;
}
