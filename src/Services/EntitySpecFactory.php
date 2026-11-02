<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use InvalidArgumentException;
use Illuminate\Support\Str;
use Streeboga\EntityGenerator\Data\EntitySpec;

/**
 * Сборка описания: defaults < пресеты < YAML < опции команды. Ничего не пишет на диск.
 */
final readonly class EntitySpecFactory
{
    public function __construct(private FieldParser $parser) {}

    /**
     * @param  array<string, mixed>  $yaml  описание из YAML (ключи как у спеки; `fields` — карта)
     * @param  array<string, mixed>  $input  явно заданные опции; отсутствующий ключ — «не задано»
     */
    public function make(?string $name, array $yaml, array $input): EntitySpec
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $data = $cfg['defaults'];

        /** @var list<string> $presets */
        $presets = [...(array) ($yaml['preset'] ?? []), ...(array) ($input['preset'] ?? [])];
        foreach ($presets as $preset) {
            $data = array_replace($data, $cfg['presets'][$preset] ?? throw new InvalidArgumentException("Пресет «{$preset}» не описан в config/entity-generator.php (presets)."));
        }
        $data = array_replace($data, array_diff_key($yaml, array_flip(['preset', 'fields', 'name'])));
        $data = array_replace($data, array_filter($input, static fn (mixed $v, string $k): bool => $v !== null && ! in_array($k, ['preset', 'fields', 'from'], true), ARRAY_FILTER_USE_BOTH));

        $name = $name ?? ($yaml['name'] ?? null);
        if (! is_string($name) || preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Имя сущности — StudlyCase, например Invoice.');
        }

        $target = (string) $data['target'];
        if (! isset($cfg['targets'][$target])) {
            throw new InvalidArgumentException("Цель «{$target}» не описана в config/entity-generator.php (targets): ".implode(', ', array_keys($cfg['targets'])).'.');
        }
        $owner = (string) $data['owner'];
        if (! isset($cfg['owners'][$owner])) {
            throw new InvalidArgumentException("Владелец «{$owner}» не описан (owners): ".implode(', ', array_keys($cfg['owners'])).'.');
        }
        if (! isset($cfg['id_types'][$data['id_type']])) {
            throw new InvalidArgumentException("Тип ключа «{$data['id_type']}» не описан (id_types).");
        }
        $layout = $data['layout'] === null ? null : (string) $data['layout'];
        if ($layout !== null && ! isset($cfg['layouts'][$layout])) {
            throw new InvalidArgumentException("Раскладка «{$layout}» не описана (layouts): ".implode(', ', array_keys($cfg['layouts'])).'.');
        }

        $actions = is_string($data['actions']) ? array_filter(array_map('trim', explode(',', $data['actions']))) : (array) $data['actions'];
        $actions = array_values($actions);
        foreach ($actions as $action) {
            if (! in_array($action, ['index', 'show', 'store', 'update', 'destroy'], true)) {
                throw new InvalidArgumentException("Действие «{$action}» неизвестно: index, show, store, update, destroy.");
            }
        }

        $fields = [];
        foreach ([...$this->parser->parseMap((array) ($yaml['fields'] ?? [])), ...$this->parser->parseList((string) ($input['fields'] ?? ''))] as $field) {
            $fields[$field->name] = $field;
        }
        foreach ($fields as $field) {
            if (! isset($cfg['types'][$field->type])) {
                throw new InvalidArgumentException("Поле «{$field->name}»: тип «{$field->type}» не описан (types): ".implode(', ', array_keys($cfg['types'])).'.');
            }
            if ($field->type === 'enum' && $field->args === []) {
                throw new InvalidArgumentException("Поле «{$field->name}»: у enum нужны значения, enum(draft,paid).");
            }
        }

        return new EntitySpec(
            name: $name,
            table: (string) ($data['table'] ?? Str::snake(Str::pluralStudly($name))),
            target: $target,
            layout: $layout,
            owner: $owner,
            idType: (string) $data['id_type'],
            publicId: $data['public_id'] === null || $data['public_id'] === '' ? null : (string) $data['public_id'],
            softDeletes: (bool) $data['soft_deletes'],
            policy: (bool) $data['policy'],
            test: (bool) $data['test'],
            builder: (bool) $data['builder'],
            filament: (bool) $data['filament'],
            actions: $actions,
            permissions: (array) $data['permissions'],
            fields: array_values($fields),
            ownerFields: $this->parser->parseList(implode(',', (array) ($cfg['owners'][$owner]['fields'] ?? []))),
        );
    }

    /** Поля, пришедшие отдельной командой (`make:entity-field`): тот же разбор, без пересборки спеки. */
    public function parser(): FieldParser
    {
        return $this->parser;
    }
}
