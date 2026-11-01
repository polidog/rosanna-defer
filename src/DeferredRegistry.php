<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

final class DeferredRegistry
{
    /** @var array<string, array{component: string, cacheControl: ?string}> */
    private array $entries = [];

    /** @var array<string, true> コンポーネントの宣言だけから作られた項目の名前 */
    private array $declared = [];

    /**
     * 同じ名前の登録は例外 (DF-3)。名前は URL になるので、別の内容を黙って上書きしない。
     * コンポーネントの宣言から作られた項目は、矛盾しなければ明示の登録で確定する (DF-14)。
     *
     * @throws \InvalidArgumentException 名前が不正か、登録済みのとき
     */
    public function register(string $name, string $component, ?string $cacheControl = null): self
    {
        Defer::assertValidName($name);
        if (isset($this->declared[$name]) && $this->merge($name, $component, $cacheControl)) {
            unset($this->declared[$name]);

            return $this;
        }
        if (isset($this->entries[$name])) {
            throw new \InvalidArgumentException(sprintf(
                "Deferred name '%s' is already registered (component: %s). Deferred names are part of the public URL surface and must be unique.",
                $name,
                $this->entries[$name]['component'],
            ));
        }
        $this->entries[$name] = ['component' => $component, 'cacheControl' => $cacheControl];

        return $this;
    }

    /**
     * コンポーネントの `Defer` の宣言を登録する (DF-14)。登録の順は問わない。明示の登録が方針を持たなければ宣言の方針を使う。
     *
     * @throws \InvalidArgumentException 名前が不正か、内容が矛盾するとき
     */
    public function registerDeclared(string $name, string $component, ?string $cacheControl): self
    {
        if (isset($this->entries[$name])) {
            if (!$this->merge($name, $component, $cacheControl)) {
                $this->register($name, $component, $cacheControl);
            }

            return $this;
        }

        $this->register($name, $component, $cacheControl);
        $this->declared[$name] = true;

        return $this;
    }

    /** 同じコンポーネントで方針が矛盾しなければ、1 つにまとめて true を返す */
    private function merge(string $name, string $component, ?string $cacheControl): bool
    {
        $existing = $this->entries[$name] ?? null;
        if ($existing === null || $existing['component'] !== $component) {
            return false;
        }
        if ($cacheControl !== null && $existing['cacheControl'] !== null && $cacheControl !== $existing['cacheControl']) {
            return false;
        }
        $this->entries[$name]['cacheControl'] = $cacheControl ?? $existing['cacheControl'];

        return true;
    }

    /** まったく同じ内容の登録は何もしない。内容が違えば register() と同じく例外 */
    public function registerIfNew(string $name, string $component, ?string $cacheControl): self
    {
        $existing = $this->entries[$name] ?? null;
        if ($existing === null || $existing['component'] !== $component || $existing['cacheControl'] !== $cacheControl) {
            $this->register($name, $component, $cacheControl);
        }

        return $this;
    }

    /** @return array{component: string, cacheControl: ?string}|null */
    public function get(string $name): ?array
    {
        return $this->entries[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->entries));
    }
}
