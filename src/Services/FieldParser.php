<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Services;

use InvalidArgumentException;
use Streeboga\EntityGenerator\Data\EntityField;

/** Разбор полей: «имя:тип[:модификаторы]», enum — `status:enum(draft,paid)`; через запятую вне скобок. */
final class FieldParser
{
    private const array MODIFIERS = ['required', 'unique', 'index', 'immutable', 'nullable'];

    /** @return list<EntityField> */
    public function parseList(string $list): array
    {
        $fields = [];
        $depth = 0;
        $buffer = '';
        foreach (str_split($list) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($char === ',' && $depth === 0) {
                $fields[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $fields[] = $buffer;

        return array_values(array_map($this->parse(...), array_filter(array_map('trim', $fields), static fn (string $f): bool => $f !== '')));
    }

    /**
     * YAML: карта «имя: тип:модификаторы» или список строк «имя:тип:модификаторы».
     *
     * @param  array<int|string, string>  $fields
     * @return list<EntityField>
     */
    public function parseMap(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $definition) {
            $out[] = $this->parse(is_int($key) ? (string) $definition : $key.':'.$definition);
        }

        return $out;
    }

    public function parse(string $definition): EntityField
    {
        $parts = explode(':', trim($definition));
        $name = array_shift($parts);
        $type = array_shift($parts) ?? '';
        if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $name) || $type === '') {
            throw new InvalidArgumentException("Поле «{$definition}»: нужен вид имя:тип[:модификаторы], имя — snake_case.");
        }
        $args = [];
        if (preg_match('/^(\w+)\((.*)\)$/', $type, $m) === 1) {
            $type = $m[1];
            $args = array_values(array_filter(array_map('trim', explode(',', $m[2])), static fn (string $a): bool => $a !== ''));
        }
        foreach ($parts as $modifier) {
            if (! in_array($modifier, self::MODIFIERS, true)) {
                throw new InvalidArgumentException("Поле «{$name}»: модификатор «{$modifier}» неизвестен (".implode(', ', self::MODIFIERS).').');
            }
        }

        return new EntityField((string) $name, $type, $args, in_array('required', $parts, true), in_array('unique', $parts, true), in_array('index', $parts, true), in_array('immutable', $parts, true));
    }

    /** Обратно в «тип(значения):модификаторы» — для YAML. */
    public function format(EntityField $field): string
    {
        $type = $field->args === [] ? $field->type : $field->type.'('.implode(',', $field->args).')';
        $mods = array_filter([$field->required ? 'required' : null, $field->unique ? 'unique' : null, $field->index ? 'index' : null, $field->immutable ? 'immutable' : null]);

        return implode(':', [$type, ...$mods]);
    }
}
