/**
 * Rosanna defer client. 依存なし、ビルドなし。状態層なしで動く。
 *
 * プレースホルダーの断片を取得して置き換え、メモリと localStorage にキャッシュする
 * (docs/spec/01-defer.md)。polidog/rosanna-state の rosanna.js があるときは、その後に読み込む。
 *
 * innerHTML に入れるのは、同一オリジンの defer の prefix 配下から返ったサーバー描画の HTML だけである。
 * template の中で解析するので <script> は動かない。
 */
(function () {
    'use strict';

    // =========================================================================
    // DOM に触らない部分。tests/js から確かめる
    // =========================================================================

    const DEFAULT_DEFER_PREFIX = '/_defer';
    const DEFER_LS_PREFIX = 'rosanna:defer:';
    const DEFER_LS_VERSION_KEY = DEFER_LS_PREFIX + '__version__';
    const DEFER_CACHE_VERSION = '1';
    const DEFER_CACHE_MAX = 64;

    // JS-17 共有端末で次の利用者に渡してはいけないものを含む断片の目印。
    // host のフォームは自前の CSRF トークンを持つことがあり、名前では見分けられないのでフォーム全体を含める
    const SESSION_BOUND_SELECTOR = [
        'form',
        'input[type="hidden" i]',
        '[data-rosanna-form]',
        'input[name="_rosanna_csrf"]',
        'input[name="_rosanna_snapshot"]',
        '[data-rosanna-snapshot]',
    ].join(',');

    function parseJsonObject(text) {
        if (typeof text !== 'string') return null;
        let value;
        try {
            value = JSON.parse(text);
        } catch {
            return null;
        }
        return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
    }

    function normalizeDeferPrefix(configured) {
        const prefix = typeof configured === 'string' && configured !== '' ? configured : DEFAULT_DEFER_PREFIX;
        return prefix.replace(/\/+$/, '');
    }

    /**
     * JS-16 同一オリジンで prefix 配下なら解決済みの URL を返す。
     * prefix が `/` だけのときはオリジン全体を許すことになるので拒否する。
     */
    function resolveDeferUrl(url, configuredPrefix, pageHref) {
        const prefix = normalizeDeferPrefix(configuredPrefix);
        if (prefix === '' || prefix[0] !== '/') return null;
        let parsed;
        try {
            parsed = new URL(url, pageHref);
        } catch {
            return null;
        }
        if (parsed.origin !== new URL(pageHref).origin) return null;
        return parsed.pathname.startsWith(prefix + '/') ? parsed.href : null;
    }

    function isHtmlContentType(contentType) {
        return /^text\/html\s*(;|$)/i.test(contentType || '');
    }

    /** defer の名前は URL の最後のセグメント。prefix を知らなくても引けるようにする */
    function deferUrlMatchesName(url, name) {
        const path = String(url).split(/[?#]/, 1)[0];
        const last = path.split('/').filter(Boolean).pop() ?? '';
        try {
            return decodeURIComponent(last) === name;
        } catch {
            return last === name;
        }
    }

    function parseReloadTargets(value) {
        return (value || '').split(/[\s,]+/).filter(Boolean);
    }

    function parseCacheTtlMs(value) {
        const seconds = Number(value);
        return Number.isInteger(seconds) && seconds > 0 ? seconds * 1000 : 0;
    }

    function storageKeys(store, prefix) {
        const keys = [];
        for (let i = 0; i < store.length; i++) {
            const key = store.key(i);
            if (key !== null && key.startsWith(prefix)) keys.push(key);
        }
        return keys;
    }

    /** JS-18 ページ内のキャッシュ。古い順に捨てる */
    function createLruCache(max) {
        const entries = new Map();
        return {
            get(key) {
                if (!entries.has(key)) return undefined;
                const value = entries.get(key);
                entries.delete(key);
                entries.set(key, value);
                return value;
            },
            set(key, value) {
                entries.delete(key);
                if (entries.size >= max) entries.delete(entries.keys().next().value);
                entries.set(key, value);
            },
            delete(key) {
                entries.delete(key);
            },
            clear() {
                entries.clear();
            },
            keys() {
                return [...entries.keys()];
            },
        };
    }

    /**
     * JS-18 localStorage の段。上限を超えたら保存の古い順に捨て、バージョンが変われば全部消す。
     * 古い版のタブが後から書くことがあるので、バージョンは項目ごとにも持つ。
     */
    function createDeferStore(getStorage, now = Date.now, max = DEFER_CACHE_MAX, version = DEFER_CACHE_VERSION) {
        const storage = () => {
            try {
                // 書き込みで確かめない。満杯のときも読めて消せる。書き込みの失敗は write が扱う
                return getStorage() || null;
            } catch {
                return null;
            }
        };
        const entryKeys = (store) => storageKeys(store, DEFER_LS_PREFIX).filter((key) => key !== DEFER_LS_VERSION_KEY);
        const readRecord = (store, key) => {
            const record = parseJsonObject(store.getItem(key));
            return record !== null && record.v === version && typeof record.html === 'string' ? record : null;
        };
        // incoming は書こうとしている項目。上書きでは件数が増えないので数えない。残った項目を古い順に返す
        const makeRoom = (store, incoming) => {
            const records = [];
            for (const key of entryKeys(store)) {
                const record = readRecord(store, key);
                if (record === null) {
                    store.removeItem(key);
                } else if (key !== incoming) {
                    records.push({ key, storedAt: typeof record.storedAt === 'number' ? record.storedAt : 0 });
                }
            }
            records.sort((a, b) => a.storedAt - b.storedAt);
            records.splice(0, Math.max(0, records.length - max + 1)).forEach(({ key }) => store.removeItem(key));
            return records;
        };

        const api = {
            reconcile() {
                const store = storage();
                if (!store) return;
                try {
                    if (store.getItem(DEFER_LS_VERSION_KEY) !== version) api.clear();
                } catch {
                    // localStorage なしで動く
                }
            },
            read(url, maxAgeMs = 0) {
                const store = storage();
                if (!store) return null;
                const key = DEFER_LS_PREFIX + url;
                try {
                    if (store.getItem(key) === null) return null;
                    const record = readRecord(store, key);
                    const expired = record !== null && maxAgeMs > 0
                        && now() - (typeof record.storedAt === 'number' ? record.storedAt : 0) > maxAgeMs;
                    if (record === null || expired) {
                        store.removeItem(key);
                        return null;
                    }
                    return record.html;
                } catch {
                    return null;
                }
            },
            write(url, html) {
                const store = storage();
                if (!store) return;
                const key = DEFER_LS_PREFIX + url;
                const record = JSON.stringify({ v: version, html, storedAt: now() });
                try {
                    makeRoom(store, key);
                    store.setItem(key, record);
                } catch {
                    // 容量が足りないときは最も古いものを捨てて 1 回だけやり直す
                    try {
                        const oldest = makeRoom(store, key)[0];
                        if (oldest) store.removeItem(oldest.key);
                        store.setItem(key, record);
                    } catch {
                        // メモリの段だけで続ける
                    }
                }
            },
            remove(url) {
                const store = storage();
                if (!store) return;
                try {
                    store.removeItem(DEFER_LS_PREFIX + url);
                } catch {
                    // 消せなくてもページは止めない
                }
            },
            clear() {
                const store = storage();
                if (!store) return;
                try {
                    storageKeys(store, DEFER_LS_PREFIX).forEach((key) => store.removeItem(key));
                    store.setItem(DEFER_LS_VERSION_KEY, version);
                } catch {
                    // 消せなくてもページは止めない
                }
            },
            urls() {
                const store = storage();
                if (!store) return [];
                try {
                    return entryKeys(store).map((key) => key.slice(DEFER_LS_PREFIX.length));
                } catch {
                    return [];
                }
            },
        };
        return api;
    }

    // ブラウザでは `module` を見ない。id="module" の要素が window に見えるため
    if (typeof document === 'undefined') {
        if (typeof module === 'object' && module !== null) {
            module.exports = {
                DEFER_CACHE_VERSION,
                SESSION_BOUND_SELECTOR,
                createDeferStore,
                createLruCache,
                deferUrlMatchesName,
                isHtmlContentType,
                parseCacheTtlMs,
                parseReloadTargets,
                resolveDeferUrl,
            };
        }
        return;
    }

    // =========================================================================
    // 公開 API の入れ物
    // =========================================================================

    // <form id="Rosanna"> のように要素が window に見えていたら使わない
    const existing = window.Rosanna;
    const Rosanna = Object.prototype.toString.call(existing) === '[object Object]' ? existing : {};
    window.Rosanna = Rosanna;

    function startElement(event) {
        if (event.target instanceof Element) return event.target;
        return event.target && event.target.parentElement ? event.target.parentElement : null;
    }

    function parseHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content;
    }

    // =========================================================================
    // 取得とキャッシュ (JS-16〜JS-20)
    // =========================================================================

    const deferMemory = createLruCache(DEFER_CACHE_MAX);
    const deferStore = createDeferStore(() => window.localStorage);
    const deferStates = new WeakMap();
    // JS-23 解決済みの defer の URL。解決すると文書からプレースホルダが消えるものがある
    const resolvedDefers = new Set();
    // セッションができた回数。これより前に始めた defer の取得はトークンを持たない
    let refreshEpoch = 0;

    function deferState(el) {
        if (!deferStates.has(el)) deferStates.set(el, { generation: 0, running: false, controller: null, epoch: 0 });
        return deferStates.get(el);
    }

    // querySelector は template の中に入らない
    function fragmentHoldsSessionData(content) {
        if (content.querySelector(SESSION_BOUND_SELECTOR) !== null) return true;
        return [...content.querySelectorAll('template')].some((template) => fragmentHoldsSessionData(template.content));
    }

    // data-rosanna-defer-name があるものはラッパーを残し、後から取得し直せるようにする。
    // nested は断片の中の defer に引き継ぐ loadDefer の指定
    function placeFragment(el, fragment, nested) {
        resolvedDefers.add(el.getAttribute('data-rosanna-defer-url'));
        if (el.hasAttribute('data-rosanna-defer-name')) {
            el.replaceChildren(fragment);
            el.removeAttribute('aria-busy');
            el.setAttribute('data-rosanna-defer-loaded', '');
            processDeferred(el, nested);
        } else {
            processDeferred(fragment, nested);
            el.replaceWith(fragment);
        }
        // rosanna.js があれば、断片の中のインスタンスを扱う
        if (typeof Rosanna.__onDeferPlaced === 'function') Rosanna.__onDeferPlaced();
    }

    function readCachedFragment(el, url) {
        const remembered = deferMemory.get(url);
        if (remembered) return remembered.cloneNode(true);
        if (!el.hasAttribute('data-rosanna-defer-cache')) return null;

        const html = deferStore.read(url, parseCacheTtlMs(el.getAttribute('data-rosanna-defer-cache-ttl')));
        if (html === null) return null;
        const content = parseHtml(html);
        // JS-17 版の古いタブや手で書かれた値でも、トークンを持つ断片は使わない
        if (fragmentHoldsSessionData(content)) {
            deferStore.remove(url);
            return null;
        }
        deferMemory.set(url, content.cloneNode(true));
        return content;
    }

    function rememberFragment(el, url, html, content) {
        deferMemory.set(url, content.cloneNode(true));
        if (!el.hasAttribute('data-rosanna-defer-cache')) return;
        if (fragmentHoldsSessionData(content)) {
            console.warn('[Rosanna] not persisting defer fragment: it contains a form or session data', url);
            return;
        }
        deferStore.write(url, html);
    }

    function isUsableDeferResponse(response, url) {
        if (!response.ok) {
            // フォールバックが出たままになるので、設定の誤りが分かるように出す
            console.warn('[Rosanna] defer fetch returned', response.status, response.statusText, url);
            return false;
        }
        // JS-16 リダイレクトの先も同じ条件で確かめる。ログイン画面などを断片として挿入しない。
        // Service Worker が作った応答の url は空で、リダイレクトではない
        if (response.url !== '' && resolveDeferUrl(response.url, Rosanna.deferPrefix, location.href) === null) {
            console.warn('[Rosanna] refusing defer response: redirected outside the same-origin defer prefix', response.url);
            return false;
        }
        if (!isHtmlContentType(response.headers.get('content-type'))) {
            console.warn('[Rosanna] refusing defer response: Content-Type is not text/html', url);
            return false;
        }
        return true;
    }

    // JS-19 force は前の取得を中断して置き換える。それ以外は実行中の取得に譲る。
    // JS-18 fresh はキャッシュを使わず、断片の中の defer にも引き継ぐ。親を取得し直して子だけ古くならないようにする。
    // DF-6 ancestors はこの要素を含む断片の URL。自分を含む断片を取得し続けない
    async function loadDefer(el, { force = false, fresh = false, ancestors = new Set() } = {}) {
        const url = el.getAttribute('data-rosanna-defer-url');
        if (!url) return;

        // キャッシュより先に確かめる。キャッシュの鍵を汚されても出さない
        const resolved = resolveDeferUrl(url, Rosanna.deferPrefix, location.href);
        if (resolved === null) {
            console.warn('[Rosanna] refusing defer fetch: URL is not under the same-origin defer prefix', url);
            return;
        }
        if (el.hasAttribute('data-rosanna-defer-loaded')) return;
        if (ancestors.has(url)) {
            console.warn('[Rosanna] refusing defer fetch: the fragment contains itself', url);
            return;
        }

        const state = deferState(el);
        if (state.running && !force) return;

        const generation = ++state.generation;
        const isLatest = () => state.generation === generation;
        if (state.controller) state.controller.abort();
        state.controller = null;
        state.running = true;
        state.epoch = refreshEpoch;
        const startedAt = refreshEpoch;
        const nested = { fresh, ancestors: new Set(ancestors).add(url) };

        try {
            if (fresh) clearDeferCache(url);
            const cached = readCachedFragment(el, url);
            if (cached) {
                placeFragment(el, cached, nested);
                return;
            }

            el.setAttribute('aria-busy', 'true');
            const controller = new AbortController();
            state.controller = controller;
            const response = await fetch(resolved, {
                method: 'GET',
                headers: { 'X-Rosanna-Defer': '1' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!isUsableDeferResponse(response, url)) return;

            const html = await response.text();
            const content = parseHtml(html);
            // 新しい取得に負けても、この URL の断片としては正しいのでキャッシュには入れる。
            // ただしセッションができる前に取得したフォームは古い (JS-23)
            if (startedAt === refreshEpoch || !fragmentHoldsSessionData(content)) rememberFragment(el, url, html, content);
            if (isLatest()) placeFragment(el, content, nested);
        } catch (error) {
            if (!error || error.name !== 'AbortError') {
                console.warn('[Rosanna] defer fetch failed:', error, url);
            }
        } finally {
            if (isLatest()) {
                state.running = false;
                state.controller = null;
                el.removeAttribute('aria-busy');
            }
        }
    }

    function processDeferred(root, options) {
        for (const el of (root || document).querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded])')) {
            loadDefer(el, options);
        }
    }

    function clearDeferCache(target) {
        if (target === undefined || target === null || target === '') {
            deferMemory.clear();
            deferStore.clear();
            return;
        }
        const matches = (url) => url === target || deferUrlMatchesName(url, target);
        deferMemory.keys().filter(matches).forEach((url) => deferMemory.delete(url));
        deferStore.urls().filter(matches).forEach((url) => deferStore.remove(url));
    }

    /** @returns {number} 取得し直した領域の数。名前の書き間違いが分かるように返す */
    function reloadDefer(target) {
        const everything = target === undefined || target === null || target === '';
        let count = 0;
        for (const el of document.querySelectorAll('[data-rosanna-defer-name][data-rosanna-defer-url]')) {
            const url = el.getAttribute('data-rosanna-defer-url');
            if (!everything && el.getAttribute('data-rosanna-defer-name') !== target
                && url !== target && !deferUrlMatchesName(url, target)) {
                continue;
            }
            el.removeAttribute('data-rosanna-defer-loaded');
            count++;
            loadDefer(el, { force: true, fresh: true });
        }
        return count;
    }

    function dispatchReloadDefer(value) {
        const targets = parseReloadTargets(value);
        if (targets.length === 0) {
            reloadDefer();
        } else {
            targets.forEach((target) => reloadDefer(target));
        }
    }

    // JS-20 フォームの中の操作は、部分更新の成功の後に送信の経路で動く
    document.addEventListener('click', (event) => {
        const start = startElement(event);
        const trigger = start ? start.closest('[data-rosanna-reload-defer]') : null;
        if (!trigger || trigger.tagName === 'FORM') return;
        if (trigger.closest('[data-rosanna-form]')) return;
        if (trigger.form && trigger.form.matches('[data-rosanna-form]')) return;
        if (trigger.hasAttribute('data-rosanna-on-click')) return;
        if (trigger.tagName === 'A') event.preventDefault();
        dispatchReloadDefer(trigger.getAttribute('data-rosanna-reload-defer'));
    });

    Rosanna.clearDeferCache = clearDeferCache;
    Rosanna.reloadDefer = reloadDefer;

    // =========================================================================
    // セッションの変化と部分更新 (JS-23)。rosanna.js から呼ばれる
    // =========================================================================

    // JS-23 localStorage にはフォームがないので (JS-17)、メモリの段だけ見る
    function forgetSessionFragments() {
        for (const url of deferMemory.keys()) {
            if (fragmentHoldsSessionData(deferMemory.get(url))) deferMemory.delete(url);
        }
    }

    function restartOutdatedDefers() {
        for (const el of document.querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded])')) {
            const state = deferStates.get(el);
            if (state && state.running && state.epoch !== refreshEpoch) loadDefer(el, { force: true });
        }
    }

    async function fetchDeferForRefresh(placeholder, url) {
        const resolved = resolveDeferUrl(url, Rosanna.deferPrefix, location.href);
        if (resolved === null) return null;
        try {
            const response = await fetch(resolved, {
                method: 'GET',
                headers: { 'X-Rosanna-Defer': '1' },
                credentials: 'same-origin',
            });
            if (!isUsableDeferResponse(response, url)) return null;
            const html = await response.text();
            const content = parseHtml(html);
            rememberFragment(placeholder, url, html, content);
            return content;
        } catch (error) {
            console.warn('[Rosanna] defer fetch failed:', error, url);
            return null;
        }
    }

    /**
     * 解決済みの defer を応答の中で解決しておく。ライブの DOM と同じ形になるので、中のインスタンスも ID で
     * 差分を当てられ、入力と Snapshot の状態が残る。取得に失敗すればプレースホルダのままで、既存の取得に任せる。
     */
    async function fillResolvedDefers(content) {
        const seen = new Set();
        for (;;) {
            const pending = [...content.querySelectorAll('[data-rosanna-defer-url]')].filter(
                (el) => !seen.has(el) && resolvedDefers.has(el.getAttribute('data-rosanna-defer-url')),
            );
            if (pending.length === 0) return;
            await Promise.all(pending.map(async (el) => {
                seen.add(el);
                const url = el.getAttribute('data-rosanna-defer-url');
                const remembered = deferMemory.get(url);
                const fragment = remembered ? remembered.cloneNode(true) : await fetchDeferForRefresh(el, url);
                if (!fragment) return;
                if (el.hasAttribute('data-rosanna-defer-name')) {
                    el.replaceChildren(fragment);
                    el.setAttribute('data-rosanna-defer-loaded', '');
                } else {
                    el.replaceWith(fragment);
                }
            }));
        }
    }

    /** @internal rosanna.js のため */
    Rosanna.__defer = {
        process: (root) => processDeferred(root),
        dispatchReload: dispatchReloadDefer,
        fillResolved: fillResolvedDefers,
        sessionChanged() {
            refreshEpoch++;
            forgetSessionFragments();
            restartOutdatedDefers();
        },
    };

    // =========================================================================
    // 起動
    // =========================================================================

    deferStore.reconcile();

    // bfcache の復帰ではスクリプトが動き直さない
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) processDeferred();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => processDeferred());
    } else {
        processDeferred();
    }
})();
