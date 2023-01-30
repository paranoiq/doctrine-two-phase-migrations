## Two-phase migrations for Doctrine
This lightweight library allows you to perform safer Doctrine migrations during deployment in cluster-based environments like kubernetes where rolling-update takes place.
Each migration has two *up* phases, no *down* phase.

- **before**
  - to be called before any traffic hits the new application version
  - typically contains ADD COLUMN etc.
- **after**
  - to be called after the deployment is done and no traffic is hitting the old application version
  - typically contains DROP COLUMN etc.

### Installation:

```sh
composer require shipmonk/doctrine-two-phase-migrations
```

### Configuration in symfony application:

If your `Doctrine\ORM\EntityManagerInterface` is autowired, just register few services in your DIC and tag the commands:
```yml
_instanceof:
    Symfony\Component\Console\Command\Command:
        tags:
            - console.command

services:
    ShipMonk\Doctrine\Migration\Command\MigrationInitCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationRunCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand:
    ShipMonk\Doctrine\Migration\MigrationService:
    ShipMonk\Doctrine\Migration\MigrationConfig:
        $migrationsDir: "%kernel.project_dir%/migrations"

    # more optional parameters:
        $migrationClassNamespace: 'YourCompany\Migrations'
        $migrationTableName: 'doctrine_migration'
        $migrationClassPrefix: 'Migration' # will be appended with date('YmDHis') by default
        $excludedTables: ['my_tmp_table'] # migration table ($migrationTableName) is always added to excluded tables automatically
        $templateFilePath: "%kernel.project_dir%/migrations/my-template.txt" # customizable according to your coding style
        $templateIndent: "\t\t" # defaults to spaces
```

### Commands:

#### Initialization:

After installation, you need to create `migration` table in your database. It is safe to run it even when the table was already initialized.

```bash
bin/console migration:init
```

#### Status verification:

You can check awaiting migrations and entity sync status:

```bash
bin/console migration:check
```

#### Generating new migration:

You can generate migration from database <=> entity diff automatically.
This puts all the queries generated by Doctrine to before stage, which will NOT be correct for any destructive actions.
Be sure to verify the migration and move the queries to proper stage or adjust them.
When no diff is detected, empty migration class is generated.

```bash
bin/console migration:generate
```

#### Skipping all migrations:

You can also mark all migrations as already executed, e.g. when you just created fresh schema from entities.
This will mark all not executed migrations in all stages as migrated.

```bash
bin/console migration:skip
```

#### Executing migration:

Execution is performed without any interaction and does not fail nor warn when no migration is present for execution.
Just be aware that those queries are not wrapped in transaction like it happens in `doctrine/migrations`.

```bash
bin/console migration:run before
bin/console migration:run after
```

When executing all the migrations (e.g. in test environment) you probably want to achieve one-by-one execution. You can do that by:

```bash
bin/console migration:run both
```

### Advanced usage

#### Run custom code for each executed query:

You can hook into migration execution by implementing `MigrationExecutor` interface and registering your implementations as a service.
Implement `executeQuery()` to run checks or other code before/after each query.
Interface of this method mimics interface of `Doctrine\DBAL\Connection::executeQuery()`.

#### Run all queries within transaction:

Each generated migration contains method specifying if it will be executed within transaction or not.
You can easily change that according your needs.
By default, transactional execution is enabled only for plaforms supporting DDL operations within transaction (PostgreSQL, SQLServer).

### Differences from doctrine/migrations

This library is aiming to provide only core functionality needed for safe migrations within rolling-update deployments.
We try to keep it as lightweight as possible, we do not plan to copy features from doctrine/migrations.

