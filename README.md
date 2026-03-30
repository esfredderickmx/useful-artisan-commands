# Useful Artisan Commands

A collection of handy Laravel Artisan commands for generating actions, DTOs, and configuring databases.

Requires **PHP 8.2+** and **Laravel 12 or 13**.

## Installation

```bash
composer require esfredderick/useful-artisan-commands --dev
```

Auto-discovery registers the commands automatically.

## Commands

### `make:action`

Generates an action class in `app/Actions/`. The `Action` suffix is auto-appended.

```bash
php artisan make:action CreateUser
# -> app/Actions/CreateUserAction.php

php artisan make:action Billing/ChargeInvoice
# -> app/Actions/Billing/ChargeInvoiceAction.php
```

| Option | Description |
|---|---|
| `-d`, `--data` | Also generate a matching DTO class |
| `-f`, `--force` | Overwrite if file already exists |

### `make:data`

Generates a `final readonly` DTO class in `app/Data/`. The `Data` suffix is auto-appended.

```bash
php artisan make:data CreateUser
# -> app/Data/CreateUserData.php
```

| Option | Description |
|---|---|
| `-f`, `--force` | Overwrite if file already exists |

### `app:config-db`

Interactive prompt to configure PostgreSQL connection details in your `.env` file.

```bash
php artisan app:config-db
```

Prompts for host, port, database name, username, password, and migrations table. Supports `schema.table` notation for the migrations table.

## Customizing Stubs

Publish the stubs to customize the generated file templates:

```bash
php artisan vendor:publish --tag=useful-artisan-commands-stubs
```

This copies `action.stub` and `data.stub` to your project's `stubs/` directory. The commands will use your local stubs over the package defaults.

## License

MIT
