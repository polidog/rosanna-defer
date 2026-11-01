<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderScope;

/**
 * 状態層なしで defer を使う入口。登録と設定だけを持ち、リクエストごとの情報は残さない。
 * URL からページを選ぶのは host の役割で、host は `handleDeferred()` をページの処理より前に呼ぶ。
 * state も使うアプリは代わりに `polidog/rosanna-state` の `RosannaState` を使う。
 */
final class RosannaDefer
{
    private Rosanna $rosanna;

    private DeferredRegistry $deferred;

    private string $prefix = DeferredEndpoint::DEFAULT_PREFIX;

    public function __construct()
    {
        $this->deferred = new DeferredRegistry();
        $this->rosanna = new Rosanna(
            scopeFactory: static fn(PsxRenderer $psx, ?RenderScope $outer): RenderScope => new RenderScope($psx),
            deferredFactory: fn(RenderScope $scope): PlaceholderRenderer => $this->placeholderRenderer(),
        );
    }

    public function registerComponent(string $fqcn, callable $component): self
    {
        $this->rosanna->registerComponent($fqcn, $component);

        return $this;
    }

    /**
     * `rosanna compile` の成果物は defer を調べていないので受けない (DF-13)。
     *
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき、deferred manifest がないか build ID が合わないとき
     */
    public function loadComponentManifest(string $path): self
    {
        $this->rosanna->loadComponentManifest($path);
        foreach (DeferredManifestCompiler::read($path) as $name => $entry) {
            $this->deferred->registerIfNew((string) $name, $entry['component'], $entry['cacheControl']);
        }

        return $this;
    }

    public function setArgumentResolver(PsxArgumentResolver $resolver): self
    {
        $this->rosanna->setArgumentResolver($resolver);

        return $this;
    }

    /**
     * 同じ名前の登録は例外 (DF-3)。名前は URL になるので、別の内容を黙って上書きしない。
     *
     * @throws \InvalidArgumentException 名前が不正か、登録済みのとき
     */
    public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self
    {
        $this->deferred->register($name, $component, $cacheControl);

        return $this;
    }

    /** @throws \InvalidArgumentException `/` で始まらないか、ルートそのものを指すとき */
    public function setDeferPrefix(string $prefix): self
    {
        $this->prefix = DeferredEndpoint::normalizePrefix($prefix);

        return $this;
    }

    public function scope(): RenderScope
    {
        return $this->rosanna->scope();
    }

    public function renderElement(Element $element): string
    {
        return $this->rosanna->renderElement($element);
    }

    /** 自分の `Renderer` を持つ host のため。`new Renderer(deferred: $app->placeholderRenderer())` */
    public function placeholderRenderer(): PlaceholderRenderer
    {
        return new PlaceholderRenderer($this->prefix);
    }

    /** `{prefix}/{name}` の GET でなければ null で、host の処理に任せる */
    public function handleDeferred(?DeferRequest $request = null): ?DeferResponse
    {
        return new DeferredEndpoint($this->deferred, $this->prefix, new PsxFragmentRenderer($this->rosanna))
            ->handle($request ?? DeferRequest::fromGlobals());
    }

    public static function renderClientScript(string $src = ClientScript::DEFAULT_SRC, ?string $deferPrefix = null, ?string $nonce = null): string
    {
        return ClientScript::render($src, $deferPrefix, $nonce);
    }
}
