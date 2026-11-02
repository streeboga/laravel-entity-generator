# streeboga/laravel-entity-generator

`make:entity` — creates every file of an entity from stubs by text substitution, so the agent spends tokens on logic only.

```bash
composer require --dev streeboga/laravel-entity-generator
php artisan vendor:publish --tag=entity-generator-config   # config/entity-generator.php
php artisan vendor:publish --tag=entity-generator-stubs    # stubs/entity/*.stub (project stubs win over package stubs)

php artisan make:entity Invoice --preset=public --owner=user \
  --fields="number:string:required:unique,total:money:required,status:enum(draft,paid)" --routes
php artisan make:entity --from=entities/Invoice.yaml          # description is stored as YAML and is the single source
php artisan make:entity Invoice --test-first                  # only the failing Feature test
php artisan make:entity-field Invoice --add="due_at:date"      # migration + insertion at `@gen:` anchors
php artisan entity:prompt Invoice                              # Markdown for a frontend agent
```

No dead code: a generated method with no caller or route does not exist.
`--actions=index,show,store,update,destroy` (YAML key `actions:`; default — all five) limits controller, service, repository, routes,
requests, DTOs, policy and test to the listed actions: no `store`/`update` — no requests, DTOs, `create`/`update`; no `destroy` — no `delete`;
`find` only if `show`, `update` or `destroy` is left. The `readonly` preset is `index,show`.
The QueryBuilder is not created by default (the repository holds plain `where`); `--builder` or `builder: true` adds it (requires `index`).

Everything is config: targets (app, a package, a domain: `--target|--package|--module|--domain`), layouts (`laravel`, `ddd`, your own),
layers, owners, presets, field types, id types. Nothing is overwritten without `--force`. `--dry-run` writes nothing.
Stubs: `{{key}}`, own-line sections `{{#flag}}…{{/flag}}` / `{{^flag}}…{{/flag}}`; `@gen:` comments are anchors for `make:entity-field`.
Targets may forbid imports (`forbid_imports`) — the command warns when a generated file would break the rule.

License: MIT.
