<?php
// Startup helper for the container entrypoint (not part of the application):
//   php erp-db-check.php ping   -> exit 0 once MySQL accepts the app's credentials
//   php erp-db-check.php roles  -> prints how many roles exist (0 = brand-new database)
// Uses TLS when MYSQL_ATTR_SSL_CA is set, as Azure Database for MySQL requires.
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
if (getenv('MYSQL_ATTR_SSL_CA')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = getenv('MYSQL_ATTR_SSL_CA');
}
$dsn = 'mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 3306);
try {
    if (($argv[1] ?? 'ping') === 'roles') {
        $db = new PDO($dsn.';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), $options);
        echo $db->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    } else {
        new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), $options);
    }
} catch (Throwable $e) {
    if (getenv('ERP_DB_CHECK_VERBOSE')) {
        fwrite(STDERR, $e->getMessage().PHP_EOL);
    }
    exit(1);
}
