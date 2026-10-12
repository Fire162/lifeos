<?php
declare(strict_types=1);

// Run only against an explicitly supplied disposable local MySQL port.
$port = (int) ($argv[1] ?? 0);
if ($port < 1024 || $port > 65535) throw new RuntimeException('Supply a dedicated local MySQL test port.');
$server = new PDO('mysql:host=127.0.0.1;port=' . $port, 'root', getenv('LIFEOS_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixture = sys_get_temp_dir() . '/lifeos_migration_' . bin2hex(random_bytes(6));
mkdir($fixture);
copy(dirname(__DIR__) . '/db.php', $fixture . '/db.php');
$fixtureConfig = ['db_host' => '127.0.0.1', 'db_name' => 'unused', 'db_user' => 'root', 'db_password' => '', 'username' => bin2hex(random_bytes(8)), 'password' => bin2hex(random_bytes(32))];
file_put_contents($fixture . '/config.php', '<?php return ' . var_export($fixtureConfig, true) . ';');
require $fixture . '/db.php';
require dirname(__DIR__) . '/lib/schema.php';
$databases = [];
$checks = 0;
function check(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function database(): PDO {
    global $server, $databases, $port;
    $name = 'lifeos_migration_test_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4'); $databases[] = $name;
    return new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $name, 'root', getenv('LIFEOS_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
try {
    $db = database();
    check(life_os_migrate($db) === 2, 'Fresh database receives both migrations');
    check((int) $db->query('SELECT COUNT(*) FROM habits')->fetchColumn() === 3, 'First setup seeds defaults');
    $db->exec('DELETE FROM habits');
    check(life_os_migrate($db) === 0, 'Current schema skips all DDL');
    check((int) $db->query('SELECT COUNT(*) FROM habits')->fetchColumn() === 0, 'Empty habits stay empty');
    $db->exec("INSERT INTO app_state VALUES ('preserve', '{\"unknown\":{}}', CURRENT_TIMESTAMP)");
    $hash = $db->query('SELECT password_hash FROM app_credentials')->fetchColumn();
    // Simulate an existing installation that has no migration history.
    $db->exec('DROP TABLE schema_migrations');
    file_put_contents($fixture . '/config.php', "<?php return ['db_host'=>'127.0.0.1','db_name'=>'unused','db_user'=>'root','db_password'=>''];");
    check(life_os_migrate($db) === 2, 'Unversioned installation upgrades without bootstrap password');
    check($db->query('SELECT password_hash FROM app_credentials')->fetchColumn() === $hash, 'Credentials preserved');
    check($db->query("SELECT state_value FROM app_state WHERE state_key='preserve'")->fetchColumn() === '{"unknown":{}}', 'State bytes preserved');
    check((int) $db->query('SELECT COUNT(*) FROM habits')->fetchColumn() === 0, 'Existing empty installation not seeded');
    $db->exec('DELETE FROM schema_migrations WHERE version=2');
    $db->exec('DROP TABLE login_throttle');
    check(life_os_migrate($db) === 1, 'Prototype version one upgrades');
    check((int) $db->query('SELECT COUNT(*) FROM login_throttle')->fetchColumn() === 0, 'Throttle table restored by upgrade');
    $db->exec('INSERT INTO schema_migrations (version) VALUES (99)');
    try { life_os_migrate($db); throw new LogicException('Future schema accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'newer'), 'Future schemas rejected'); }
    echo "Passed $checks MySQL migration checks\n";
} finally {
    foreach ($databases as $name) $server->exec('DROP DATABASE `' . $name . '`');
    unlink($fixture . '/db.php'); unlink($fixture . '/config.php'); rmdir($fixture);
}
