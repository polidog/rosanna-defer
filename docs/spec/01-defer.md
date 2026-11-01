# defer

Status: Draft (2026-11-02、`polidog/rosanna-state` の 02 の 8 節と 9 節の JS-16〜20 から分離)

要件 ID は分離の前の番号のままである。テストやコメントからの参照を切らないため。

## パッケージの境界

依存の向きは `polidog/rosanna-state` → `polidog/rosanna-defer` → `polidog/rosanna` の一方向である。このパッケージは状態層の名前を参照せず、セッションを始めない。

| パッケージ | 持つもの |
|---|---|
| `polidog/rosanna` | `H::defer()`、`<defer>` の構文、差し替え口 `DeferredRenderer`。実装がなければ描画は案内の例外 (RT-3.16) |
| `polidog/rosanna-defer` | `Defer`、`DeferredRegistry`、`DeferredEndpoint`、`PlaceholderRenderer`、`DeferredManifestCompiler`、`deferred()`、`RosannaDefer`、`rosanna-defer.js` |
| `polidog/rosanna-state` | `fc(defer:)`、`#[Defer]` のクラス、断片の中のフォーム (`_rosanna_defer`、`_rosanna_defer_query`)、署名の `defer:{name}` (DF-10、SG-6)、replay の探索 (HD-9)、部分更新とセッションの更新との接続 (JS-23) |

## 目的

一部のコンポーネントをページの読み込み後に別のリクエストで取得する。ページを CDN に載せつつ、訪問者ごとの部分を分ける。

## 公開 API

```php
final class RosannaDefer
{
    public function registerComponent(string $fqcn, callable $component): self;
    public function loadComponentManifest(string $path): self;
    public function setArgumentResolver(PsxArgumentResolver $resolver): self;
    public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self;
    public function setDeferPrefix(string $prefix): self;
    public function scope(): RenderScope;
    public function renderElement(Element $element): string;
    public function placeholderRenderer(): PlaceholderRenderer;
    public function handleDeferred(?DeferRequest $request = null): ?DeferResponse;
    public static function renderClientScript(string $src = '/rosanna-defer.js', ?string $deferPrefix = null, ?string $nonce = null): string;
}

function deferred(callable $component, Defer $defer): Deferred;
```

`RosannaState` は同じ処理を `handleDeferred(Request): ?Response` などで公開する。

## 差し替え口

- `FragmentRenderer` はエンドポイントの対象を HTML にする。既定の `PsxFragmentRenderer` はセッションも描画パスも使わない。状態層は `State\Protocol\DeferredFragments` で実装する。
- `EndpointTargetScope` は `RenderScope::$state` に置き、対象自身かどうかを答える (DF-6)。`Deferred` と `FunctionComponent` は `DeclaresDefer` を実装する。
- コンパイラの `ComponentParameters` は `fc` と `deferred` の第 1 引数からパラメータを読む。

## 振る舞い

- **DF-1** GET で、パスが prefix か prefix 配下のときだけ処理する。それ以外は `null` (`usePHP/src/UsePHP.php:1828-1835`)。
- **DF-2** 名前は `[A-Za-z0-9_-]+`。不正なら 404。未登録も 404。404 は `no-store` (`usePHP/src/UsePHP.php:1846-1856`)。
- **DF-3** 同じ名前の登録は例外。同じ内容の自動登録は何もしない (`usePHP/src/UsePHP.php:355-388`)。
- **DF-4** クエリを props にする。`key`、`fallback` は取り除く。`key` がインスタンスの ID を決めるので、URL から他人の状態を選べないようにする。スカラーでない値は 400 (`usePHP/src/UsePHP.php:1865-1884`)。
- **DF-5** 既定の `Cache-Control` は `private, max-age=0`。登録の値があれば使う (`usePHP/src/UsePHP.php:1886`)。
- **DF-6** 新しい描画のパスで描画し、対象自身はプレースホルダを飛ばす。ネストした defer はプレースホルダのまま (`usePHP/src/UsePHP.php:1889-1898`)。
- **DF-7** 描画の例外はログに詳細を出し、500 と `Failed to render deferred component.` と `no-store` を返す。例外のメッセージをクライアントに見せない (`usePHP/src/UsePHP.php:1902-1917`)。
- **DF-8** **描画の後に共有キャッシュを確かめる。コミット `4621f0f`。** ポリシーが共有キャッシュを許すとき、または送信予定のヘッダーが許すとき、次のどちらかなら `Cache-Control: private, no-store` に置き換えてログに出す (`usePHP/src/UsePHP.php:1952-1988`)。
  - セッションがアクティブ、または読んで閉じられた (`session_id()` が空でない)。
  - `Set-Cookie` を送る。

  `private` か `no-store` を含まないものを「共有キャッシュを許す」と扱う (`:1994-1999`)。リクエストに Cookie があるだけでは該当しない。`session.cache_limiter = public` でも効くように、送信予定のヘッダーを見る。
- **DF-9** セッションを開始するより前にヘッダーが送られていて置き換えられなければ、ログに出す (`usePHP/src/UsePHP.php:1971-1979`)。RQ-2 により通常は起きない。
- **DF-10** 描画のあいだ、署名の scope は `defer:{name}` (SG-6)。
- **DF-11** defer の URL は常に prefix から始める。Relayer のロケールの接頭辞は付かない (`relayer/src/Router/AppRouter.php:470-476`)。
- **DF-12** `/_defer/{name}` は認証なしの公開の GET で、host の保護の対象外である。props は信頼しない入力であることをドキュメントに書く (README 1205 行)。
- **DF-13** PSX の defer は、`RosannaState::loadComponentManifest()` が deferred manifest の項目を `registerDeferred()` に渡して登録する。本体の `Rosanna::loadComponentManifest()` は deferred manifest を読まないので、`RosannaState` が読む (本体の RT-11.5、[本体 03](https://github.com/polidog/rosanna/blob/main/docs/spec/03-compiler.md) の 8 節)。deferred manifest がない、または build ID (本体の CP-144) が `manifest.php` と合わないときは、`vendor/bin/rosanna-state compile` を案内する `RuntimeException` にする。`rosanna compile` の成果物を黙って defer なしとして受け入れない。

## クライアント

`rosanna.js` と一緒に使うときは `rosanna.js` の後に読み込む (`RosannaState::renderClientScript()` はこの順に出す)。`rosanna.js` は `window.Rosanna.__defer` を読み込まれた後に使い、なければ何もしない。断片を置いたときは `window.Rosanna.__onDeferPlaced` があれば呼ぶ。どちらも内部の接点で、公開 API ではない。

- **JS-16** プレースホルダの URL は、ページと**同一オリジン**で、defer の prefix 配下のときだけ取得する。応答の `Content-Type` が `text/html` のときだけ挿入する。それ以外は console に警告して何もしない。サニタイザーを抜けた `data-*` で任意の内容を流し込まれるのを防ぐ。**コミット `739714f`** (`usePHP/public/usephp.js:96-136`, `:1094-1101`)。
- **JS-17** フォーム、`type=hidden` の入力、`_rosanna_csrf`、`_rosanna_snapshot`、`data-rosanna-snapshot` を含む断片は `localStorage` に書かない。`localCache` の指定があっても書かない。共有端末で次の利用者にトークンが渡るのを防ぐ (`usePHP/public/usephp.js:141-153`, `:1121-1130`)。
- **JS-18** キャッシュはページ内のメモリと、指定があるときの `localStorage` の 2 段。`localStorage` の上限は 64 件で古い順に捨てる。バージョンが変われば全部消す。`localStorage` がなければメモリだけで動く (`usePHP/public/usephp.js:79-91`, `:187-420`)。
- **JS-19** 同時の取得は 1 つ。`reloadDefer` は新しい取得で前を中断し、古い応答は捨てる (`usePHP/public/usephp.js:160-175`, `:986-1135`)。
- **JS-20** `data-rosanna-reload-defer` はフォームなら部分更新の成功の後、それ以外は click で動く。一覧の取得が他の取得より先に走るよう、defer の再走査より前に動かす (`usePHP/public/usephp.js:512-562`, `:739-755`)。

公開 API は `window.Rosanna.reloadDefer(name?)` と `window.Rosanna.clearDeferCache(name?)`。

## エラー

404、400、500 の本文は上記のとおり。本文に入れる名前はエスケープする。

## テスト

- DF-1: POST、別のパスで `null`。
- DF-2、DF-4: 不正な名前、未登録、配列のパラメーター、`key` の除去。
- DF-7: 例外のメッセージが本文に出ない。
- DF-8: `DeferSharedCacheTest` の項目。セッションを使う、Cookie を設定する、`session.cache_limiter = public`、セッションを使わないならポリシーを保つ、`private` は警告しない。
- DF-8: 秘密鍵があるセッションなしのフォームは全員に同じで、キャッシュできる (`testASignedFormWithoutASessionIsTheSameForEveryVisitor`)。

