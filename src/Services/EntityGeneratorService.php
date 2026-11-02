<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Streeboga\EntityGenerator\Data\EntitySpec;
use Streeboga\EntityGenerator\Data\GeneratedFile;
use Streeboga\EntityGenerator\Data\GenerationResult;

/** Генерация сущности: файлы из описания, запись без перезаписи, предупреждения, что осталось вручную. */
final readonly class EntityGeneratorService
{
    public function __construct(private EntityRenderer $renderer, private EntityLayout $layout, private DefinitionStore $definitions, private MigrationNamer $migrations, private Filesystem $files) {}

    public function generate(EntitySpec $spec, bool $dryRun = false, bool $force = false, bool $appendRoutes = false, bool $testFirst = false): GenerationResult
    {
        $result = new GenerationResult;
        $files = $this->renderer->files($spec, $testFirst ? ['test'] : null);
        $stamp = Carbon::now();

        $m = 0;
        foreach ($files as $file) {
            $path = str_contains($file->path, '{TS}') ? $this->migrations->resolve($file->path, $stamp->copy()->addSeconds($m++), $spec->table) : $file->path;
            $this->warnImports($file, $result);
            $this->write($path, $file->content, $dryRun, $force, $result);
        }
        if (! $testFirst) {
            $this->routes($spec, $dryRun, $appendRoutes, $result);
            $this->notes($spec, $files, $result);
        }
        if (! $dryRun) {
            $result->updated[] = $this->definitions->write($spec);
        }
        $result->manual[] = $testFirst
            ? 'Тест падает — так и задумано. Дальше: make:entity '.$spec->name.' (без --test-first) создаст остальное, существующий тест не тронет.'
            : 'Прогнать форматтер (pint) по созданным файлам, затем migrate и тест сущности.';

        return $result;
    }

    private function write(string $path, string $content, bool $dryRun, bool $force, GenerationResult $result): void
    {
        $abs = base_path($path);
        if ($this->files->exists($abs) && ! $force) {
            $result->skipped[] = $path;

            return;
        }
        if (! $dryRun) {
            $this->files->ensureDirectoryExists(dirname($abs));
            $this->files->put($abs, $content);
        }
        $result->created[] = $path;
    }

    private function warnImports(GeneratedFile $file, GenerationResult $result): void
    {
        $target = (array) config('entity-generator.targets.'.$file->target);
        $forbid = (array) ($target['forbid_imports'] ?? []);
        if ($forbid === []) {
            return;
        }
        preg_match_all('/^use ([^;]+);$/m', $file->content, $m);
        foreach ($m[1] as $import) {
            $banned = array_filter($forbid, static fn (string $p): bool => str_starts_with($import, $p));
            if ($banned !== [] && ! in_array($import, (array) ($target['allow_imports'] ?? []), true)) {
                $result->warnings[] = "{$file->path}: импорт {$import} нарушает правило цели «{$file->target}» (запрещено: ".implode(', ', $banned).'). Переложи слой в другую цель или убери зависимость.';
            }
        }
    }

    private function routes(EntitySpec $spec, bool $dryRun, bool $append, GenerationResult $result): void
    {
        if (array_intersect($spec->actions, ['index', 'show', 'store', 'update', 'destroy']) === []) {
            return;
        }
        $snippet = $this->renderer->routes($spec);
        $route = $this->renderer->route($spec);
        $controller = $this->layout->info($spec, 'controller');
        // Файл маршрутов цели контроллера (пакет — свой, его подключает ServiceProvider пакета) главнее файла владельца.
        $route['file'] = config('entity-generator.targets.'.($controller['target'] ?? $spec->target).'.route_file') ?? $route['file'];
        $file = base_path((string) $route['file']);
        $fresh = $append && $controller !== null && ! $this->files->exists($file);
        if ($append && $controller !== null) {
            if (! $fresh && str_contains($this->files->get($file), '\\'.$controller['fqcn'].'::class')) {
                $result->skipped[] = $route['file'].' (маршруты уже есть)';

                return;
            }
            if (! $dryRun) {
                $this->files->ensureDirectoryExists(dirname($file));
                $fresh && $this->files->put($file, "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n");
                $this->files->append($file, "\n".$snippet);
            }
            $fresh ? $result->created[] = $route['file'] : $result->updated[] = $route['file'];
            $fresh && $result->manual[] = "Создан {$route['file']}: подключите его в ServiceProvider цели (loadRoutesFrom), иначе маршрутов нет.";

            return;
        }
        $result->routes = $snippet;
        $result->manual[] = "Маршруты: вставить фрагмент в {$route['file']} (или запустить с --routes); файл пакета подключает его ServiceProvider (loadRoutesFrom).";
    }

    /**
     * @param  list<GeneratedFile>  $files
     */
    private function notes(EntitySpec $spec, array $files, GenerationResult $result): void
    {
        $layers = array_unique(array_map(static fn (GeneratedFile $f): string => $f->layer, $files));
        foreach ((array) config('entity-generator.notes', []) as $layer => $note) {
            if (in_array($layer, $layers, true)) {
                $result->manual[] = (string) $note;
            }
        }
        $policy = $this->layout->info($spec, 'policy');
        $model = $this->layout->info($spec, 'model');
        if ($spec->policy && $policy !== null && $model !== null && $policy['target'] !== $model['target']) {
            $result->manual[] = "Политика {$policy['fqcn']} не находится автообнаружением для модели {$model['fqcn']}: Gate::policy(...) в провайдере.";
        }
    }
}
