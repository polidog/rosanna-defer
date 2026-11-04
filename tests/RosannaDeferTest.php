<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Defer\ClientScript;
use Polidog\Rosanna\Defer\Defer;

use function Polidog\Rosanna\Defer\deferred;

use Polidog\Rosanna\Defer\Deferred;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\Renderer;

/** 状態層なしで defer を使う */
final class RosannaDeferTest extends TestCase
{
    private static function echoProps(string $name = 'echo'): Deferred
    {
        return deferred(static fn(array $props): Element => H::p(children: (string) json_encode($props)), new Defer($name));
    }

    private static function app(): RosannaDefer
    {
        return new RosannaDefer()
            ->registerComponent('App\\Echo', self::echoProps())
            ->registerDeferred('echo', 'App\\Echo');
    }

    private static function get(string $path, array $query = []): DeferRequest
    {
        return new DeferRequest('GET', $path, $query, http_build_query($query));
    }

    public function testPlaceholderIsRenderedOnThePage(): void
    {
        $app = new RosannaDefer();
        $component = deferred(static fn(): Element => H::p(children: 'secret'), new Defer('panel', localCache: true, reloadable: true, localCacheTtl: 60));

        $html = $app->renderElement($component(['topic' => 'a&b', 'fallback' => H::i(children: 'wait')]));

        self::assertSame('<div data-rosanna-defer-url="/_defer/panel?topic=a%26b" data-rosanna-defer-cache data-rosanna-defer-cache-ttl="60" data-rosanna-defer-name="panel"><i>wait</i></div>', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    public function testHDeferRendersWithoutTheStateLayer(): void
    {
        self::assertSame('<div data-rosanna-defer-url="/_defer/a"></div>', new RosannaDefer()->renderElement(H::defer('a')));
    }

    public function testPrefixIsUsedForPlaceholdersAndTheEndpoint(): void
    {
        $app = self::app()->setDeferPrefix('/api/_d/');

        self::assertStringContainsString('"/api/_d/a"', $app->renderElement(H::defer('a')));
        self::assertNull($app->handleDeferred(self::get('/_defer/echo')));
        self::assertSame(200, $app->handleDeferred(self::get('/api/_d/echo'))?->status);
    }

    /** 値の列挙は normalizePrefix() のテストで見る */
    public function testBadPrefixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RosannaDefer()->setDeferPrefix('//evil.example');
    }

    public function testOtherRequestsAreLeftToTheHost(): void
    {
        $app = self::app();

        self::assertNull($app->handleDeferred(self::get('/page')));
        self::assertNull($app->handleDeferred(new DeferRequest('POST', '/_defer/echo')));
        self::assertNull($app->handleDeferred(self::get('/_deferred/echo')));
    }

    public function testUnknownAndInvalidNamesAreNotFound(): void
    {
        $app = self::app();

        self::assertSame(404, $app->handleDeferred(self::get('/_defer/none'))?->status);
        self::assertSame(404, $app->handleDeferred(self::get('/_defer/a%2Fb'))?->status);
    }

    public function testEndpointRendersTheTargetWithQueryPropsAndNoKey(): void
    {
        $response = self::app()->handleDeferred(self::get('/_defer/echo', ['a' => '1', 'key' => 'other', 'fallback' => 'x']));

        self::assertSame(200, $response?->status);
        self::assertSame('<p>{&quot;a&quot;:&quot;1&quot;}</p>', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('private, max-age=0', $response->headers['Cache-Control']);
    }

    public function testArrayParameterIsABadRequest(): void
    {
        self::assertSame(400, self::app()->handleDeferred(self::get('/_defer/echo', ['a' => ['b']]))?->status);
    }

    public function testRegisteredCacheControlIsSent(): void
    {
        $app = new RosannaDefer()
            ->registerComponent('App\\Echo', self::echoProps())
            ->registerDeferred('echo', 'App\\Echo', 'public, s-maxage=60');

        self::assertSame('public, s-maxage=60', $app->handleDeferred(self::get('/_defer/echo'))?->headers['Cache-Control']);
    }

    public function testNestedDeferStaysAPlaceholder(): void
    {
        $inner = deferred(static fn(): Element => H::p(children: 'inner-secret'), new Defer('inner'));
        $app = new RosannaDefer()
            ->registerComponent('App\\Outer', deferred(static fn(): Element => H::div(children: [$inner()]), new Defer('outer')))
            ->registerDeferred('outer', 'App\\Outer');

        $body = $app->handleDeferred(self::get('/_defer/outer'))?->body;

        self::assertSame('<div><div data-rosanna-defer-url="/_defer/inner"></div></div>', $body);
    }

    public function testDF6AComponentNestedInItselfStaysAPlaceholder(): void
    {
        $tree = null;
        $tree = deferred(static function (array $props) use (&$tree): Element {
            $depth = (int) $props['depth'];

            return H::div(children: [(string) $depth, $depth > 0 ? $tree(['depth' => $depth - 1]) : null]);
        }, new Defer('tree'));
        $app = new RosannaDefer()->registerComponent('App\\Tree', $tree);

        self::assertSame(
            '<div>2<div data-rosanna-defer-url="/_defer/tree?depth=1"></div></div>',
            $app->handleDeferred(self::get('/_defer/tree', ['depth' => '2']))?->body,
        );
    }

    public function testDF6AComponentWithoutAStopConditionDoesNotRecurse(): void
    {
        $loop = null;
        $loop = deferred(static function () use (&$loop): Element {
            return H::div(children: [$loop()]);
        }, new Defer('loop'));
        $app = new RosannaDefer()->registerComponent('App\\Loop', $loop);

        $response = $app->handleDeferred(self::get('/_defer/loop'));

        self::assertSame(200, $response?->status);
        self::assertSame('<div><div data-rosanna-defer-url="/_defer/loop"></div></div>', $response->body);
    }

    public function testDF14RegisterComponentRegistersTheDeclaration(): void
    {
        $app = new RosannaDefer()->registerComponent('App\\Pub', deferred(static fn(): Element => H::p(children: 'x'), new Defer('pub', cacheControl: 'public, max-age=60')));

        $response = $app->handleDeferred(self::get('/_defer/pub'));

        self::assertSame(200, $response?->status);
        self::assertSame('public, max-age=60', $response->headers['Cache-Control']);
    }

    public function testDF14RegisterDeferredWithoutAPolicyKeepsTheDeclaredOneInEitherOrder(): void
    {
        $component = deferred(static fn(): Element => H::p(children: 'x'), new Defer('pub', cacheControl: 'public, max-age=60'));
        $after = new RosannaDefer()->registerComponent('App\\Pub', $component)->registerDeferred('pub', 'App\\Pub');
        $before = new RosannaDefer()->registerDeferred('pub', 'App\\Pub')->registerComponent('App\\Pub', $component);

        self::assertSame('public, max-age=60', $after->handleDeferred(self::get('/_defer/pub'))?->headers['Cache-Control']);
        self::assertSame('public, max-age=60', $before->handleDeferred(self::get('/_defer/pub'))?->headers['Cache-Control']);
    }

    public function testDF14AConflictingPolicyIsRefused(): void
    {
        $app = new RosannaDefer()->registerComponent('App\\Pub', deferred(static fn(): Element => H::p(children: 'x'), new Defer('pub', cacheControl: 'public, max-age=60')));

        $this->expectException(\InvalidArgumentException::class);
        $app->registerDeferred('pub', 'App\\Pub', 'private');
    }

    public function testDF14TwoComponentsCannotDeclareTheSameName(): void
    {
        $app = new RosannaDefer()->registerComponent('App\\A', self::echoProps('same'));

        $this->expectException(\InvalidArgumentException::class);
        $app->registerComponent('App\\B', self::echoProps('same'));
    }

    public function testDF3RegisteringTheSameNameTwiceExplicitlyIsStillRefused(): void
    {
        $app = new RosannaDefer()->registerComponent('App\\Echo', self::echoProps())->registerDeferred('echo', 'App\\Echo');

        $this->expectException(\InvalidArgumentException::class);
        $app->registerDeferred('echo', 'App\\Echo');
    }

    public function testAPlainPsxFunctionCanBeATarget(): void
    {
        $app = new RosannaDefer()
            ->registerComponent('App\\Plain', static fn(array $props): Element => H::p(children: 'plain'))
            ->registerDeferred('plain', 'App\\Plain');

        self::assertSame('<p>plain</p>', $app->handleDeferred(self::get('/_defer/plain'))?->body);
    }

    public function testArgumentResolverReachesTheInnerComponent(): void
    {
        $component = deferred(static fn(array $props, string $service): Element => H::p(children: $service), new Defer('di'));
        $app = new RosannaDefer()
            ->registerComponent('App\\Di', $component)
            ->registerDeferred('di', 'App\\Di')
            ->setArgumentResolver(new class implements PsxArgumentResolver {
                public function resolve(string $fqcn, array $parameters, array $props): array
                {
                    return ['injected'];
                }
            });

        self::assertSame('<p>injected</p>', $app->handleDeferred(self::get('/_defer/di'))?->body);
    }

    public function testExceptionIsHiddenAndLogged(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'rosanna-defer-log');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $app = new RosannaDefer()
                ->registerComponent('App\\Boom', deferred(static fn(): Element => throw new \RuntimeException('/secret/path'), new Defer('boom')))
                ->registerDeferred('boom', 'App\\Boom');

            $response = $app->handleDeferred(self::get('/_defer/boom'));

            self::assertSame(500, $response?->status);
            self::assertStringNotContainsString('/secret/path', $response->body);
            self::assertStringContainsString('/secret/path', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    }

    public function testEventBindingsStillGuideToTheStateLayerInsideAFragment(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'rosanna-defer-log');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $app = new RosannaDefer()
                ->registerComponent('App\\Wire', deferred(static fn(): Element => new Element('button', ['wire:click' => 'x']), new Defer('wire')))
                ->registerDeferred('wire', 'App\\Wire');

            self::assertSame(500, $app->handleDeferred(self::get('/_defer/wire'))?->status);
            self::assertStringContainsString('polidog/rosanna-state', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    }

    public function testEndpointRestoresGetAndBuffers(): void
    {
        $_GET = ['outer' => '1'];
        $level = ob_get_level();
        try {
            $seen = null;
            $app = new RosannaDefer()
                ->registerComponent('App\\Get', deferred(static function () use (&$seen): Element {
                    $seen = $_GET;
                    echo 'printed';

                    return H::p(children: 'x');
                }, new Defer('get')))
                ->registerDeferred('get', 'App\\Get');

            $body = $app->handleDeferred(self::get('/_defer/get', ['a' => '1']))?->body;

            self::assertSame(['a' => '1'], $seen);
            self::assertSame('printed<p>x</p>', $body);
            self::assertSame(['outer' => '1'], $_GET);
            self::assertSame($level, ob_get_level());
        } finally {
            $_GET = [];
        }
    }

    public function testNoSessionStarts(): void
    {
        self::app()->handleDeferred(self::get('/_defer/echo'));

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertFalse(class_exists('Polidog\\Rosanna\\State\\RosannaState'));
    }

    public function testHostRendererUsesThePlaceholderRenderer(): void
    {
        $app = new RosannaDefer()->setDeferPrefix('/d');

        self::assertSame('<div data-rosanna-defer-url="/d/a"></div>', new Renderer(deferred: $app->placeholderRenderer())->renderElement(H::defer('a')));
    }

    public function testAComponentRenderedOutsideAnEndpointScopeIsAPlaceholder(): void
    {
        $app = self::app();

        $element = RenderContext::run($app->scope(), static fn(): Element => $app->scope()->psx->render('App\\Echo'));

        self::assertSame('__defer__', $element->type);
    }

    public function testClientScript(): void
    {
        $html = RosannaDefer::renderClientScript('/a.js?v=1', '/api/_d', 'n"1');

        self::assertStringContainsString('window.Rosanna.deferPrefix="/api/_d";', $html);
        self::assertStringContainsString('<script src="/a.js?v=1" defer nonce="n&quot;1"></script>', $html);
        self::assertSame(3, substr_count($html, 'nonce="n&quot;1"'));
        self::assertStringContainsString('window.Rosanna.reloadDefer', $html);
        self::assertStringNotContainsString('localStorage', $html);
        self::assertFileExists(ClientScript::path());
    }
}
