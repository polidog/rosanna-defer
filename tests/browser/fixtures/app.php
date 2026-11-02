<?php

declare(strict_types=1);

use Polidog\Rosanna\Defer\ClientScript;
use Polidog\Rosanna\Defer\Defer;

use function Polidog\Rosanna\Defer\deferred;

use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

require __DIR__ . '/../../../vendor/autoload.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/rosanna-defer.js') {
    header('Content-Type: text/javascript');
    readfile(ClientScript::path());

    return true;
}

$counter = sys_get_temp_dir() . '/rosanna-defer-browser-' . getmypid();
$next = static function (string $suffix) use ($counter): int {
    $count = (int) @file_get_contents($counter . $suffix) + 1;
    file_put_contents($counter . $suffix, (string) $count);

    return $count;
};

// host の前段のミドルウェアがリダイレクトする場合
if ($path === '/_defer/moved') {
    header('Location: /login', true, 302);

    return true;
}
if ($path === '/login') {
    echo '<form id="login"><input name="user"></form>';

    return true;
}

$selfLoop = null;
$selfLoop = deferred(static function () use (&$selfLoop): Element {
    return H::div(id: 'self-loop', children: [$selfLoop(['fallback' => H::i(id: 'self-loop-stop', children: 'stop')])]);
}, new Defer('self-loop'));

$app = new RosannaDefer()
    ->registerComponent('App\\Plain', deferred(static fn(array $props): Element => H::p(id: 'plain', children: 'plain ' . (string) ($props['topic'] ?? '')), new Defer('plain')))
    ->registerComponent('App\\Cached', deferred(static fn(): Element => H::p(id: 'cached', children: 'cached'), new Defer('cached', localCache: true)))
    ->registerComponent('App\\Count', deferred(static function () use ($counter): Element {
        $count = (int) @file_get_contents($counter) + 1;
        file_put_contents($counter, (string) $count);

        return H::p(id: 'count', children: "count={$count}");
    }, new Defer('count', reloadable: true)))
    ->registerComponent('App\\WithForm', deferred(static fn(): Element => H::form(id: 'host-form', children: [H::input(type: 'hidden', name: '_host_token', value: 'host-secret')]), new Defer('with-form', localCache: true)))
    ->registerComponent('App\\Child', deferred(static fn(): Element => H::span(id: 'child', children: 'child=' . $next('-child')), new Defer('child', cacheControl: 'private, no-store')))
    ->registerComponent('App\\Parent', deferred(static fn(): Element => H::section(id: 'parent', children: [
        H::span(id: 'parent-count', children: 'parent=' . $next('-parent')),
        H::defer('child'),
    ]), new Defer('parent', reloadable: true)))
    ->registerComponent('App\\TemplateToken', deferred(static fn(): Element => H::template(children: [H::input(type: 'hidden', name: '_host_token', value: 'template-secret')]), new Defer('template-token', localCache: true)))
    ->registerComponent('App\\SelfLoop', $selfLoop)
    ->registerDeferred('plain', 'App\\Plain')
    ->registerDeferred('cached', 'App\\Cached')
    ->registerDeferred('count', 'App\\Count')
    ->registerDeferred('with-form', 'App\\WithForm');

$response = $app->handleDeferred();
if ($response !== null) {
    $response->send();

    return true;
}

$src = is_string($_GET['src'] ?? null) ? $_GET['src'] : ClientScript::DEFAULT_SRC;
$body = $app->renderElement(match ($_GET['scene'] ?? null) {
    'moved' => H::defer('moved', fallback: H::i(id: 'moved-fallback', children: 'still')),
    'parent' => H::defer('parent', reloadable: true),
    'template' => H::defer('template-token', localCache: true),
    'self-loop' => H::defer('self-loop'),
    default => H::div(children: [
    H::defer('plain', ['topic' => 'a'], H::i(children: 'fallback')),
    H::defer('cached', localCache: true),
    H::defer('count', reloadable: true),
    H::defer('with-form', localCache: true),
    H::defer('outside', fallback: H::i(id: 'unregistered', children: 'still')),
    H::button(...['data-rosanna-reload-defer' => 'count'], id: 'reload', type: 'button', children: 'reload'),
    ]),
});

$script = RosannaDefer::renderClientScript($src);
if (($_GET['script'] ?? null) === 'twice') {
    $script .= RosannaDefer::renderClientScript($src);
}

echo '<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>', $script, '</head><body>', $body, '</body></html>';

return true;
