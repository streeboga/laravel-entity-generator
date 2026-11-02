<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Data;

/** Описание сущности: единый источник для всех слоёв (хранится YAML-файлом рядом с целью). */
final readonly class EntitySpec
{
    /**
     * @param  list<EntityField>  $fields  поля пользователя
     * @param  list<EntityField>  $ownerFields  синтетические поля владельца (project_id)
     * @param  list<string>  $actions  index, show, store, update, destroy
     * @param  array<string, string>  $permissions
     */
    public function __construct(
        public string $name,
        public string $table,
        public string $target,
        public ?string $layout,
        public string $owner,
        public string $idType,
        public ?string $publicId,
        public bool $softDeletes,
        public bool $policy,
        public bool $test,
        public bool $builder,
        public bool $filament,
        public array $actions,
        public array $permissions,
        public array $fields,
        public array $ownerFields = [],
    ) {}

    /** @return list<EntityField> */
    public function allFields(): array
    {
        return [...$this->ownerFields, ...$this->fields];
    }

    /** Включено ли действие (для списка — хотя бы одно из них). */
    public function has(string|array $action): bool
    {
        return array_intersect((array) $action, $this->actions) !== [];
    }

    /**
     * Копия с другим набором полей пользователя.
     *
     * @param  list<EntityField>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->name, $this->table, $this->target, $this->layout, $this->owner, $this->idType, $this->publicId, $this->softDeletes, $this->policy, $this->test, $this->builder, $this->filament, $this->actions, $this->permissions, array_values($fields), $this->ownerFields);
    }
}
