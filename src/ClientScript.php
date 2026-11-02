<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * rosanna-defer.js の配信。CSP の nonce で動くよう、インラインのイベントハンドラを使わない。
 */
final class ClientScript
{
    /**
     * 外部の rosanna-defer.js が読めないときだけ defer を 1 回取得する。
     * defer の script は DOMContentLoaded の前に動くので、その時点で reloadDefer がなければ読み込みに失敗している。
     */
    private const DEFER_FALLBACK_SCRIPT = <<<'JS'
        (function(){
        window.Rosanna=window.Rosanna||{};
        var B='data-rosanna-defer-fallback-loading';
        function warn(m,u){if(window.console&&console.warn)console.warn('[Rosanna] '+m,u);}
        function prefix(){var r=window.Rosanna,p=r&&typeof r.deferPrefix==='string'&&r.deferPrefix!==''?r.deferPrefix:'/_defer';return p.replace(/\/+$/,'');}
        function allowed(url){try{var u=new URL(url,location.href);return u.origin===location.origin&&u.pathname.indexOf(prefix()+'/')===0;}catch(e){return false;}}
        function scan(root,ancestors){root.querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded]):not(['+B+'])').forEach(function(el){load(el,ancestors||[]);});}
        function place(el,html,ancestors){var t=document.createElement('template');t.innerHTML=html;scan(t.content,ancestors);if(el.hasAttribute('data-rosanna-defer-name')){el.replaceChildren(t.content);el.setAttribute('data-rosanna-defer-loaded','');}else{el.replaceWith(t.content);}}
        function load(el,ancestors){var url=el.getAttribute('data-rosanna-defer-url');if(!allowed(url)){warn('refusing defer fetch outside the same-origin defer prefix',url);return;}if(ancestors.indexOf(url)!==-1){warn('refusing defer fetch: the fragment contains itself',url);return;}el.setAttribute(B,'');el.setAttribute('aria-busy','true');fetch(url,{method:'GET',headers:{'X-Rosanna-Defer':'1'},credentials:'same-origin'}).then(function(r){var type=(r.headers.get('content-type')||'').trim().toLowerCase();if(!r.ok||type.indexOf('text/html')!==0||(r.url&&!allowed(r.url)))return null;return r.text();}).then(function(html){if(html!==null)place(el,html,ancestors.concat(url));}).catch(function(e){warn('defer fallback fetch failed',e);}).finally(function(){el.removeAttribute(B);el.removeAttribute('aria-busy');});}
        function check(){setTimeout(function(){if(!(window.Rosanna&&window.Rosanna.reloadDefer))scan(document);},0);}
        if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',check,{once:true});else check();
        })();
        JS;

    public const DEFAULT_SRC = '/rosanna-defer.js';

    public static function path(): string
    {
        return dirname(__DIR__) . '/public/rosanna-defer.js';
    }

    /**
     * RT-12.6。$src はエスケープし、$deferPrefix は JS の文字列として `</` で script を閉じられないように出す。
     * setDeferPrefix() を使うアプリは同じ値を渡す。
     */
    public static function render(string $src = self::DEFAULT_SRC, ?string $deferPrefix = null, ?string $nonce = null): string
    {
        return self::inline($deferPrefix, $nonce) . self::tag($src, $nonce);
    }

    /** prefix の設定とフォールバック。外部の script より前に置く */
    public static function inline(?string $deferPrefix = null, ?string $nonce = null): string
    {
        $nonceAttribute = self::nonceAttribute($nonce);

        $prefixScript = '';
        if ($deferPrefix !== null) {
            $json = json_encode($deferPrefix, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $prefixScript = "<script{$nonceAttribute}>window.Rosanna=window.Rosanna||{};window.Rosanna.deferPrefix={$json};</script>\n";
        }

        return $prefixScript . "<script{$nonceAttribute}>" . self::DEFER_FALLBACK_SCRIPT . "</script>\n";
    }

    public static function tag(string $src = self::DEFAULT_SRC, ?string $nonce = null): string
    {
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" defer' . self::nonceAttribute($nonce) . '></script>';
    }

    private static function nonceAttribute(?string $nonce): string
    {
        return $nonce === null ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
}
