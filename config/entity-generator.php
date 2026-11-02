<?php

declare(strict_types=1);

/*
 * Конфиг по умолчанию. Проект переопределяет нужные ключи в своём config/entity-generator.php
 * (слияние рекурсивное: достаточно указать то, что меняется). Стабы проекта (`stubs_path`) главнее стабов пакета.
 *
 * Плейсхолдеры в стабах: {{ключ}}. Строка из одного плейсхолдера вставляется с отступом строки;
 * блоки {{#флаг}} … {{/флаг}} (каждый маркер на своей строке) остаются, только если флаг включён.
 * Якоря `@gen:имя` в сгенерированных файлах — места, куда `make:entity-field` вставляет новые поля.
 */
return [
    'stubs_path' => 'stubs/entity',

    // Что берётся, когда не сказано иное (YAML и опции команды главнее).
    'defaults' => [
        'target' => 'app',
        'layout' => null,          // null — layout цели
        'owner' => 'none',
        'id_type' => 'bigint',     // ключи id_types
        'public_id' => null,       // префикс публичного ключа, например "inv_"
        'soft_deletes' => false,
        'policy' => true,
        'test' => true,
        'builder' => false,
        'filament' => false,
        'actions' => ['index', 'show', 'store', 'update', 'destroy'],
        'permissions' => [],       // для owner-стабов с правами (ключи view, manage)
    ],

    // Пресеты: любые ключи defaults, накладываются по порядку --preset.
    'presets' => [
        'public' => ['public_id' => 'ent_'],
        'readonly' => ['actions' => ['index', 'show']],
        'soft' => ['soft_deletes' => true],
    ],

    // Цели: корень путей и пространство имён. Пакет — своя цель с `http_target`:
    // HTTP-слои (`http: true` у слоя) ложатся в указанную цель, а не в пакет.
    // `forbid_imports` / `allow_imports` — предупреждение, если файл цели тянет запрещённый префикс.
    'targets' => [
        'app' => [
            'root' => 'app',
            'namespace' => 'App',
            'layout' => 'laravel',
            'migrations' => 'database/migrations',
            'factories' => 'database/factories',
            'definitions' => 'entities',
            'factory_namespace' => 'Database\\Factories',
            'tests' => 'tests/Feature',
            'http_target' => null,   // HTTP-слои (http: true) в другую цель; null — в эту же
            'route_file' => null,    // файл маршрутов цели; null — из owners.*.route / route
            'forbid_imports' => [],
            'allow_imports' => [],
        ],
    ],

    'user_model' => 'App\\Models\\User',

    // JSON:API-ресурсы (timacdonald/json-api); свой базовый класс — здесь.
    'json_api' => [
        'resource' => 'TiMacDonald\\JsonApi\\JsonApiResource',
        'collection' => 'TiMacDonald\\JsonApi\\JsonApiResourceCollection',
    ],

    // Маршруты по умолчанию; владелец может переопределить ключ `route` целиком.
    // middleware — готовые PHP-выражения; file — куда дописывает `--routes`.
    'route' => [
        'middleware' => ["'auth:sanctum'"],
        'prefix' => 'v1',
        'name_prefix' => 'api.',
        'file' => 'routes/api.php',
    ],

    // Где хранится описание (YAML) и фрагменты стабов проекта — ключ `definitions` у цели.

    // Раскладка слоёв внутри цели: слой => каталог относительно root ({Names} — множественное StudlyCase).
    // Нет слоя — он выключен. Свою раскладку (например DDD) добавляют сюда же и выбирают --layout.
    'layouts' => [
        'laravel' => [
            'model' => 'Models',
            'enum' => 'Enums',
            'dto_create' => 'DataTransferObjects/{Names}',
            'dto_update' => 'DataTransferObjects/{Names}',
            'request_store' => 'Http/Requests/{Names}',
            'request_update' => 'Http/Requests/{Names}',
            'builder' => 'Builders',
            'repository' => 'Repositories',
            'service' => 'Services',
            'controller' => 'Http/Controllers/Api/V1',
            'resource' => 'Http/Resources',
            'policy' => 'Policies',
        ],
        'ddd' => [
            'model' => 'Domain/{Names}/Models',
            'enum' => 'Domain/{Names}/Enums',
            'dto_create' => 'Application/{Names}/DTO',
            'dto_update' => 'Application/{Names}/DTO',
            'builder' => 'Infrastructure/{Names}/Builders',
            'repository' => 'Infrastructure/{Names}/Repositories',
            'service' => 'Application/{Names}/Services',
            'request_store' => 'Http/Requests/{Names}',
            'request_update' => 'Http/Requests/{Names}',
            'controller' => 'Http/Controllers/Api/V1',
            'resource' => 'Http/Resources',
            'policy' => 'Policies',
        ],
    ],

    // Слои: шаблон имени класса, стаб, http — слой HTTP-части, option — включается флагом/ключом спеки,
    // kind — слой с особым путём (migration, factory, test).
    'layers' => [
        'model' => ['class' => '{Name}', 'stub' => 'model'],
        'enum' => ['class' => '{Name}{Field}', 'stub' => 'enum'],
        'dto_create' => ['class' => 'Create{Name}Data', 'stub' => 'dto.create', 'action' => 'store'],
        'dto_update' => ['class' => 'Update{Name}Data', 'stub' => 'dto.update', 'action' => 'update'],
        'request_store' => ['class' => 'Store{Name}Request', 'stub' => 'request.store', 'http' => true, 'action' => 'store'],
        'request_update' => ['class' => 'Update{Name}Request', 'stub' => 'request.update', 'http' => true, 'action' => 'update'],
        'builder' => ['class' => '{Name}QueryBuilder', 'stub' => 'builder', 'option' => 'builder'],
        'repository' => ['class' => '{Name}Repository', 'stub' => 'repository'],
        'service' => ['class' => '{Name}Service', 'stub' => 'service'],
        'controller' => ['class' => '{Name}Controller', 'stub' => 'controller', 'http' => true],
        'resource' => ['class' => '{Name}Resource', 'stub' => 'resource', 'http' => true],
        'policy' => ['class' => '{Name}Policy', 'stub' => 'policy', 'http' => true, 'option' => 'policy'],
        'migration' => ['kind' => 'migration', 'stub' => 'migration'],
        'factory' => ['kind' => 'factory', 'class' => '{Name}Factory', 'stub' => 'factory'],
        'test' => ['kind' => 'test', 'class' => '{Name}Test', 'stub' => 'test', 'option' => 'test'],
    ],

    // Владелец строки. kind выбирает вариант стаба (`controller.user.stub`, иначе `controller.stub`).
    // columns/foreign/indexes — строки миграции, fillable/props/uses/traits — модели, fields — синтетические
    // поля («имя:тип:модификаторы»; immutable — не в Update), unique_prefix — колонки перед уникальным полем,
    // unique_scope — условие Rule::unique, extra_migrations — ещё миграции: имя => стаб.
    'owners' => [
        'none' => ['kind' => 'none'],
        'user' => [
            'kind' => 'user',
            'columns' => ["\$table->foreignId('user_id')->constrained()->cascadeOnDelete();"],
            'indexes' => ["\$table->index(['user_id', 'id']);"],
            'fillable' => ['user_id'],
            'props' => ['int $user_id'],
            'unique_prefix' => ['user_id'],
            'unique_scope' => "->where('user_id', \$this->user()?->id)",
            'factory' => ["'user_id' => {user_class}::factory(),"],
        ],
    ],

    // Хранение ключа: column — строка миграции, key — колонка маршрута, where — regex маршрута, traits/uses — модель.
    'id_types' => [
        'bigint' => ['column' => '$table->id();', 'key' => 'id', 'where' => '[0-9]+', 'prop' => 'int', 'traits' => [], 'uses' => []],
        'ulid' => ['column' => "\$table->ulid('id')->primary();", 'key' => 'id', 'where' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'prop' => 'string',
            'traits' => ['HasUlids'], 'uses' => ['Illuminate\\Database\\Eloquent\\Concerns\\HasUlids']],
    ],

    'features' => [
        'soft_deletes' => [
            'column' => '$table->softDeletesTz();',
            'traits' => ['SoftDeletes'],
            'uses' => ['Illuminate\\Database\\Eloquent\\SoftDeletes'],
            'prop' => '\\Illuminate\\Support\\Carbon|null $deleted_at',
        ],
    ],

    // Колонка таблицы Filament для поля ({name}); используется стабом layer `filament`, если он настроен.
    'filament_column' => "TextColumn::make('{name}')->label('{name}'),",

    // Заметки «осталось вручную» по слоям: печатаются, когда слой сгенерирован.
    'notes' => [],

    'money' => ['currency' => 'RUB', 'precision' => 18, 'scale' => 8],

    // Типы полей. Шаблоны: {name} имя, {v} входное значение, {this} свойство DTO, {enum}/{enum_fqcn} класс enum,
    // {currency}, {args} значения enum через запятую.
    'types' => [
        'string' => ['column' => "string('{name}')", 'php' => 'string', 'doc' => 'string', 'rule' => "'string', 'max:255'",
            'input' => '(string) {v}', 'factory' => 'fake()->words(3, true)', 'sample' => "'sample'", 'resource' => '$this->{name}'],
        'text' => ['column' => "text('{name}')", 'php' => 'string', 'doc' => 'string', 'rule' => "'string', 'max:5000'",
            'input' => '(string) {v}', 'factory' => 'fake()->sentence()', 'sample' => "'sample text'", 'resource' => '$this->{name}'],
        'int' => ['column' => "integer('{name}')", 'php' => 'int', 'doc' => 'int', 'cast' => "'integer'", 'rule' => "'integer'",
            'input' => '(int) {v}', 'factory' => 'fake()->numberBetween(1, 1000)', 'sample' => '10', 'resource' => '$this->{name}'],
        'bool' => ['column' => "boolean('{name}')", 'php' => 'bool', 'doc' => 'bool', 'cast' => "'boolean'", 'rule' => "'boolean'",
            'input' => '(bool) {v}', 'factory' => 'fake()->boolean()', 'sample' => 'true', 'resource' => '$this->{name}'],
        'money' => ['column' => "decimal('{name}', {precision}, {scale})", 'php' => 'Money', 'doc' => 'string', 'rule' => "'numeric', 'min:0', 'decimal:0,{scale}'",
            'input' => "Money::of((string) {v}, '{currency}', new CustomContext({scale}))", 'attr' => '(string) {this}->getAmount()',
            'factory' => "(string) fake()->randomFloat(2, 1, 1000)", 'sample' => "'10.50'", 'resource' => '$this->{name}',
            'uses' => ['dto' => ['Brick\\Money\\Money', 'Brick\\Money\\Context\\CustomContext']]],
        'date' => ['column' => "date('{name}')", 'php' => 'CarbonInterface', 'doc' => '\\Illuminate\\Support\\Carbon', 'cast' => "'date'", 'rule' => "'date'",
            'input' => 'Carbon::parse((string) {v})', 'factory' => 'fake()->date()', 'sample' => "'2026-01-31'", 'resource' => '$this->{name}?->toDateString()',
            'uses' => ['dto' => ['Carbon\\CarbonInterface', 'Illuminate\\Support\\Carbon']]],
        'datetime' => ['column' => "timestampTz('{name}')", 'php' => 'CarbonInterface', 'doc' => '\\Illuminate\\Support\\Carbon', 'cast' => "'datetime'", 'rule' => "'date'",
            'input' => 'Carbon::parse((string) {v})', 'factory' => 'fake()->dateTime()', 'sample' => "'2026-01-31T10:00:00Z'", 'resource' => '$this->{name}?->toIso8601String()',
            'uses' => ['dto' => ['Carbon\\CarbonInterface', 'Illuminate\\Support\\Carbon']]],
        'json' => ['column' => "jsonb('{name}')", 'php' => 'array', 'doc' => 'array<string, mixed>', 'cast' => "'array'", 'rule' => "'array'",
            'input' => '(array) {v}', 'factory' => '[]', 'sample' => '[]', 'resource' => '$this->{name}'],
        'enum' => ['column' => "string('{name}', 32)", 'php' => '{enum}', 'doc' => '{enum}', 'cast' => '{enum}::class', 'rule' => 'Rule::enum({enum}::class)',
            'input' => '{enum}::from((string) {v})', 'factory' => 'fake()->randomElement({enum}::cases())', 'sample' => "'{first}'", 'resource' => '$this->{name}?->value',
            'uses' => ['dto' => ['{enum_fqcn}'], 'model' => ['{enum_fqcn}'], 'request' => ['Illuminate\\Validation\\Rule', '{enum_fqcn}'], 'factory' => ['{enum_fqcn}']]],
    ],
];
