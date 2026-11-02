<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Data;

/** Поле сущности: имя, тип (enum — со значениями), модификаторы. */
final readonly class EntityField
{
    /** @param list<string> $args значения enum */
    public function __construct(
        public string $name,
        public string $type,
        public array $args = [],
        public bool $required = false,
        public bool $unique = false,
        public bool $index = false,
        public bool $immutable = false,
    ) {}
}
