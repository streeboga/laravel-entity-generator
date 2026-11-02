<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Filesystem\Filesystem;
use LogicException;

/**
 * Подстановка в стабы: {{ключ}}; строка из одного плейсхолдера вставляется с отступом, пустое значение
 * убирает строку; секции {{#флаг}}…{{/флаг}} и {{^флаг}}…{{/флаг}} (на своих строках или внутри строки).
 * Стаб ищется сначала в каталоге проекта, потом в пакете; для владельца — сперва вариант `имя.вид.stub`.
 */
final readonly class StubRenderer
{
    public function __construct(private Filesystem $files) {}

    public function locate(string $stub, string $kind): string
    {
        $dirs = [base_path((string) config('entity-generator.stubs_path', 'stubs/entity')), dirname(__DIR__, 2).'/stubs'];
        foreach ($dirs as $dir) {
            foreach ([$stub.'.'.$kind, $stub] as $name) {
                if ($this->files->exists($dir.'/'.$name.'.stub')) {
                    return $dir.'/'.$name.'.stub';
                }
            }
        }

        throw new LogicException("Стаб «{$stub}» не найден ни в проекте, ни в пакете.");
    }

    /** @param array<string, string|bool|list<string>> $vars */
    public function render(string $stub, string $kind, array $vars): string
    {
        $text = $this->files->get($this->locate($stub, $kind));
        $text = $this->sections($text, $vars);
        $text = $this->inline($text, $vars);
        $text = (string) preg_replace_callback('/^([ \t]*)\{\{(\w+)\}\}$/m', function (array $m) use ($vars): string {
            $value = $this->value($vars, $m[2]);
            if ($value === '') {
                return "\u{0}";
            }

            return implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : $m[1].$line, explode("\n", $value)));
        }, $text);
        $text = (string) preg_replace("/^\u{0}\n/m", '', $text);
        $text = (string) preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $m): string => $this->value($vars, $m[1]), $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\u{0}", '', $text));

        return $this->tidyImports($text);
    }

    /** @param array<string, string|bool|list<string>> $vars */
    private function value(array $vars, string $key): string
    {
        if (! array_key_exists($key, $vars)) {
            throw new LogicException("В стабе плейсхолдер {{{$key}}} без значения.");
        }
        $v = $vars[$key];

        return is_array($v) ? implode("\n", $v) : (is_bool($v) ? ($v ? '1' : '') : $v);
    }

    /** @param array<string, string|bool|list<string>> $vars */
    private function sections(string $text, array $vars): string
    {
        $out = [];
        $stack = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*\{\{([#^])(\w+)\}\}\s*$/', $line, $m) === 1) {
                $stack[] = ($this->value($vars, $m[2]) !== '') === ($m[1] === '#');
            } elseif (preg_match('/^\s*\{\{\/\w+\}\}\s*$/', $line) === 1) {
                array_pop($stack);
            } elseif (! in_array(false, $stack, true)) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /** @param array<string, string|bool|list<string>> $vars */
    private function inline(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{([#^])(\w+)\}\}(.*?)\{\{\/\2\}\}/', fn (array $m): string => ($this->value($vars, $m[2]) !== '') === ($m[1] === '#') ? $m[3] : '', $text);
    }

    /** Блок `use` в шапке: без повторов, по алфавиту, без классов своего пространства имён. */
    private function tidyImports(string $text): string
    {
        $namespace = preg_match('/^namespace ([^;]+);/m', $text, $n) === 1 ? $n[1] : null;
        $lines = explode("\n", $text);
        $first = null;
        $last = null;
        $uses = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^use ([^;]+);$/', $line, $m) === 1) {
                $first ??= $i;
                $last = $i;
                $uses[$m[1]] = true;
            } elseif ($first !== null && trim($line) !== '') {
                break;
            } elseif ($first !== null) {
                $last = $i;
            }
        }
        if ($first === null) {
            return $text;
        }
        $names = array_keys($uses);
        $names = array_filter($names, static fn (string $u): bool => $namespace === null || ! (str_starts_with($u, $namespace.'\\') && ! str_contains(substr($u, strlen($namespace) + 1), '\\')));
        sort($names, SORT_STRING | SORT_FLAG_CASE);
        $block = array_map(static fn (string $u): string => 'use '.$u.';', $names);
        // хвостовая пустая строка блока остаётся одной
        $tail = $last !== null && trim($lines[$last]) === '' ? [''] : [];
        array_splice($lines, $first, ($last ?? $first) - $first + 1, [...$block, ...$tail]);

        return implode("\n", $lines);
    }
}
