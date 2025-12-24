<?php
declare(strict_types=1);

/**
 * db_config.php (PDO version)
 * - No credentials should be committed to Git.
 * - Put real credentials in config.local.php (ignored) OR environment variables.
 */

function load_db_config(): array {
    // 1) Local config file (recommended for dev + AlwaysData if you prefer)
    $localPath = __DIR__ . '/config.local.php';
    if (file_exists($localPath)) {
        $cfg = require $localPath;
        if (!is_array($cfg)) {
            throw new RuntimeException("config.local.php must return an array.");
        }
        return $cfg;
    }

    // 2) Environment variables (recommended for production / AlwaysData)
    $host = getenv('DB_HOST') ?: '';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';

    if ($host && $name && $user) {
        return [
            'host' => $host,
            'dbname' => $name,
            'user' => $user,
            'pass' => $pass,
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ];
    }

    // If neither exists, fail with a clear message
    throw new RuntimeException(
        "Database config missing. Create config.local.php OR set DB_HOST, DB_NAME, DB_USER, DB_PASS env vars."
    );
}

function connect_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = load_db_config();

    $charset = $cfg['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch assoc arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepares
    ];

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    return $pdo;
}

/**
 * Fetch lookup values for form controls.
 * Uses a whitelist so table/column injection is impossible.
 */
function fetch_lookup_data(PDO $pdo, string $tableName, string $idColumn, string $valueColumn): array {
    $whitelist = [
        'RoadType'    => ['idRoadType', 'roadType'],
        'WeatherType' => ['idWeatherType', 'weatherType'],
        'Parking'     => ['idParking', 'parkingType'],
        'Emergency'   => ['idEmergency', 'emergencyType'],
    ];

    if (!isset($whitelist[$tableName])) {
        throw new InvalidArgumentException("Table not allowed: {$tableName}");
    }
    if (!in_array($idColumn, $whitelist[$tableName], true) || !in_array($valueColumn, $whitelist[$tableName], true)) {
        throw new InvalidArgumentException("Columns not allowed for {$tableName}");
    }

    $sql = "SELECT {$idColumn}, {$valueColumn} FROM {$tableName} ORDER BY {$valueColumn} ASC";
    return $pdo->query($sql)->fetchAll();
}
