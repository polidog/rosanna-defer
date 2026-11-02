# Rosanna Defer

[日本語](README.ja.md)

`polidog/rosanna-defer` fetches parts of a Rosanna page in a separate request after load, so the page itself can sit on a CDN while per-visitor or slow parts stay separate.

It uses no session and no state. If you need `useState` or buttons that go through the server, use [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state), which includes this package.

## Install

```bash
composer require polidog/rosanna-defer
vendor/bin/rosanna-defer publish   # copies rosanna-defer.js to public/
```

## Usage

```php
<?php
// components/UserHeader.psx
namespace App;

use Polidog\Rosanna\Defer\Defer;
use function Polidog\Rosanna\Defer\deferred;

return deferred(
    fn (array $props, UserRepository $users) => <p>{$users->current()->name}</p>,
    new Defer(name: 'user-header', cacheControl: 'private, no-store'),
);
```

```bash
vendor/bin/rosanna-defer compile components/
```

```php
<?php
use Polidog\Rosanna\Defer\RosannaDefer;

$app = new RosannaDefer();
$app->loadComponentManifest(__DIR__ . '/var/cache/psx/manifest.php');
// Supplies arguments after $props, such as `$users`. Implements Polidog\Rosanna\Runtime\PsxArgumentResolver; without it the render returns 500
$app->setArgumentResolver($yourResolver);

// Call before page handling. Returns null unless the path is /_defer/{name}
$response = $app->handleDeferred();
if ($response !== null) {
    $response->send();

    return;
}

// On the page, <UserHeader /> renders as a placeholder
echo RosannaDefer::renderClientScript();
```

A host with its own `Renderer` uses `new Renderer(deferred: $app->placeholderRenderer())`. A host with its own request object passes `new DeferRequest($method, $path, $query, $queryString)`.

## Caching

| Option | Meaning |
|---|---|
| `cacheControl` | The endpoint's `Cache-Control`. Defaults to `private, max-age=0` |
| `localCache` | Store in the browser's `localStorage` |
| `localCacheTtl` | Seconds to keep it in `localStorage` |
| `reloadable` | Keep the wrapper after loading so `Rosanna.reloadDefer(name)` can fetch again |

- A fragment with a form or a `type=hidden` input is never written to `localStorage`, even with `localCache`, so CSRF tokens do not stay on a shared device.
- A response that uses the session or sets a cookie is sent as `private, no-store`, even if `cacheControl` allows shared caches.
- `/_defer/{name}` is a public GET outside the host's protection, and its query is untrusted input. Authorize inside the target.

Artifacts from `vendor/bin/rosanna compile` are refused because it does not look for defer. Use `vendor/bin/rosanna-defer compile`.

## Specification

[docs/spec/01-defer.md](docs/spec/01-defer.md)
