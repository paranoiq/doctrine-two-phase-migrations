<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\DriverManager;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use function sys_get_temp_dir;

class MigrationServiceTest extends TestCase
{

    public function testInitGenerationExecution(): void
    {
        $sql1 = 'CREATE TABLE sample (id INT NOT NULL)';
        $sql2 = 'INSERT INTO sample VALUES (1)';
        $migrationsDir = sys_get_temp_dir() . '/migrations';
        FileSystem::delete($migrationsDir);
        FileSystem::createDir($migrationsDir);

        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $service = new MigrationService($connection, $migrationsDir);

        $service->initializeMigrationTable();

        self::assertSame([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([], $service->getPreparedVersions());

        $generatedFile = $service->generateMigrationFile([$sql1, $sql2]);
        $generatedVersion = $generatedFile->getVersion();
        $generatedContents = FileSystem::read($generatedFile->getFilePath());

        require $generatedFile->getFilePath();

        self::assertStringContainsString($sql1, $generatedContents);
        self::assertStringContainsString($sql2, $generatedContents);

        self::assertSame([], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());

        $service->executeMigration($generatedVersion, MigrationPhase::BEFORE);

        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertSame([['id' => '1']], $connection->executeQuery('SELECT * FROM sample')->fetchAll());

        $service->executeMigration($generatedVersion, MigrationPhase::AFTER);

        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::BEFORE));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getExecutedVersions(MigrationPhase::AFTER));
        self::assertSame([$generatedVersion => $generatedVersion], $service->getPreparedVersions());
        self::assertSame([['id' => '1']], $connection->executeQuery('SELECT * FROM sample')->fetchAll());
    }

}
