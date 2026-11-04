<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use function Polidog\Rosanna\Defer\Internal\Functions\allowsSharedCache;
use function Polidog\Rosanna\Defer\Internal\Functions\deferredNameFromPath;
use function Polidog\Rosanna\Defer\Internal\Functions\deferredProps;
use function Polidog\Rosanna\Defer\Internal\Functions\escape;
use function Polidog\Rosanna\Defer\Internal\Functions\normalizePrefix;
use function Polidog\Rosanna\Defer\Internal\Functions\pendingAllowsSharedCache;

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
        return normalizePrefix($prefix);
    }

    /**
     * DF-1〜DF-12。host が `{prefix}/{name}` へのリクエストをページの処理より前に渡す。
     */
    public function handle(DeferRequest $request): ?DeferResponse
    {
        $name = $request->method === 'GET' ? deferredNameFromPath($this->prefix, $request->path) : null;
        if ($name === null) {
            return null;
        }
        if (!Defer::isValidName($name)) {
            return self::plainResponse(404, 'Not Found');
        }

        $registration = $this->registry->get($name);
        if ($registration === null) {
            return self::plainResponse(404, 'Deferred component not registered: ' . escape($name));
        }

        $props = deferredProps($request->query);
        foreach ($props as $param => $value) {
            if (!is_scalar($value)) {
                return self::plainResponse(400, 'Deferred component param "' . escape($param) . '" must be a scalar.');
            }
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
        if (!allowsSharedCache($policy) && !pendingAllowsSharedCache($pending)) {
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

    private static function plainResponse(int $status, string $body): DeferResponse
    {
        return new DeferResponse($status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], $body);
    }
}
