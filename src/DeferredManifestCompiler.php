<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use function Polidog\Rosanna\Defer\Internal\Functions\deferredManifestEntries;
use function Polidog\Rosanna\Defer\Internal\Functions\deferredManifestSource;

use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\PsxRenderer;

/**
 * `rosanna-defer compile` と `rosanna-state compile` が `CompileCommand` の manifest の後に動かし、defer の登録を deferred manifest に書く。
 *
 * @internal
 */
final class DeferredManifestCompiler
{
    /**
     * defer がなくても必ず書き、manifest と同じ build ID を持つ (CP-136)。
     *
     * @param array<string, string> $components FQCN => ソースのパス
     *
     * @return list<string> 古い (書き換えた) パス
     */
    public function compile(array $components, string $cacheDir, string $buildId, bool $check): array
    {
        $path = $cacheDir . \DIRECTORY_SEPARATOR . PsxRenderer::DEFERRED_MANIFEST_FILENAME;
        $source = deferredManifestSource($buildId, $this->collectDeferred($components, $cacheDir));

        return CompileCommand::writeIfChanged($path, $source, $check) ? [$path] : [];
    }

    /**
     * manifest の隣の deferred manifest を読み、build ID を照合する。ファイルの有無だけを見ると、
     * `rosanna-defer compile` の後の `rosanna compile` で古い登録を受け入れてしまう。
     *
     * @return array<string, array{component: string, cacheControl: ?string}> 名前 => 登録の内容
     * @throws \RuntimeException ファイルがない、build ID が合わない、項目の形が違うとき
     */
    public static function read(string $manifestPath): array
    {
        $path = \dirname($manifestPath) . \DIRECTORY_SEPARATOR . PsxRenderer::DEFERRED_MANIFEST_FILENAME;
        if (!\file_exists($path)) {
            throw new \RuntimeException("Deferred manifest not found: {$path}. Deferred components need `vendor/bin/rosanna-defer compile` or `vendor/bin/rosanna-state compile`, not `vendor/bin/rosanna compile`.");
        }

        $data = PsxRenderer::readArrayFile($path);
        if (($data[PsxRenderer::BUILD_ID_KEY] ?? null) !== PsxRenderer::readBuildId($manifestPath) || !isset($data[PsxRenderer::BUILD_ID_KEY])) {
            throw new \RuntimeException("Deferred manifest {$path} does not match {$manifestPath}. Run `vendor/bin/rosanna-defer compile` or `vendor/bin/rosanna-state compile` to regenerate.");
        }

        return deferredManifestEntries($data, $path);
    }

    /**
     * CP-135: `Defer` の文字列を含むファイルだけを読み込む。トップレベルのコードが実行される。
     *
     * @param array<string, string> $components FQCN => ソースのパス
     *
     * @return array<array-key, array{component: string, cacheControl: ?string}>
     */
    private function collectDeferred(array $components, string $cacheDir): array
    {
        \ksort($components, \SORT_STRING);

        $entries = [];
        foreach ($components as $fqcn => $file) {
            $artifactPath = CompileCommand::cachePathFor($cacheDir, $file);
            $artifact = \is_file($artifactPath) ? @\file_get_contents($artifactPath) : false;
            if ($artifact === false || !\str_contains($artifact, 'Defer')) {
                continue;
            }

            \ob_start();
            try {
                $value = require $artifactPath;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Error: failed to load $artifactPath ($file) while scanning for deferred components: " . $e->getMessage(),
                    previous: $e,
                );
            } finally {
                \ob_end_clean();
            }

            $defer = $value instanceof DeclaresDefer ? $value->deferDeclaration() : null;
            if ($defer === null) {
                continue;
            }

            $name = $defer->name;
            if (isset($entries[$name])) {
                throw new \RuntimeException(
                    "Error: duplicate deferred component name '$name'\n"
                    . "  registered by: {$entries[$name]['component']}\n  also by:       $fqcn",
                );
            }
            $entries[$name] = ['component' => $fqcn, 'cacheControl' => $defer->cacheControl];
        }

        return $entries;
    }
}
