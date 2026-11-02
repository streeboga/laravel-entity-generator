<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use Illuminate\Support\Str;
use Streeboga\EntityGenerator\Data\EntityField;
use Streeboga\EntityGenerator\Data\EntitySpec;

/** Markdown-описание сущности для передачи агенту фронтенда: только чтение описания, ничего не генерирует. */
final readonly class PromptBuilder
{
    public function __construct(private FieldFragments $fragments, private EntityRenderer $renderer) {}

    public function build(EntitySpec $spec): string
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('entity-generator');
        $route = $this->renderer->route($spec);
        $segment = str_replace('_', '-', $spec->table);
        $param = '{'.Str::camel($spec->name).'}';
        $base = (str_starts_with($route['prefix'], 'api/') ? '/' : '/api/').$route['prefix'].'/'.$segment;
        $rows = array_map(fn (EntityField $f): array => [$f, $this->fragments->for($spec, $f)], $spec->allFields());

        $md = ["# Сущность {$spec->name}", '', "Таблица `{$spec->table}`, владелец: `{$spec->owner}`, ключ: ".($spec->publicId !== null ? "`public_id` (префикс `{$spec->publicId}`)" : "`{$spec->idType}`").($spec->softDeletes ? ', мягкое удаление' : '').'.', ''];

        $md[] = '## Поля';
        $md[] = '| Поле | Тип | Обязательно | Валидация (создание) | Правка |';
        $md[] = '|---|---|---|---|---|';
        foreach ($rows as [$f, $frag]) {
            $type = $f->args === [] ? $f->type : $f->type.'('.implode(', ', $f->args).')';
            $md[] = '| `'.$f->name.'` | '.$type.' | '.($f->required ? 'да' : 'нет').' | `'.$this->rules($frag['rule_store']).'` | '.($f->immutable ? 'не меняется' : 'PATCH, необязательно').' |';
        }
        $md[] = '';
        $md[] = '## Действия и маршруты';
        $routes = ['index' => ['GET', ''], 'show' => ['GET', '/'.$param], 'store' => ['POST', ''], 'update' => ['PATCH', '/'.$param], 'destroy' => ['DELETE', '/'.$param]];
        foreach ($spec->actions as $action) {
            [$verb, $suffix] = $routes[$action];
            $md[] = "- `{$action}`: `{$verb} {$base}{$suffix}`";
        }
        $md[] = '';
        $md[] = 'Формат — JSON:API (`data.type = "'.$segment.'"`, поля в `data.attributes`); `POST` отвечает 201 и `Location`, `DELETE` — 204, чужая запись — 404.';
        $md[] = '';
        $md[] = '## Запрос (создание)';
        $md[] = '```json';
        $md[] = '{'.implode(', ', array_map(fn (array $r): string => '"'.$r[0]->name.'": '.$this->sample($r[0], $r[1]), array_values(array_filter($rows, static fn (array $r): bool => $r[0]->required)))).'}';
        $md[] = '```';
        $md[] = '';
        $md[] = '## Ответ';
        $md[] = '```json';
        $md[] = '{"data": {"id": "…", "type": "'.$segment.'", "attributes": {'.implode(', ', array_map(static fn (array $r): string => '"'.$r[0]->name.'": …', $rows)).', "created_at": "…", "updated_at": "…"}}}';
        $md[] = '```';
        if ($spec->permissions !== []) {
            $md[] = '';
            $md[] = '## Права';
            foreach ($spec->permissions as $kind => $permission) {
                $md[] = "- {$kind}: `{$permission}`";
            }
        }

        return implode("\n", $md)."\n";
    }

    private function rules(string $line): string
    {
        return preg_match('/=> \[(.*)\],$/', $line, $m) === 1 ? str_replace('|', '\\|', $m[1]) : $line;
    }

    /** @param array<string, mixed> $frag */
    private function sample(EntityField $f, array $frag): string
    {
        $value = preg_replace('/^\'[a-z0-9_]+\' => /', '', rtrim((string) $frag['sample'], ','));

        return str_replace("'", '"', (string) $value);
    }
}
