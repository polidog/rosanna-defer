<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * `{prefix}/{name}` の GET に、登録された対象の HTML で答える。
 *
 * @internal `RosannaDefer::handleDeferred()` と `RosannaState::handleDeferred()` のため
 */
final class DeferredEndpoint
{
    public const DEFAULT_PREFIX = '/_defer';

    private const DEFAULT_CACHE_CONTROL = 'private, max-age=0';

    public function __construct(
        private readonly DeferredRegistry $registry,
        private readonly string $prefix,
        private readonly FragmentRenderer $fragments,
    ) {}

    /** @throws \InvalidArgumentException `/` で始まらないか、ルートそのものを指すとき */
    public static function normalizePrefix(string $prefix): string
    {
        $normalized = rtrim($prefix, '/');
        if ($normalized === '' || !str_starts_with($normalized, '/') || str_starts_with($normalized, '//')) {
            throw new \InvalidArgumentException("Defer prefix must be a path like '/_defer', got: '{$prefix}'");
        }

        return $normalized;
    }

    /**
     * DF-1〜DF-12。host が `{prefix}/{name}` へのリクエストをページの処理より前に渡す。
     */
    public function handle(DeferRequest $request): ?DeferResponse
    {
        if ($request->method !== 'GET' || ($request->path !== $this->prefix && !str_starts_with($request->path, $this->prefix . '/'))) {
            return null;
        }

        $name = rawurldecode(substr($request->path, strlen($this->prefix) + 1));
        if (!Defer::isValidName($name)) {
            return self::plainResponse(404, 'Not Found');
        }

        $registration = $this->registry->get($name);
        if ($registration === null) {
            return self::plainResponse(404, 'Deferred component not registered: ' . self::escape($name));
        }

        // DF-4 key がインスタンスの ID を決めるので、URL から他人の状態を選ばせない
        $props = [];
        foreach ($request->query as $param => $value) {
            $param = (string) $param;
            if ($param === 'key' || $param === 'fallback') {
                continue;
            }
            if (!is_scalar($value)) {
                return self::plainResponse(400, 'Deferred component param "' . self::escape($param) . '" must be a scalar.');
            }
            $props[$param] = $value;
        }

        try {
            $body = $this->render($request, $name, $registration['component'], $props);
        } catch (\Throwable $e) {
            // DF-7 例外のメッセージには FQCN やパスが入るので、公開のエンドポイントで見せない
            error_log(sprintf("[Rosanna] defer render failed for name '%s' (component %s): %s\n%s", $name, $registration['component'], $e->getMessage(), $e->getTraceAsString()));

            return self::plainResponse(500, 'Failed to render deferred component.');
        }

        return new DeferResponse(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => self::cacheControlFor($name, $registration['cacheControl'] ?? self::DEFAULT_CACHE_CONTROL),
        ], $body);
    }

    /**
     * @param array<string, mixed> $props
     * @throws \Throwable 描画の例外。呼び出し側が 500 にする
     */
    private function render(DeferRequest $request, string $name, string $component, array $props): string
    {
        $outerGet = $_GET;
        // クラスコンポーネントはパラメーターを $_GET から読む。host がパスを書き換えていてもこのリクエストの値にする
        $_GET = $request->query;
        $level = ob_get_level();
        ob_start();
        try {
            $html = $this->fragments->render($name, $component, $props, $request);

            return (string) ob_get_contents() . $html;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $_GET = $outerGet;
        }
    }

    /**
     * DF-8。セッションは描画の途中で始まることがあるので描画の後に確かめる。ポリシーだけでなく送信予定のヘッダーも見るのは、
     * `session.cache_limiter = public` が `private` のポリシーを持つ応答にも public を送るため。
     * リクエストに Cookie があるだけでは該当しない。
     */
    private static function cacheControlFor(string $name, string $policy): string
    {
        $pending = headers_list();
        if (!self::allowsSharedCache($policy) && !self::pendingAllowsSharedCache($pending)) {
            return $policy;
        }

        $reasons = [];
        if (session_status() === PHP_SESSION_ACTIVE || (session_id() ?: '') !== '') {
            $reasons[] = 'uses the session';
        }
        foreach ($pending as $header) {
            if (stripos($header, 'set-cookie:') === 0) {
                $reasons[] = 'sets a cookie';
                break;
            }
        }
        if ($reasons === []) {
            return $policy;
        }

        $reason = implode(' and ', $reasons);
        if (headers_sent()) {
            // DF-9
            error_log(sprintf("[Rosanna] defer endpoint '%s' %s, but headers were already sent, so it may stay cacheable", $name, $reason));
        }
        error_log(sprintf("[Rosanna] defer endpoint '%s' (cacheControl \"%s\") would be stored by shared caches but %s; using \"private, no-store\"", $name, $policy, $reason));

        return 'private, no-store';
    }

    /** `private` か `no-store` を含まないものを共有キャッシュを許すと扱う。フィールド名つきの `private="x"` は含まない */
    private static function allowsSharedCache(string $cacheControl): bool
    {
        $directives = array_map(trim(...), explode(',', strtolower($cacheControl)));

        return !in_array('private', $directives, true) && !in_array('no-store', $directives, true);
    }

    /** @param list<string> $headers */
    private static function pendingAllowsSharedCache(array $headers): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, 'cache-control:') === 0 && self::allowsSharedCache(substr($header, strlen('cache-control:')))) {
                return true;
            }
        }

        return false;
    }

    private static function plainResponse(int $status, string $body): DeferResponse
    {
        return new DeferResponse($status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], $body);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
