<?php

namespace app\service;

use think\facade\Db;

/** Additive, resumable upgrade for existing installations. */
final class QrcodeMigration
{
    private const VERSION_KEY = 'schema_multi_qr_v1';
    private const COLUMNS = [
        'pay_qrcode' => [
            'qr_kind' => "VARCHAR(16) NOT NULL DEFAULT 'normal'",
            'amount_mode' => "VARCHAR(16) NOT NULL DEFAULT 'fixed'",
            'image_path' => 'VARCHAR(255) NULL',
            'image_hash' => 'CHAR(64) NULL',
            'name' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'sort_order' => 'INT NOT NULL DEFAULT 0',
        ],
        'pay_order' => ['qr_options' => 'LONGTEXT NULL'],
    ];

    public static function table(string $name): string
    {
        $connection = (string) config('database.default', 'mysql');
        $prefix = (string) config('database.connections.' . $connection . '.prefix', '');
        return '`' . str_replace('`', '``', $prefix . $name) . '`';
    }

    public static function status(): array
    {
        $missing = [];
        foreach (self::COLUMNS as $table => $definitions) {
            $columns = array_column(Db::query('SHOW COLUMNS FROM ' . self::table($table)), 'Field');
            foreach ($definitions as $column => $definition) {
                if (!in_array($column, $columns, true)) $missing[] = $table . '.' . $column;
            }
        }
        $complete = (string) Db::name('setting')->where('vkey', self::VERSION_KEY)->value('vvalue') === '1';
        return ['ready' => $complete && $missing === [], 'missing' => $missing];
    }

    public static function ready(): bool
    {
        return (string) Db::name('setting')->where('vkey', self::VERSION_KEY)->value('vvalue') === '1';
    }

    public static function upgrade(): array
    {
        // DDL commits implicitly in MySQL; each column must be independently resumable.
        $lockName = 'vmq_qr_upgrade_' . substr(hash('sha256', self::table('pay_qrcode')), 0, 32);
        $lock = Db::query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock[0]['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('另一个升级正在执行，请稍后重试');
        }
        try {
            foreach (self::COLUMNS as $table => $definitions) {
                $columns = array_column(Db::query('SHOW COLUMNS FROM ' . self::table($table)), 'Field');
                foreach ($definitions as $column => $definition) {
                    if (!in_array($column, $columns, true)) {
                        Db::execute('ALTER TABLE ' . self::table($table) . ' ADD COLUMN `' . $column . '` ' . $definition);
                    }
                }
            }
            Db::transaction(function (): void {
                QrcodeCatalog::lock();
                if (self::ready()) return;
                foreach ([1 => 'wxpay', 2 => 'zfbpay'] as $type => $key) {
                    $url = trim((string) Db::name('setting')->where('vkey', $key)->value('vvalue'));
                    if ($url === '') continue;
                    $exists = Db::name('pay_qrcode')->where('type', $type)
                        ->where('amount_mode', 'input')->where('pay_url', $url)->find();
                    if (!$exists) {
                        Db::name('pay_qrcode')->insert([
                            'type' => $type, 'price' => 0, 'pay_url' => $url, 'state' => 0,
                            'amount_mode' => 'input', 'qr_kind' => 'normal', 'name' => '原默认收款码',
                        ]);
                    }
                }
                Db::execute('INSERT INTO ' . self::table('setting')
                    . ' (vkey,vvalue) VALUES (?,?) ON DUPLICATE KEY UPDATE vvalue=VALUES(vvalue)',
                    [self::VERSION_KEY, '1']);
            });
            return self::status();
        } finally {
            Db::query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
