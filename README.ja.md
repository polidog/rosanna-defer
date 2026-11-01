# Rosanna Defer

[English](README.md)

`polidog/rosanna-defer` は、Rosanna のページの一部を読み込みの後に別のリクエストで取得します。ページ本体を CDN に載せたまま、訪問者ごとの部分や重い部分を分けられます。

セッションも状態も使いません。`useState` やサーバーを通るボタンが要るなら、これを含む [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state) を使います。

## インストール

```bash
composer require polidog/rosanna-defer
vendor/bin/rosanna-defer publish   # public/rosanna-defer.js を配置する
```

## 使い方

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

// ページの処理より前に呼ぶ。`/_defer/{名前}` でなければ null
$response = $app->handleDeferred();
if ($response !== null) {
    $response->send();

    return;
}

// ページでは <UserHeader /> がプレースホルダーになる
echo RosannaDefer::renderClientScript();
```

自分の `Renderer` を持つ host は `new Renderer(deferred: $app->placeholderRenderer())` で描画します。自分の Request を持つ host は `new DeferRequest($method, $path, $query, $queryString)` を渡します。

## キャッシュ

| 指定 | 意味 |
|---|---|
| `cacheControl` | エンドポイントの `Cache-Control`。既定は `private, max-age=0` |
| `localCache` | ブラウザの `localStorage` に保存する |
| `localCacheTtl` | `localStorage` の保存の秒数 |
| `reloadable` | 解決後もラッパーを残し、`Rosanna.reloadDefer(name)` で取得し直せる |

- フォームか `type=hidden` の入力を含む断片は、`localCache` を指定しても `localStorage` に書きません。CSRF トークンが共有端末に残らないようにするためです。
- セッションを使うか Cookie を設定する応答は、共有キャッシュを許す `cacheControl` でも `private, no-store` に置き換えます。
- `/_defer/{名前}` は host の保護の対象外の公開の GET で、クエリは信頼しない入力です。認可は対象の中で行います。

`vendor/bin/rosanna compile` の成果物は defer を調べていないので受け付けません。`vendor/bin/rosanna-defer compile` を使います。

## 仕様

[docs/spec/01-defer.md](docs/spec/01-defer.md)
