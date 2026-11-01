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

$app = new RosannaDefer()
    ->registerComponent('App\\Plain', deferred(static fn(array $props): Element => H::p(id: 'plain', children: 'plain ' . (string) ($props['topic'] ?? '')), new Defer('plain')))
    ->registerComponent('App\\Cached', deferred(static fn(): Element => H::p(id: 'cached', children: 'cached'), new Defer('cached', localCache: true)))
    ->registerComponent('App\\Count', deferred(static function () use ($counter): Element {
        $count = (int) @file_get_contents($counter) + 1;
        file_put_contents($counter, (string) $count);

        return H::p(id: 'count', children: "count={$count}");
    }, new Defer('count', reloadable: true)))
    ->registerComponent('App\\WithForm', deferred(static fn(): Element => H::form(id: 'host-form', children: [H::input(type: 'hidden', name: '_host_token', value: 'host-secret')]), new Defer('with-form', localCache: true)))
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
$body = $app->renderElement(H::div(children: [
    H::defer('plain', ['topic' => 'a'], H::i(children: 'fallback')),
    H::defer('cached', localCache: true),
    H::defer('count', reloadable: true),
    H::defer('with-form', localCache: true),
    H::defer('outside', fallback: H::i(id: 'unregistered', children: 'still')),
    H::button(...['data-rosanna-reload-defer' => 'count'], id: 'reload', type: 'button', children: 'reload'),
]));

echo '<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>', RosannaDefer::renderClientScript($src), '</head><body>', $body, '</body></html>';

return true;
