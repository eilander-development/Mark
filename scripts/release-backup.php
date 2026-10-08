<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$backend = $argv[1];
$destination = $argv[2];
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! config('app.key')) {
    throw new RuntimeException('Bestaande APP_KEY ontbreekt; geen nieuwe productiesleutel aanmaken.');
}
$connections = explode(',', getenv('DEPLOY_DB_CONNECTIONS') ?: config('database.default'));
$prefix = getenv('DEPLOY_TABLE_PREFIX') ?: '';
$exclude = getenv('DEPLOY_EXCLUDE_PREFIX') ?: '';
$expectedDatabase = getenv('DEPLOY_EXPECTED_DATABASE') ?: '';
foreach ($connections as $connection) {
    if (! preg_match('/^[a-z0-9_]+$/D', $connection)) {
        throw new RuntimeException('Ongeldige back-upverbinding.');
    }
    $database = DB::connection($connection);
    if (! in_array($database->getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('Productieback-up vereist MySQL/MariaDB.');
    }
    if ($expectedDatabase !== '' && $database->getDatabaseName() !== $expectedDatabase) {
        throw new RuntimeException('Onverwachte productiedatabase.');
    }
    $pdo = $database->getPdo();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $tables = array_values(array_filter($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN), function (string $name) use ($prefix, $exclude): bool {
        return preg_match('/^[a-zA-Z0-9_]+$/D', $name)
            && ($prefix === '' || str_starts_with($name, $prefix))
            && ($exclude === '' || ! str_starts_with($name, $exclude));
    }));
    if ($tables === []) {
        throw new RuntimeException('Geen eigen tabellen gevonden; back-up afgebroken.');
    }
    $file = gzopen($destination.'/'.$connection.'.sql.gz', 'wb9');
    if ($file === false) {
        throw new RuntimeException('Databaseback-up openen mislukt.');
    }
    $write = function (string $sql) use ($file): void {
        if (gzwrite($file, $sql) !== strlen($sql)) {
            throw new RuntimeException('Databaseback-up schrijven mislukt.');
        }
    };
    $write("SET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($tables as $table) {
        $definition = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $write('DROP TABLE IF EXISTS `'.$table.'`;'.PHP_EOL.$definition.';'.PHP_EOL);
        $query = $pdo->query('SELECT * FROM `'.$table.'`');
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $columns = implode(',', array_map(fn (string $key): string => '`'.$key.'`', array_keys($row)));
            $values = implode(',', array_map(fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), $row));
            $write('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.');'.PHP_EOL);
        }
    }
    $pdo->commit();
    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    if (! gzclose($file)) {
        throw new RuntimeException('Databaseback-up afsluiten mislukt.');
    }
    chmod($destination.'/'.$connection.'.sql.gz', 0600);
    echo count($tables)." eigen tabellen geback-upt.\n";
}
