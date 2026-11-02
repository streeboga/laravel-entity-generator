<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Support\Str;
use Streeboga\EntityGenerator\Data\EntityField;
use Streeboga\EntityGenerator\Data\EntitySpec;

/**
 * Фрагменты кода одного поля по описанию типа из конфига (`types`). Только строки: куда их вставить,
 * решает стаб (при генерации) или якорь `@gen:` (при `make:entity-field`).
 */
final readonly class FieldFragments
{
    public function __construct(private EntityLayout $layout) {}

    /**
     * Ключи: column, indexes (list), prop, cast, create_param, update_param, attr, change, rule_store, rule_update,
     * arg_store, arg_update, resource, factory, sample, uses (bucket => list), enum_cases.
     *
     * @return array<string, mixed>
     */
    public function for(EntitySpec $spec, EntityField $f): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $t = $cfg['types'][$f->type];
        $enum = '';
        $enumFqcn = '';
        if ($f->type === 'enum') {
            $info = $this->layout->info($spec, 'enum', ['Field' => Str::studly($f->name)]);
            $enum = $info['class'] ?? '';
            $enumFqcn = $info['fqcn'] ?? '';
        }
        $r = [
            '{name}' => $f->name, '{enum}' => $enum, '{enum_fqcn}' => $enumFqcn, '{first}' => $f->args[0] ?? '',
            '{currency}' => (string) $cfg['money']['currency'], '{precision}' => (string) $cfg['money']['precision'], '{scale}' => (string) $cfg['money']['scale'],
        ];
        $sub = static fn (string $tpl, array $extra = []): string => strtr($tpl, $r + $extra);
        $owner = $cfg['owners'][$spec->owner];
        $prefix = (array) ($owner['unique_prefix'] ?? []);
        $get = '$data[\''.$f->name.'\']';
        $prop = Str::camel($f->name);
        $this_ = '$this->'.$prop;
        $plain = ! isset($t['attr']);
        $attrExpr = $sub($t['attr'] ?? '{this}', ['{this}' => $this_]);
        $input = $sub($t['input'], ['{v}' => $get]);
        $php = $sub($t['php']);
        $nullable = ! $f->required;

        $column = '$table->'.$sub($t['column']).($nullable ? '->nullable()' : '').($f->unique && $prefix === [] ? '->unique()' : '').';';
        $indexes = [];
        if ($f->unique && $prefix !== []) {
            $indexes[] = '$table->unique([\''.implode("', '", [...$prefix, $f->name]).'\']);';
        }
        if ($f->index) {
            $indexes[] = '$table->index(\''.$f->name.'\');';
        }

        $unique = $f->unique ? ["Rule::unique('{$spec->table}', '{$f->name}')".($owner['unique_scope'] ?? '')] : [];
        $key = (string) $cfg['id_types'][$spec->idType]['key'];
        $uniqueUpdate = $f->unique ? ["Rule::unique('{$spec->table}', '{$f->name}')".($owner['unique_scope'] ?? '').'->ignore($this->route(\''.Str::camel($spec->name).'\'), \''.($spec->publicId !== null ? 'public_id' : $key).'\')'] : [];
        $rule = static fn (array $lead, array $extra): string => "'{$f->name}' => [".implode(', ', [...$lead, ...$extra]).'],';
        $typeRule = $sub($t['rule']);

        $uses = [];
        foreach ((array) ($t['uses'] ?? []) as $bucket => $list) {
            $uses[$bucket] = array_map($sub, $list);
        }
        // Запрос строит DTO теми же выражениями (Money::of, Carbon::parse): импорты те же.
        if (isset($uses['dto']) && ! isset($uses['request'])) {
            $uses['request'] = $uses['dto'];
        }
        if ($f->unique) {
            $uses['request'][] = 'Illuminate\\Validation\\Rule';
        }

        $cast = isset($t['cast']) ? "'{$f->name}' => ".$sub($t['cast']).',' : null;
        $doc = $sub($t['doc']);

        return [
            'column' => $column,
            'indexes' => $indexes,
            'prop' => ' * @property '.$doc.($nullable ? '|null' : '').' $'.$f->name,
            'cast' => $cast,
            'create_param' => $f->required ? "public {$php} \${$prop}," : "public ?{$php} \${$prop} = null,",
            'update_param' => "public ?{$php} \${$prop} = null,",
            'attr' => "'{$f->name}' => ".($plain ? $this_ : ($nullable ? "{$this_} === null ? null : {$attrExpr}" : $attrExpr)).',',
            'change' => "...({$this_} === null ? [] : ['{$f->name}' => {$attrExpr}]),",
            'rule_store' => $rule([$f->required ? "'required'" : "'nullable'"], [$typeRule, ...$unique]),
            'rule_update' => $rule(["'sometimes'", ...($nullable ? ["'nullable'"] : [])], [$typeRule, ...$uniqueUpdate]),
            'arg_store' => $prop.': '.($f->required ? $input : "isset({$get}) ? {$input} : null").',',
            'arg_update' => $prop.': '."isset({$get}) ? {$input} : null,",
            'resource' => "'{$f->name}' => ".$sub($t['resource']).',',
            'factory' => isset($t['factory']) ? "'{$f->name}' => ".$sub($t['factory']).',' : null,
            'sample' => "'{$f->name}' => ".$sub($t['sample']).',',
            'fillable' => "'{$f->name}',",
            'filament' => $sub((string) $cfg['filament_column']),
            'uses' => $uses,
            'enum_cases' => $f->type === 'enum' ? array_map(static fn (string $v): string => '    case '.Str::studly($v)." = '{$v}';", $f->args) : [],
        ];
    }
}
