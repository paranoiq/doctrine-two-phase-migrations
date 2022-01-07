<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use LogicException;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use SplFileInfo;
use function date;
use function implode;
use function ksort;
use function sprintf;
use function str_replace;

class MigrationService
{

    private Connection $connection;

    private string $migrationsDir;

    private string $migrationClassNamespace;

    private string $migrationClassPrefix;

    private bool $includeDropTableInDatabaseSync;

    private string $templateFilePath;

    private string $templateIndent;

    public function __construct(
        Connection $connection,
        string $migrationsDir,
        string $migrationClassNamespace = 'Migrations',
        string $migrationClassPrefix = 'Migration',
        bool $includeDropTableInDatabaseSync = true,
        string $templateFilePath = __DIR__ . '/template/migration.txt',
        string $templateIndent = '        ',
    )
    {
        $this->connection = $connection;
        $this->migrationsDir = $migrationsDir;
        $this->migrationClassNamespace = $migrationClassNamespace;
        $this->migrationClassPrefix = $migrationClassPrefix;
        $this->includeDropTableInDatabaseSync = $includeDropTableInDatabaseSync;
        $this->templateFilePath = $templateFilePath;
        $this->templateIndent = $templateIndent;
    }

    public function getMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    private function getMigrationClassNamespace(): string
    {
        return $this->migrationClassNamespace;
    }

    public function shouldIncludeDropTableInDatabaseSync(): bool
    {
        return $this->includeDropTableInDatabaseSync;
    }

    private function getMigrationClassPrefix(): string
    {
        return $this->migrationClassPrefix;
    }

    private function getMigration(string $version): Migration
    {
        /** @var class-string<Migration> $fqn */
        $fqn = '\\' . $this->getMigrationClassNamespace() . '\\' . $this->getMigrationClassPrefix() . $version;
        return new $fqn();
    }

    public function executeMigration(string $version, string $phase): void
    {
        $migration = $this->getMigration($version);

        if ($phase === MigrationPhase::BEFORE) {
            $migration->before($this->connection);

        } elseif ($phase === MigrationPhase::AFTER) {
            $migration->after($this->connection);

        } else {
            throw new LogicException('Invalid phase given!');
        }

        $this->markMigrationExecuted($version, $phase);
    }

    /**
     * @return array<string, string>
     */
    public function getPreparedVersions(): array
    {
        $migrations = [];

        /** @var SplFileInfo $fileinfo */
        foreach (Finder::findFiles($this->getMigrationClassPrefix() . '*.php')->in($this->migrationsDir) as $fileinfo) {
            $version = str_replace($this->getMigrationClassPrefix(), '', $fileinfo->getBasename('.php'));
            $migrations[$version] = $version;
        }

        ksort($migrations);

        return $migrations;
    }

    /**
     * @return array<string, string>
     */
    public function getExecutedVersions(string $phase): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT version FROM migration WHERE phase = :phase',
            [
                'phase' => $phase,
            ],
        );

        $versions = [];

        foreach ($result as $row) {
            /** @var string $version */
            $version = $row['version'];
            $versions[$version] = $version;
        }

        ksort($versions);

        return $versions;
    }

    public function markMigrationExecuted(string $version, string $phase): void
    {
        $this->connection->insert('migration', [
            'version' => $version,
            'phase' => $phase,
            'executed' => date('Y-m-d H:i:s'),
        ]);
    }

    public function initializeMigrationTable(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('migration');
        $table->addColumn('version', 'string', ['length' => 14]);
        $table->addColumn('phase', 'string', ['length' => 6]);
        $table->addColumn('executed', 'datetime');
        $table->setPrimaryKey(['version', 'phase']);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeQuery($sql);
        }
    }

    /**
     * @param string[] $sqls
     */
    public function generateMigrationFile(array $sqls): MigrationFile
    {
        $statements = [];

        foreach ($sqls as $sql) {
            $statements[] = sprintf("\$connection->executeQuery('%s');", str_replace("'", "\'", $sql));
        }

        $migrationsDir = $this->getMigrationsDir();
        $migrationClassPrefix = $this->getMigrationClassPrefix();
        $migrationClassNamespace = $this->getMigrationClassNamespace();

        $version = date('YmdHis');
        $template = FileSystem::read($this->templateFilePath);
        $template = str_replace('%namespace%', $migrationClassNamespace, $template);
        $template = str_replace('%version%', $version, $template);
        $template = str_replace('%statements%', implode("\n" . $this->templateIndent, $statements), $template);

        $filePath = $migrationsDir . '/' . $migrationClassPrefix . $version . '.php';
        FileSystem::createDir($migrationsDir);
        FileSystem::write($filePath, $template);

        return new MigrationFile($filePath, $version);
    }

}