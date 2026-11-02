<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Streeboga\EntityGenerator\Data\EntityField;
use Streeboga\EntityGenerator\Data\EntitySpec;
use Streeboga\EntityGenerator\Data\GenerationResult;

/**
 * Одно поле меняется одной командой: миграция и точечная вставка по якорям `@gen:` в файлы слоёв.
 * Нет файла или якоря — файл уходит в «вручную», код не угадывается. Переименование и удаление:
 * миграция и описание, а файлы, где поле встречается, перечисляются — правка текста там не делается.
 */
final readonly class FieldChangeService
{
    /** слой => [якорь => ключ фрагмента] */
    private const array ANCHORS = [
        'model' => ['props' => 'prop', 'fillable' => 'fillable', 'casts' => 'cast'],
        'dto_create' => ['params' => 'update_param', 'attrs' => 'attr'],
        'dto_update' => ['params' => 'update_param', 'changes' => 'change'],
        'request_store' => ['rules' => 'rule_store', 'args' => 'arg_store'],
        'request_update' => ['rules' => 'rule_update', 'args' => 'arg_update'],
        'resource' => ['attrs' => 'resource'],
        'factory' => ['attrs' => 'factory'],
        'test' => ['payload' => 'sample'],
        'filament' => ['columns' => 'filament'],
    ];

    /** слой => бакет импортов поля */
    private const array USES = ['model' => 'model', 'dto_create' => 'dto', 'dto_update' => 'dto', 'request_store' => 'request', 'request_update' => 'request', 'factory' => 'factory'];

    public function __construct(
        private EntityLayout $layout,
        private FieldFragments $fragments,
        private EntityRenderer $renderer,
        private StubRenderer $stubs,
        private DefinitionStore $definitions,
        private MigrationNamer $migrations,
        private Filesystem $files,
    ) {}

    public function add(EntitySpec $spec, EntityField $field, bool $dryRun): GenerationResult
    {
        if (collect($spec->allFields())->contains(fn (EntityField $f): bool => $f->name === $field->name)) {
            throw new InvalidArgumentException("Поле «{$field->name}» у {$spec->name} уже есть.");
        }
        if (! isset(config('entity-generator.types')[$field->type])) {
            throw new InvalidArgumentException("Тип «{$field->type}» не описан в config/entity-generator.php (types).");
        }
        $result = new GenerationResult;
        $frag = $this->fragments->for($spec, $field);

        $this->migration($spec, 'add_'.$field->name.'_to_'.$spec->table.'_table', 'migration.add', [
            'columns' => [$frag['column']], 'indexes' => $frag['indexes'], 'names' => "'{$field->name}'",
        ], $dryRun, $result);
        if ($field->type === 'enum') {
            $enum = $this->renderer->enumFile($spec, $field);
            $this->put($enum->path, $enum->content, $dryRun, $result);
        }
        foreach (self::ANCHORS as $layer => $anchors) {
            $this->insertInto($spec, $layer, $anchors, $frag, $dryRun, $result);
        }
        $this->definitions->write($spec->withFields([...$spec->fields, $field]));
        $result->updated[] = $this->definitions->path($spec->target, $spec->name);
        $result->manual[] = 'Прогнать форматтер (pint), migrate и тест сущности. Поле в DTO создания — необязательное (`?T = null`): обязательность держит запрос.';

        return $result;
    }

    public function drop(EntitySpec $spec, string $name, bool $dryRun): GenerationResult
    {
        $this->assertExists($spec, $name);
        $result = new GenerationResult;
        $this->migration($spec, 'drop_'.$name.'_from_'.$spec->table.'_table', 'migration.drop', ['names' => "'{$name}'"], $dryRun, $result);
        $this->listMentions($spec, $name, $result);
        $dryRun || $this->definitions->write($spec->withFields(array_values(array_filter($spec->fields, static fn (EntityField $f): bool => $f->name !== $name))));

        return $result;
    }

    public function rename(EntitySpec $spec, string $from, string $to, bool $dryRun): GenerationResult
    {
        $this->assertExists($spec, $from);
        $result = new GenerationResult;
        $this->migration($spec, 'rename_'.$from.'_to_'.$to.'_in_'.$spec->table.'_table', 'migration.rename', ['from' => $from, 'to' => $to], $dryRun, $result);
        $this->listMentions($spec, $from, $result);
        $dryRun || $this->definitions->write($spec->withFields(array_map(static fn (EntityField $f): EntityField => $f->name === $from
            ? new EntityField($to, $f->type, $f->args, $f->required, $f->unique, $f->index, $f->immutable) : $f, $spec->fields)));

        return $result;
    }

    private function assertExists(EntitySpec $spec, string $name): void
    {
        if (! collect($spec->fields)->contains(fn (EntityField $f): bool => $f->name === $name)) {
            throw new InvalidArgumentException("Поля «{$name}» нет в описании {$spec->name}.");
        }
    }

    /** @param array<string, string|bool|list<string>> $vars */
    private function migration(EntitySpec $spec, string $name, string $stub, array $vars, bool $dryRun, GenerationResult $result): void
    {
        $dir = (string) $this->layout->info($spec, 'migration')['dir'];
        $pattern = $dir.'/{TS}_'.$name.'.php';
        if ($this->migrations->exists($pattern)) {
            $result->skipped[] = $dir.'/*_'.$name.'.php';

            return;
        }
        $content = $this->stubs->render($stub, (string) config('entity-generator.owners.'.$spec->owner.'.kind'), $vars + ['table' => $spec->table, 'columns' => '', 'indexes' => '', 'names' => '', 'from' => '', 'to' => '']);
        $this->put($this->migrations->resolve($pattern, Carbon::now(), $spec->table), $content, $dryRun, $result);
    }

    /**
     * @param  array<string, string>  $anchors
     * @param  array<string, mixed>  $frag
     */
    private function insertInto(EntitySpec $spec, string $layer, array $anchors, array $frag, bool $dryRun, GenerationResult $result): void
    {
        if (config('entity-generator.layers.'.$layer) === null) {
            return;
        }
        $info = $this->layout->info($spec, $layer);
        if ($info === null || ! $this->enabled($spec, $layer)) {
            return;
        }
        $path = base_path($info['path']);
        if (! $this->files->exists($path)) {
            $result->manual[] = "{$info['path']}: файла нет — вставить поле вручную.";

            return;
        }
        $text = $this->files->get($path);
        $changed = false;
        foreach ($anchors as $anchor => $key) {
            $line = $frag[$key] ?? null;
            if ($line === null) {
                continue;
            }
            $inserted = $this->insertBefore($text, '@gen:'.$anchor, (string) $line);
            if ($inserted === null) {
                $result->manual[] = "{$info['path']}: нет якоря @gen:{$anchor} — вставить вручную: {$line}";

                continue;
            }
            $changed = $changed || $inserted !== $text;
            $text = $inserted;
        }
        $text = $this->addUses($text, (array) ($frag['uses'][self::USES[$layer] ?? ''] ?? []), $changed);
        if ($changed) {
            $dryRun || $this->files->put($path, $text);
            $result->updated[] = $info['path'];
        }
    }

    private function enabled(EntitySpec $spec, string $layer): bool
    {
        $def = (array) config('entity-generator.layers.'.$layer);

        return (($def['option'] ?? null) === null || (bool) $spec->{$def['option']}) && (($def['action'] ?? null) === null || $spec->has($def['action']));
    }

    /** Строка вставляется перед строкой с якорем, с её отступом; повтор не вставляется. null — якоря нет. */
    private function insertBefore(string $text, string $anchor, string $fragment): ?string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if (! str_contains($line, $anchor)) {
                continue;
            }
            if (str_contains($text, $fragment)) {
                return $text;
            }
            preg_match('/^\s*/', $line, $m);
            array_splice($lines, $i, 0, [$m[0].$fragment]);

            return implode("\n", $lines);
        }

        return null;
    }

    /** @param list<string> $uses */
    private function addUses(string $text, array $uses, bool &$changed): string
    {
        foreach ($uses as $use) {
            if ($use === '' || str_contains($text, 'use '.$use.';')) {
                continue;
            }
            if (preg_match_all('/^use [^;]+;$/m', $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                $last = end($m[0]);
                $text = substr_replace($text, "\nuse {$use};", $last[1] + strlen($last[0]), 0);
                $changed = true;
            }
        }

        return $text;
    }

    private function listMentions(EntitySpec $spec, string $name, GenerationResult $result): void
    {
        foreach (array_keys((array) config('entity-generator.layers')) as $layer) {
            $info = $this->layout->info($spec, $layer);
            if ($info !== null && $info['path'] !== '' && $this->files->exists(base_path($info['path'])) && str_contains($this->files->get(base_path($info['path'])), "'{$name}'")) {
                $result->manual[] = "{$info['path']}: упоминает '{$name}' — поправить вручную.";
            }
        }
        $result->manual[] = 'Прогнать форматтер (pint), migrate и тест сущности.';
    }

    private function put(string $path, string $content, bool $dryRun, GenerationResult $result): void
    {
        if ($this->files->exists(base_path($path))) {
            $result->skipped[] = $path;

            return;
        }
        if (! $dryRun) {
            $this->files->ensureDirectoryExists(dirname(base_path($path)));
            $this->files->put(base_path($path), $content);
        }
        $result->created[] = $path;
    }
}
