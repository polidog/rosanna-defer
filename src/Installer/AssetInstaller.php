<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Installer;

use Composer\Script\Event;
use Polidog\Rosanna\Defer\ClientScript;

/**
 * rosanna-defer.js をプロジェクトの public/ に配置する Composer スクリプト。
 * Event の型は Composer の中でしか解決されない。
 */
final class AssetInstaller
{
    /** 失敗は警告にとどめる。`composer install` を止めないため */
    public static function publish(Event $event): void
    {
        $io = $event->getIO();

        try {
            $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
            if (!is_string($vendorDir) || $vendorDir === '') {
                $io->writeError('<warning>Rosanna: could not determine the vendor directory</warning>');

                return;
            }

            $targetDir = dirname($vendorDir) . '/public';
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
                $io->writeError('<warning>Rosanna: could not create the public directory</warning>');

                return;
            }
            if (!@copy(ClientScript::path(), $targetDir . '/rosanna-defer.js')) {
                $io->writeError('<warning>Rosanna: could not copy rosanna-defer.js</warning>');

                return;
            }

            $io->write('<info>Rosanna:</info> Published rosanna-defer.js to public/rosanna-defer.js');
        } catch (\Throwable $e) {
            $io->writeError('<warning>Rosanna: could not publish rosanna-defer.js: ' . $e->getMessage() . '</warning>');
        }
    }
}
