<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Support\Str;
use Streeboga\EntityGenerator\Data\EntityField;
use Streeboga\EntityGenerator\Data\EntitySpec;
use Streeboga\EntityGenerator\Data\GeneratedFile;

/** Превращает описание в файлы: переменные для стабов из конфига и полей, затем подстановка. Диск не трогает. */
final readonly class EntityRenderer
{
    public function __construct(private EntityLayout $layout, private FieldFragments $fragments, private StubRenderer $stubs) {}

    /**
     * @param  list<string>|null  $only  ограничить слоями (test-first)
     * @return list<GeneratedFile>
     */
    public function files(EntitySpec $spec, ?array $only = null): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $kind = (string) $cfg['owners'][$spec->owner]['kind'];
        [$vars, $frag] = $this->context($spec);
        $files = [];

        foreach ($cfg['layers'] as $layer => $def) {
            if ($only !== null && ! in_array($layer, $only, true)) {
                continue;
            }
            if (($def['option'] ?? null) !== null && ! $spec->{$def['option']}) {
                continue;
            }
            if (($def['action'] ?? null) !== null && ! $spec->has($def['action'])) {
                continue;
            }
            if ($layer === 'enum') {
                foreach ($spec->fields as $field) {
                    $field->type === 'enum' && $files[] = $this->enumFile($spec, $field);
                }

                continue;
            }
            $info = $this->layout->info($spec, $layer);
            if ($info === null) {
                continue;
            }
            $path = $def['kind'] ?? null;
            $files[] = new GeneratedFile($layer, $path === 'migration' ? $info['dir'].'/{TS}_create_'.$spec->table.'_table.php' : $info['path'],
                $this->stubs->render($def['stub'], $kind, array_replace($vars, $this->layerVars($layer, $frag), ['namespace' => $info['namespace'], 'class' => $info['class']])), $info['target']);
        }
        if ($only === null) {
            foreach ((array) ($cfg['owners'][$spec->owner]['extra_migrations'] ?? []) as $name => $stub) {
                $dir = $this->layout->info($spec, 'migration')['dir'] ?? '';
                $files[] = new GeneratedFile('migration', $dir.'/{TS}_'.strtr($name, ['{table}' => $spec->table]).'.php', $this->stubs->render($stub, $kind, $vars + ['namespace' => '', 'class' => '']), $spec->target);
            }
        }

        return $files;
    }

    /**
     * Настройки маршрутов: владелец, затем цель контроллера (пакет без префикса `api` задаёт свой), затем общие.
     *
     * @return array{middleware: list<string>, prefix: string, name_prefix: string, file: string}
     */
    public function route(EntitySpec $spec): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $target = $this->layout->info($spec, 'controller')['target'] ?? $spec->target;

        return $cfg['owners'][$spec->owner]['route'] ?? $cfg['targets'][$target]['route'] ?? $cfg['route'];
    }

    /** Фрагмент маршрутов (для печати или дописывания в файл). */
    public function routes(EntitySpec $spec): string
    {
        return $this->stubs->render('routes', (string) config('entity-generator.owners.'.$spec->owner.'.kind'), $this->context($spec)[0] + ['namespace' => '', 'class' => '']);
    }

    /** @return array{0: array<string, string|bool|list<string>>, 1: list<array{0: EntityField, 1: array<string, mixed>}>} */
    private function context(EntitySpec $spec): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $owner = $cfg['owners'][$spec->owner];
        $id = $cfg['id_types'][$spec->idType];
        $fields = $spec->allFields();
        $frag = array_map(fn (EntityField $f): array => [$f, $this->fragments->for($spec, $f)], $fields);
        $col = static fn (array $rows, string $key): array => array_values(array_filter(array_map(static fn (array $p) => $p[1][$key], $rows), static fn (mixed $v): bool => $v !== null));
        $user = (string) $cfg['user_model'];
        $feature = $spec->softDeletes ? $cfg['features']['soft_deletes'] : ['traits' => [], 'uses' => [], 'prop' => null, 'column' => ''];
        $segment = str_replace('_', '-', $spec->table);
        $route = $this->route($spec);
        $name = Str::camel($spec->name);
        $sub = static fn (string $s): string => strtr($s, ['{table}' => $spec->table, '{user_class}' => class_basename($user)]);
        $asUse = static fn (array $list): array => array_map(static fn (string $c): string => 'use '.$c.';', $list);

        $vars = [
            'Name' => $spec->name, 'Names' => Str::pluralStudly($spec->name), 'name' => $name, 'names' => $spec->table, 'table' => $spec->table,
            'id_prop' => $id['prop'], 'id_column' => $id['column'], 'key_column' => $spec->publicId !== null ? 'public_id' : $id['key'],
            'route_where' => $spec->publicId !== null ? preg_quote($spec->publicId, '/').'[a-z0-9]{26}' : $id['where'], 'route_param' => $name,
            'route_segment' => $segment, 'route_name' => $segment, 'resource_type' => $segment,
            'route_middleware' => implode(', ', $route['middleware']), 'route_prefix' => $route['prefix'], 'route_name_prefix' => $route['name_prefix'],
            'test_uri' => (str_starts_with($route['prefix'], 'api/') ? '/' : '/api/').$route['prefix'].'/'.$segment,
            'user_fqcn' => $user, 'user_class' => class_basename($user),
            'json_api_resource' => $cfg['json_api']['resource'], 'json_api_collection' => $cfg['json_api']['collection'],
            'public_id' => $spec->publicId ?? '', 'public_column' => $spec->publicId !== null ? "\$table->string('public_id', 40)->unique();" : '',
            'soft_column' => $feature['column'], 'builder' => $spec->builder, 'policy' => $spec->policy,
            'user' => $owner['kind'] === 'user', 'tenant' => $owner['kind'] === 'tenant',
            'perm_view' => (string) ($spec->permissions['view'] ?? ''), 'perm_manage' => (string) ($spec->permissions['manage'] ?? ''),
            'columns' => $col($frag, 'column'),
            'owner_columns' => array_map($sub, (array) ($owner['columns'] ?? [])),
            'indexes' => [...array_map($sub, (array) ($owner['indexes'] ?? [])), ...array_merge([], ...array_map(static fn (array $p): array => $p[1]['indexes'], $frag))],
            'foreign' => array_map($sub, (array) ($owner['foreign'] ?? [])),
            'props' => [...array_map(static fn (string $p): string => ' * @property '.$p, array_filter([...(array) ($owner['props'] ?? []), $spec->publicId !== null ? 'string $public_id' : null, $feature['prop']])), ...$col($frag, 'prop')],
            'fillable' => array_map(static fn (string $f): string => "'{$f}',", [...(array) ($owner['fillable'] ?? []), ...array_map(static fn (EntityField $f): string => $f->name, $fields)]),
            'casts' => $col($frag, 'cast'),
            'traits' => array_map(static fn (string $t): string => 'use '.$t.';', [...$id['traits'], ...$feature['traits'], ...(array) ($owner['traits'] ?? [])]),
            'uses' => [...$asUse([...$id['uses'], ...$feature['uses'], ...(array) ($owner['uses'] ?? [])]), ...$asUse($this->uses($frag, 'model'))],
            'params' => '', 'attrs' => '', 'changes' => '', 'rules' => '', 'args' => '', 'sample' => $col($frag, 'sample'),
            'owner_factory' => array_map($sub, (array) ($owner['factory'] ?? [])),
            'cases' => '',
        ];
        foreach (['index', 'show', 'store', 'update', 'destroy'] as $action) {
            $vars[$action] = $spec->has($action);
        }
        foreach ($cfg['layers'] as $layer => $def) {
            $info = $def['kind'] ?? null ? null : $this->layout->info($spec, $layer);
            if ($layer === 'factory') {
                $info = $this->layout->info($spec, 'factory');
            }
            if ($info !== null && $info['fqcn'] !== '') {
                $vars[$layer.'_fqcn'] = $info['fqcn'];
                $vars[$layer.'_class'] = $info['class'];
            }
        }

        return [$vars, $frag];
    }

    /**
     * Списки полей, различающиеся по слою (DTO создания и правки, запросы, ресурс, фабрика).
     *
     * @param  list<array{0: EntityField, 1: array<string, mixed>}>  $frag
     * @return array<string, string|list<string>>
     */
    private function layerVars(string $layer, array $frag): array
    {
        $rows = static fn (bool $all): array => $all ? $frag : array_values(array_filter($frag, static fn (array $p): bool => ! $p[0]->immutable));
        $col = static fn (array $r, string $key): array => array_values(array_filter(array_map(static fn (array $p): mixed => $p[1][$key], $r), static fn (mixed $v): bool => $v !== null));
        $req = [...array_filter($frag, static fn (array $p): bool => $p[0]->required), ...array_filter($frag, static fn (array $p): bool => ! $p[0]->required)];
        $asUse = fn (string $bucket): array => array_map(static fn (string $c): string => 'use '.$c.';', $this->uses($frag, $bucket));

        return match ($layer) {
            'dto_create' => ['params' => $col($req, 'create_param'), 'attrs' => $col($frag, 'attr'), 'uses' => $asUse('dto')],
            'dto_update' => ['params' => $col($rows(false), 'update_param'), 'changes' => $col($rows(false), 'change'), 'uses' => $asUse('dto')],
            'request_store' => ['rules' => $col($frag, 'rule_store'), 'args' => $col($frag, 'arg_store'), 'uses' => $asUse('request')],
            'request_update' => ['rules' => $col($rows(false), 'rule_update'), 'args' => $col($rows(false), 'arg_update'), 'uses' => $asUse('request')],
            'resource' => ['attrs' => $col($frag, 'resource')],
            'filament' => ['columns' => $col($frag, 'filament')],
            'factory' => ['attrs' => $col($frag, 'factory'), 'uses' => $asUse('factory')],
            default => [],
        };
    }

    /**
     * @param  list<array{0: EntityField, 1: array<string, mixed>}>  $frag
     * @return list<string>
     */
    private function uses(array $frag, string $bucket): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (array $p): array => $p[1]['uses'][$bucket] ?? [], $frag))));
    }

    public function enumFile(EntitySpec $spec, EntityField $field): GeneratedFile
    {
        $kind = (string) config('entity-generator.owners.'.$spec->owner.'.kind');
        $info = $this->layout->info($spec, 'enum', ['Field' => Str::studly($field->name)]);
        $cases = $this->fragments->for($spec, $field)['enum_cases'];

        return new GeneratedFile('enum', (string) $info['path'], $this->stubs->render('enum', $kind, ['namespace' => $info['namespace'], 'class' => $info['class'], 'cases' => $cases]), $info['target']);
    }
}
