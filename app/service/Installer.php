<?php
declare(strict_types=1);

namespace app\service;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Dependency-free first-run installer. It is loaded before Composer exists.
 */
final class Installer
{
    private const ENV_PLACEHOLDER = '# VMQFOX_INSTALLER_PLACEHOLDER';

    public static function status(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $vendor = is_file($root . '/vendor/autoload.php');
        $env = self::environmentIsConfigured($root . '/.env');
        $schemaFile = is_file($root . '/vmq.sql');
        $lockFile = is_file($root . '/runtime/install.lock');
        $installing = $lockFile && self::lockIsInstalling($root . '/runtime/install.lock');
        $lock = $lockFile && !$installing;
        $databaseImported = $lock || ($env && self::existingSchemaAvailable($root));
        $schema = $schemaFile && $databaseImported;
        $requirements = [
            'php' => PHP_VERSION_ID >= 80200,
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'proc_open' => function_exists('proc_open'),
        ];

        // Once the lock exists, the schema was imported by this installer.
        // Before that, avoid opening a database connection on every request.
        $missing = [];
        foreach (['vendor' => $vendor, 'env' => $env, 'schema' => $schema, 'lock' => $lock] as $name => $present) {
            if (!$present) {
                $missing[] = $name;
            }
        }
        foreach ($requirements as $name => $present) {
            if (!$present) {
                $missing[] = $name;
            }
        }

        return [
            'php_version' => PHP_VERSION,
            'vendor' => $vendor,
            'env' => $env,
            'schema' => $schema,
            'database_imported' => $databaseImported,
            'lock' => $lock,
            'installing' => $installing,
            'ready' => $vendor && $env && $schema && $lock && !in_array(false, $requirements, true),
            'requirements' => $requirements,
            'missing' => $missing,
        ];
    }

    public static function validateInput(array $input): array
    {
        $values = [
            'db_host' => trim((string)($input['db_host'] ?? '127.0.0.1')),
            'db_port' => trim((string)($input['db_port'] ?? '3306')),
            'db_name' => trim((string)($input['db_name'] ?? 'vmq')),
            'db_user' => trim((string)($input['db_user'] ?? 'root')),
            'db_password' => (string)($input['db_password'] ?? ''),
            'admin_username' => trim((string)($input['admin_username'] ?? '')),
            'admin_password' => (string)($input['admin_password'] ?? ''),
            'timezone' => trim((string)($input['timezone'] ?? 'Asia/Shanghai')),
        ];

        foreach (['db_host', 'db_name', 'db_user', 'admin_username', 'timezone'] as $field) {
            if ($values[$field] === '') {
                throw new InvalidArgumentException($field . ' cannot be empty.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $values['db_name'])) {
            throw new InvalidArgumentException('Database name contains unsupported characters.');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $values['db_user'])) {
            throw new InvalidArgumentException('Database username contains unsupported characters.');
        }
        if (!ctype_digit($values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }
        if (strlen($values['admin_username']) > 100 || strlen($values['admin_password']) < 8) {
            throw new InvalidArgumentException('Administrator password must contain at least 8 characters.');
        }
        if ($values['admin_username'] === 'admin' && $values['admin_password'] === 'admin') {
            throw new InvalidArgumentException('The insecure admin/admin credential pair is not allowed.');
        }
        if (self::isPlaceholder($values['admin_username']) || self::isPlaceholder($values['admin_password'])) {
            throw new InvalidArgumentException('Replace the example administrator credentials before installing.');
        }
        try {
            new DateTimeZone($values['timezone']);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Unknown timezone.');
        }

        return $values;
    }

    /**
     * @return array{ok:bool,message:string,redirect:string}
     */
    public static function install(array $input, string $root, ?PDO $connection = null): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ((string)($input['confirm'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm the database import before installing.');
        }
        $existing = self::configurationDefaults($root);
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'timezone', 'admin_username'] as $field) {
            if (array_key_exists($field, $existing) && isset($input[$field]) && (string)$input[$field] !== '' && (string)$input[$field] !== (string)$existing[$field]) {
                throw new InvalidArgumentException('The existing .env already defines ' . $field . '. Edit .env on the server before changing it.');
            }
        }
        foreach ($existing as $field => $value) {
            if (!isset($input[$field]) || (string)$input[$field] === '') {
                $input[$field] = $value;
            }
        }
        $values = self::validateInput($input);
        $status = self::status($root);
        if ($status['lock']) {
            throw new RuntimeException('The application is already installed.');
        }
        if (!is_file($root . '/vmq.sql')) {
            throw new RuntimeException('vmq.sql is missing from the release.');
        }

        $runtime = $root . '/runtime';
        if (!is_dir($runtime) && !mkdir($runtime, 0775, true) && !is_dir($runtime)) {
            throw new RuntimeException('Unable to create the runtime directory.');
        }

        $envPath = $root . '/.env';
        $envCreated = false;
        $envPlaceholderWritten = false;
        $envOriginal = null;
        $lockPath = $runtime . '/install.lock';
        $lockHandle = @fopen($lockPath, 'x');
        if ($lockHandle === false) {
            throw new RuntimeException('Another installation is in progress or the installation lock is not writable.');
        }
        $lockCreated = true;
        $lockData = json_encode(['state' => 'installing', 'started_at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
        fwrite($lockHandle, $lockData . PHP_EOL);
        fflush($lockHandle);
        @chmod($lockPath, 0600);
        try {
            $env = self::renderEnv($values);
            if (is_file($envPath) && self::environmentIsPlaceholder($envPath)) {
                $envOriginal = file_get_contents($envPath);
                if (!is_writable($envPath) || @file_put_contents($envPath, $env, LOCK_EX) === false) {
                    throw new RuntimeException('The environment placeholder is not writable.');
                }
                $envPlaceholderWritten = true;
                @chmod($envPath, 0600);
            } elseif (!is_file($envPath)) {
                $tmpPath = $envPath . '.tmp.' . bin2hex(random_bytes(6));
                if (@file_put_contents($tmpPath, $env, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write the environment file.');
                }
                @chmod($tmpPath, 0600);
                if (!rename($tmpPath, $envPath)) {
                    @unlink($tmpPath);
                    throw new RuntimeException('Unable to activate the environment file.');
                }
                $envCreated = true;
            }

            $pdo = $connection ?? self::connect($values);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $schemaState = self::schemaState($pdo);
            if ($schemaState === 'partial') {
                throw new RuntimeException('The database contains a partial VMQFox schema. Back it up and repair it before continuing.');
            }
            $schemaExists = $schemaState === 'complete';
            $skipImport = (string)($input['schema_action'] ?? '') === 'skip' || (string)($input['skip_schema'] ?? '') === '1';
            if ($schemaExists && !$skipImport) {
                // A fresh form cannot inspect a database before credentials are submitted.
                // Existing complete tables are therefore safely treated as an implicit skip.
                $skipImport = true;
            }
            if (!$schemaExists && $skipImport) {
                throw new InvalidArgumentException('The database schema was not detected. Leave database import enabled.');
            }
            if (!$schemaExists) {
                self::importSchema($pdo, $root . '/vmq.sql');
            }

            $user = $values['admin_username'];
            $hash = password_hash($values['admin_password'], PASSWORD_DEFAULT);
            $key = bin2hex(random_bytes(32));
            $statement = $pdo->prepare(
                'INSERT INTO setting (vkey, vvalue) VALUES (:key, :value) '
                . 'ON DUPLICATE KEY UPDATE vvalue = VALUES(vvalue)'
            );
            foreach (['user' => $user, 'pass' => $hash, 'key' => $key] as $settingKey => $settingValue) {
                $statement->execute(['key' => $settingKey, 'value' => $settingValue]);
            }

            $lockData = json_encode(['state' => 'complete', 'installed_at' => gmdate('c'), 'version' => '2026'], JSON_UNESCAPED_SLASHES);
            if (!ftruncate($lockHandle, 0) || !rewind($lockHandle) || fwrite($lockHandle, $lockData . PHP_EOL) === false) {
                fclose($lockHandle);
                @unlink($lockPath);
                throw new RuntimeException('Unable to create the installation lock.');
            }
            fflush($lockHandle);
            fclose($lockHandle);
            $lockCreated = false;
            @chmod($lockPath, 0600);

            return ['ok' => true, 'message' => 'Installation completed. You can now sign in.', 'redirect' => '/'];
        } catch (\Throwable $exception) {
            if (isset($lockHandle) && is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            if ($lockCreated) {
                @unlink($lockPath);
            }
            if ($envCreated) {
                @unlink($envPath);
            } elseif ($envPlaceholderWritten && $envOriginal !== null) {
                @file_put_contents($envPath, $envOriginal, LOCK_EX);
            }
            if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Installation failed. Check the database settings and server logs.', 0, $exception);
        }
    }

    public static function renderEnv(array $values): string
    {
        $quote = static function (string $value): string {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        };

        return implode(PHP_EOL, [
            'APP_DEBUG = false',
            'APP_TRACE = false',
            'APP_FRONTEND_URL = ' . $quote('http://localhost:3006'),
            'APP_TIMEZONE = ' . $quote($values['timezone']),
            'ADMIN_USERNAME = ' . $quote($values['admin_username']),
            'ADMIN_PASSWORD =',
            'SESSION_SECURE_COOKIE = false',
            '',
            '[DATABASE]',
            'TYPE = mysql',
            'HOSTNAME = ' . $quote($values['db_host']),
            'DATABASE = ' . $quote($values['db_name']),
            'USERNAME = ' . $quote($values['db_user']),
            'PASSWORD = ' . $quote($values['db_password']),
            'HOSTPORT = ' . $values['db_port'],
            'CHARSET = utf8mb4',
            'PREFIX =',
            'DEBUG = false',
            '',
            '[REDIS]',
            'HOST = 127.0.0.1',
            'PORT = 6379',
            'PASSWORD =',
            'SELECT = 0',
            '',
            '[CACHE]',
            'DRIVER = file',
            '',
            '[SESSION]',
            'DRIVER = file',
            '',
        ]);
    }

    /** @return array<string,string> */
    public static function configurationDefaults(string $root): array
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR) . '/.env';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $section = '';
        $parsed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($line[0] === '[' && str_ends_with($line, ']')) {
                $section = strtoupper(trim($line, '[] '));
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            $parsed[($section !== '' ? $section . '.' : '') . strtoupper($key)] = stripcslashes($value);
        }
        $map = [
            'DATABASE.HOSTNAME' => 'db_host',
            'DATABASE.HOSTPORT' => 'db_port',
            'DATABASE.DATABASE' => 'db_name',
            'DATABASE.USERNAME' => 'db_user',
            'DATABASE.PASSWORD' => 'db_password',
            'ADMIN_USERNAME' => 'admin_username',
            'ADMIN_PASSWORD' => 'admin_password',
            'APP_TIMEZONE' => 'timezone',
        ];
        $result = [];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $parsed)) {
                $result[$target] = $parsed[$source];
            }
        }
        return $result;
    }

    private static function isPlaceholder(string $value): bool
    {
        return preg_match('/^(replace-with|change-me|your[-_]|example|password)/i', $value) === 1;
    }

    private static function environmentIsConfigured(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        $contents = trim($contents);
        return $contents !== '' && $contents !== self::ENV_PLACEHOLDER;
    }

    private static function environmentIsPlaceholder(string $path): bool
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        $contents = trim($contents);
        return $contents === '' || $contents === self::ENV_PLACEHOLDER;
    }

    private static function connect(array $values): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $values['db_host'], $values['db_port'], $values['db_name']);
        try {
            return new PDO($dsn, $values['db_user'], $values['db_password'], $options);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() !== '1049') {
                throw new RuntimeException('Unable to connect to the configured database.', 0, $exception);
            }
            $server = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $values['db_host'], $values['db_port']),
                $values['db_user'],
                $values['db_password'],
                $options
            );
            $identifier = str_replace('`', '``', $values['db_name']);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $identifier . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return new PDO($dsn, $values['db_user'], $values['db_password'], $options);
        }
    }

    private static function importSchema(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read vmq.sql.');
        }
        foreach (self::splitSql($sql) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    private static function schemaExists(PDO $pdo): bool
    {
        foreach (['pay_order', 'pay_qrcode', 'setting', 'tmp_price'] as $table) {
            try {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (\Throwable $exception) {
                return false;
            }
        }
        return true;
    }

    private static function schemaState(PDO $pdo): string
    {
        $found = 0;
        foreach (['pay_order', 'pay_qrcode', 'setting', 'tmp_price'] as $table) {
            try {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
                $found++;
            } catch (\Throwable $exception) {
                // Missing table; continue so partial schemas can be distinguished.
            }
        }
        return $found === 0 ? 'missing' : ($found === 4 ? 'complete' : 'partial');
    }

    private static function lockIsInstalling(string $path): bool
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        $data = json_decode($contents, true);
        return is_array($data) && ($data['state'] ?? '') === 'installing';
    }

    private static function existingSchemaAvailable(string $root): bool
    {
        if (!extension_loaded('pdo_mysql')) {
            return false;
        }
        $values = self::configurationDefaults($root);
        foreach (['db_host', 'db_port', 'db_name', 'db_user'] as $field) {
            if (!isset($values[$field]) || $values[$field] === '') {
                return false;
            }
        }
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $values['db_host'], $values['db_port'], $values['db_name']),
                $values['db_user'],
                $values['db_password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
            );
            return self::schemaExists($pdo);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return list<string> */
    private static function splitSql(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;
            if (($char === "'" || $char === '"' || $char === '`') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote === null ? $char : $quote);
            }
            if ($char === ';' && $quote === null) {
                $parts[] = trim($buffer);
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        return $parts;
    }
}
