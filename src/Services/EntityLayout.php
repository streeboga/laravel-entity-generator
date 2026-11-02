<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Streeboga\EntityGenerator\Data\EntitySpec;

/** Куда ложится слой: цель, раскладка, пространство имён, путь. Всё из конфига, без ветвлений по именам слоёв. */
final class EntityLayout
{
    /**
     * Слой выключен (нет в раскладке цели) — null.
     *
     * @param  array<string, string>  $vars  дополнительные подстановки в имя класса ({Field})
     * @return array{fqcn: string, namespace: string, class: string, path: string, dir: string, target: string}|null
     */
    public function info(EntitySpec $spec, string $layer, array $vars = []): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $def = $cfg['layers'][$layer] ?? throw new InvalidArgumentException("Слой «{$layer}» не описан (layers).");
        $targetKey = $spec->target;
        $own = true;
        if (($def['http'] ?? false) && ($http = $cfg['targets'][$targetKey]['http_target'] ?? null) !== null) {
            $targetKey = (string) $http;
            $own = false;
        }
        $target = $cfg['targets'][$targetKey] ?? throw new InvalidArgumentException("Цель «{$targetKey}» не описана (targets).");
        $replace = ['{Name}' => $spec->name, '{Names}' => Str::pluralStudly($spec->name), ...array_combine(array_map(static fn (string $k): string => '{'.$k.'}', array_keys($vars)), $vars)];
        $class = strtr((string) ($def['class'] ?? '{Name}'), $replace);
        $kind = $def['kind'] ?? null;

        if ($kind === 'migration') {
            return ['fqcn' => '', 'namespace' => '', 'class' => '', 'path' => '', 'dir' => (string) $target['migrations'], 'target' => $targetKey];
        }
        if ($kind === 'factory') {
            $ns = (string) $target['factory_namespace'];

            return ['fqcn' => $ns.'\\'.$class, 'namespace' => $ns, 'class' => $class, 'dir' => (string) $target['factories'], 'path' => $target['factories'].'/'.$class.'.php', 'target' => $targetKey];
        }
        if ($kind === 'test') {
            $dir = $target['tests'].'/'.Str::pluralStudly($spec->name);

            return ['fqcn' => '', 'namespace' => '', 'class' => $class, 'dir' => $dir, 'path' => $dir.'/'.$class.'.php', 'target' => $targetKey];
        }

        $layoutName = $own && $spec->layout !== null ? $spec->layout : (string) $target['layout'];
        $dir = $cfg['layouts'][$layoutName][$layer] ?? null;
        if ($dir === null) {
            return null;
        }
        $dir = strtr((string) $dir, $replace);
        $namespace = $target['namespace'].'\\'.str_replace('/', '\\', $dir);

        return ['fqcn' => $namespace.'\\'.$class, 'namespace' => $namespace, 'class' => $class, 'dir' => $dir, 'path' => $target['root'].'/'.$dir.'/'.$class.'.php', 'target' => $targetKey];
    }
}
