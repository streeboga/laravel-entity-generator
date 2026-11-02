<?php

declare(strict_types=1);

use Streeboga\EntityGenerator\Services\FieldParser;

it('разбирает поля с enum и модификаторами', function (): void {
    $fields = (new FieldParser)->parseList('number:string,total:money:required,status:enum(draft,paid):unique');

    expect($fields)->toHaveCount(3)
        ->and($fields[1]->required)->toBeTrue()
        ->and($fields[2]->args)->toBe(['draft', 'paid'])
        ->and($fields[2]->unique)->toBeTrue();
});

it('отказывает на неизвестный модификатор и плохое имя', function (): void {
    expect(fn () => (new FieldParser)->parse('a:string:wat'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new FieldParser)->parse('BadName:string'))->toThrow(InvalidArgumentException::class);
});

it('форматирует обратно для YAML', function (): void {
    $p = new FieldParser;

    expect($p->format($p->parse('status:enum(a,b):required')))->toBe('enum(a,b):required');
});
