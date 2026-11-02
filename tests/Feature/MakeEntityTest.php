<?php

declare(strict_types=1);

const FIELDS = 'number:string:required:unique,total:money:required,status:enum(draft,paid),due_at:date';

it('--dry-run ничего не пишет', function (): void {
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => FIELDS, '--dry-run' => true, '--owner' => 'user'])->assertSuccessful();

    expect($this->phpFiles())->toHaveCount(1); // только routes/api.php
    expect(is_dir($this->sandbox.'/entities'))->toBeFalse();
});

it('создаёт круг файлов, валидный PHP, и не перезаписывает без --force', function (): void {
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => FIELDS, '--owner' => 'user', '--routes' => true])->assertSuccessful();

    foreach ($this->phpFiles() as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
        expect($code)->toBe(0, $file.' '.implode("\n", $out));
    }
    $model = file_get_contents($this->sandbox.'/app/Models/Invoice.php');
    expect($model)->toContain('@property string $number')->toContain("'total',")->toContain('InvoiceStatus::class')
        ->and(file_exists($this->sandbox.'/app/Enums/InvoiceStatus.php'))->toBeTrue()
        ->and(file_exists($this->sandbox.'/entities/Invoice.yaml'))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/routes/api.php'))->toContain("->name('invoices.index')")
        ->and(glob($this->sandbox.'/database/migrations/*_create_invoices_table.php'))->toHaveCount(1);

    file_put_contents($this->sandbox.'/app/Models/Invoice.php', 'mine');
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => FIELDS, '--owner' => 'user'])->assertSuccessful();
    expect(file_get_contents($this->sandbox.'/app/Models/Invoice.php'))->toBe('mine');
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => FIELDS, '--owner' => 'user', '--force' => true])->assertSuccessful();
    expect(file_get_contents($this->sandbox.'/app/Models/Invoice.php'))->not->toBe('mine')
        ->and(glob($this->sandbox.'/database/migrations/*_create_invoices_table.php'))->toHaveCount(1);
});

it('выключенное действие убирает метод, запрос и маршрут', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required', '--preset' => ['readonly']])->assertSuccessful();

    $controller = file_get_contents($this->sandbox.'/app/Http/Controllers/Api/V1/NoteController.php');
    expect($controller)->toContain('function index')->not->toContain('function store')
        ->and(file_exists($this->sandbox.'/app/Http/Requests/Notes/StoreNoteRequest.php'))->toBeFalse()
        ->and(file_exists($this->sandbox.'/app/DataTransferObjects/Notes/CreateNoteData.php'))->toBeFalse();
});

it('--actions=index,show: ни реквестов, ни DTO, ни create/update/delete в слоях, билдера нет', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required', '--owner' => 'user', '--actions' => 'index,show', '--routes' => true])->assertSuccessful();

    expect(file_exists($this->sandbox.'/app/Http/Requests/Notes/StoreNoteRequest.php'))->toBeFalse()
        ->and(file_exists($this->sandbox.'/app/Http/Requests/Notes/UpdateNoteRequest.php'))->toBeFalse()
        ->and(glob($this->sandbox.'/app/DataTransferObjects/Notes/*'))->toBe([])
        ->and(glob($this->sandbox.'/app/Builders/*'))->toBe([]);

    foreach (['Services/NoteService', 'Repositories/NoteRepository', 'Http/Controllers/Api/V1/NoteController', 'Policies/NotePolicy'] as $path) {
        $code = file_get_contents($this->sandbox.'/app/'.$path.'.php');
        expect($code)->not->toMatch('/function (create|update|delete|store|destroy)\(/', $path)->not->toContain('Create')->not->toContain('JsonResponse');
    }
    $routes = file_get_contents($this->sandbox.'/routes/api.php');
    expect($routes)->toContain("notes.index")->toContain("notes.show")->not->toContain("notes.store")->not->toContain("notes.update")->not->toContain("notes.destroy");
    foreach ($this->phpFiles() as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
        expect($code)->toBe(0, $file.' '.implode("\n", $out));
    }
});

it('--actions=index: find нет ни в репозитории, ни в сервисе, ни в контроллере', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required', '--actions' => 'index'])->assertSuccessful();

    foreach (['Services/NoteService', 'Repositories/NoteRepository', 'Http/Controllers/Api/V1/NoteController', 'Policies/NotePolicy'] as $path) {
        expect(file_get_contents($this->sandbox.'/app/'.$path.'.php'))->not->toContain('function find')->not->toContain('function view(');
    }
});

it('QueryBuilder только по --builder', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required'])->assertSuccessful();
    expect(glob($this->sandbox.'/app/Builders/*'))->toBe([])
        ->and(file_get_contents($this->sandbox.'/app/Repositories/NoteRepository.php'))->not->toContain('QueryBuilder');

    $this->artisan('make:entity', ['name' => 'Memo', '--fields' => 'body:text:required', '--builder' => true])->assertSuccessful();
    expect(file_exists($this->sandbox.'/app/Builders/MemoQueryBuilder.php'))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/app/Repositories/MemoRepository.php'))->toContain('MemoQueryBuilder');
});

it('без действий или с неизвестным действием — отказ', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text', '--actions' => 'index,fly'])->assertFailed();
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text', '--actions' => ','])->assertFailed();
});

it('--test-first создаёт только тест', function (): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required', '--test-first' => true])->assertSuccessful();

    expect(file_exists($this->sandbox.'/tests/Feature/Notes/NoteTest.php'))->toBeTrue()
        ->and(file_exists($this->sandbox.'/app/Models/Note.php'))->toBeFalse();
});

it('читает описание из YAML и печатает промпт', function (): void {
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => FIELDS])->assertSuccessful();
    $this->artisan('entity:prompt', ['name' => 'Invoice'])->expectsOutputToContain('## Поля')->assertSuccessful();

    exec('rm -rf '.escapeshellarg($this->sandbox.'/app'));
    $this->artisan('make:entity', ['--from' => 'entities/Invoice.yaml'])->assertSuccessful();
    expect(file_exists($this->sandbox.'/app/Models/Invoice.php'))->toBeTrue();
});

it('make:entity-field --add вставляет поле по якорям и в описание', function (): void {
    $this->artisan('make:entity', ['name' => 'Invoice', '--fields' => 'number:string:required'])->assertSuccessful();
    $this->artisan('make:entity-field', ['name' => 'Invoice', '--add' => 'paid_at:datetime'])->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/app/Models/Invoice.php'))->toContain('@property \Illuminate\Support\Carbon|null $paid_at')->toContain("'paid_at',")
        ->and(file_get_contents($this->sandbox.'/app/Http/Resources/InvoiceResource.php'))->toContain("'paid_at'")
        ->and(file_get_contents($this->sandbox.'/entities/Invoice.yaml'))->toContain('paid_at: datetime')
        ->and(glob($this->sandbox.'/database/migrations/*_add_paid_at_to_invoices_table.php'))->toHaveCount(1);
    foreach ($this->phpFiles() as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
        expect($code)->toBe(0, $file.' '.implode("\n", $out));
    }
    $this->artisan('make:entity-field', ['name' => 'Invoice', '--add' => 'paid_at:datetime'])->assertFailed();
});

it('предупреждает, когда слой пакета тянет запрещённый импорт', function (): void {
    config()->set('entity-generator.targets.pkg', [
        'root' => 'packages/x/src', 'namespace' => 'Vendor\\X', 'layout' => 'laravel', 'migrations' => 'packages/x/database/migrations',
        'factories' => 'packages/x/database/factories', 'factory_namespace' => 'Vendor\\X\\Factories', 'tests' => 'tests/Feature',
        'forbid_imports' => ['App\\'], 'allow_imports' => ['App\\Models\\User'],
    ]);
    config()->set('entity-generator.user_model', 'App\\Models\\Person');
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text', '--package' => 'pkg', '--owner' => 'user', '--dry-run' => true])
        ->expectsOutputToContain('нарушает правило цели')->assertSuccessful();
});

it('любой набор действий даёт валидный PHP', function (string $actions, string $owner, bool $builder): void {
    $this->artisan('make:entity', ['name' => 'Note', '--fields' => 'body:text:required', '--owner' => $owner, '--actions' => $actions, '--builder' => $builder, '--routes' => true])->assertSuccessful();

    foreach ($this->phpFiles() as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
        expect($code)->toBe(0, $file.' '.implode("\n", $out));
    }
})->with(function (): iterable {
    foreach (['index', 'show', 'store', 'update', 'destroy', 'destroy,store', 'index,update', 'show,update,destroy'] as $actions) {
        foreach (['none', 'user'] as $owner) {
            yield "$actions/$owner" => [$actions, $owner, $owner === 'user'];
        }
    }
});
