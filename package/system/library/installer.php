<?php
declare(strict_types=1);

namespace Opencart\System\Library\Extension\YueiSpanishPe;

/** Admin-only lifecycle. The storefront uses OpenCart's native language loader. */
final class Installer {
    public const PACKAGE = 'yuei_spanish_pe';
    public const CODE = 'es-pe';
    private const EVENT = 'yuei_spanish_pe_guard';
    // Same content families seeded by OpenCart 4.1.0.4's addLanguage().
    private const CONTENT = [
        'attribute_description', 'attribute_group_description', 'banner_image', 'category_description',
        'country_description', 'customer_group_description', 'custom_field_description',
        'custom_field_value_description', 'download_description', 'filter_description',
        'filter_group_description', 'information_description', 'length_class_description',
        'option_description', 'option_value_description', 'order_status', 'product_description',
        'product_attribute', 'return_action', 'return_reason', 'return_status', 'stock_status',
        'weight_class_description', 'subscription_plan_description', 'subscription_status',
        'seo_url', 'topic_description', 'zone_description'
    ];
    private string $lock;

    public function __construct(private \PDO $pdo, private string $prefix) {
        if (!preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix) || strlen($prefix) > 32) {
            throw new \InvalidArgumentException('Invalid database prefix');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $query = $pdo->prepare('SELECT DATABASE()');
        $query->execute();
        $this->lock = 'es-pe:' . substr(hash('sha256', $query->fetchColumn() . ':' . $prefix), 0, 50);
    }

    public static function connect(): self {
        $pdo = new \PDO('mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8mb4',
            DB_USERNAME, DB_PASSWORD, [\PDO::ATTR_PERSISTENT => false, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false]);
        return new self($pdo, DB_PREFIX);
    }

    private function table(string $name): string {
        if (!preg_match('/\A[a-z_]+\z/D', $name)) { throw new \InvalidArgumentException('Invalid table'); }
        return '`' . $this->prefix . $name . '`';
    }

    public function language(): array {
        $query = $this->pdo->prepare('SELECT * FROM ' . $this->table('language') . ' WHERE code = ?');
        $query->execute([self::CODE]);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 1) { throw new \RuntimeException('Duplicate es-pe language records'); }
        return $rows[0] ?? [];
    }

    public function isDefault(): bool {
        $query = $this->pdo->prepare('SELECT 1 FROM ' . $this->table('setting') . ' WHERE `key` IN (?, ?, ?) AND `value` = ? LIMIT 1');
        $query->execute(['config_language_admin', 'config_language_catalog', 'config_language', self::CODE]);
        return (bool)$query->fetchColumn();
    }

    private function transaction(callable $callback): mixed {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$this->lock]);
        if ((int)$lock->fetchColumn() !== 1) { throw new \RuntimeException('Language installation is busy'); }
        try {
            $tables = array_merge(self::CONTENT, ['language', 'event', 'setting', 'extension']);
            $query = $this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            foreach ($tables as $table) {
                $query->execute([$this->prefix . $table]);
                if (strcasecmp((string)$query->fetchColumn(), 'InnoDB') !== 0) {
                    throw new \RuntimeException('InnoDB required: ' . $table);
                }
            }
            $this->pdo->beginTransaction();
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$this->lock]);
        }
    }

    public function install(int $sourceId): int {
        return $this->transaction(function () use ($sourceId): int {
            $existing = $this->language();
            if ($existing && $existing['extension'] !== self::PACKAGE) {
                throw new \DomainException('The es-pe code belongs to another language package');
            }
            if ($existing) {
                $languageId = (int)$existing['language_id'];
                $query = $this->pdo->prepare('UPDATE ' . $this->table('language') . ' SET status = 1 WHERE language_id = ?');
                $query->execute([$languageId]);
            } else {
                $source = $this->pdo->prepare('SELECT language_id FROM ' . $this->table('language') . ' WHERE language_id = ?');
                $source->execute([$sourceId]);
                if (!$source->fetchColumn()) { throw new \DomainException('Source language does not exist'); }
                $query = $this->pdo->prepare('INSERT INTO ' . $this->table('language') . ' (name, code, locale, extension, status, sort_order) VALUES (?, ?, ?, ?, 1, 1)');
                $query->execute(['Español (Perú)', self::CODE, 'es_PE.UTF-8,es_PE.utf8,es_PE,es-PE,es', self::PACKAGE]);
                $languageId = (int)$this->pdo->lastInsertId();
                foreach (self::CONTENT as $table) { $this->seed($table, $sourceId, $languageId); }
                $this->seedLanguageUrls();
            }
            $this->status('1');
            $this->registerGuards();
            // Native install inserts its extension row before calling this hook.
            // Concurrent/repeated installs must leave only one registration for this package.
            $query = $this->pdo->prepare('SELECT extension_id FROM ' . $this->table('extension') . ' WHERE extension = ? AND `type` = ? AND code = ? ORDER BY extension_id');
            $query->execute([self::PACKAGE, 'language', self::PACKAGE]);
            $ids = $query->fetchAll(\PDO::FETCH_COLUMN);
            $delete = $this->pdo->prepare('DELETE FROM ' . $this->table('extension') . ' WHERE extension_id = ?');
            foreach (array_slice($ids, 1) as $id) { $delete->execute([$id]); }
            return $languageId;
        });
    }

    private function seed(string $table, int $source, int $destination): void {
        // Identifiers come only from this allowlist and the server's own metadata.
        $query = $this->pdo->prepare('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $query->execute([$this->prefix . $table]);
        $columns = [];
        $select = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $name = $column['COLUMN_NAME'];
            if (str_contains(strtolower($column['EXTRA']), 'auto_increment')) { continue; }
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $name)) { throw new \RuntimeException('Unexpected column name'); }
            $columns[] = '`' . $name . '`';
            $select[] = $name === 'language_id' ? ':destination' : '`' . $name . '`';
        }
        if (!in_array('`language_id`', $columns, true)) { throw new \RuntimeException('Missing language_id: ' . $table); }
        $copy = $this->pdo->prepare('INSERT INTO ' . $this->table($table) . ' (' . implode(',', $columns) . ') SELECT ' . implode(',', $select) . ' FROM ' . $this->table($table) . ' WHERE language_id = :source');
        $copy->execute(['destination' => $destination, 'source' => $source]);
    }

    private function seedLanguageUrls(): void {
        $stores = $this->pdo->prepare('SELECT 0 AS store_id UNION SELECT store_id FROM ' . $this->table('store'));
        $stores->execute();
        $languages = $this->pdo->prepare('SELECT language_id FROM ' . $this->table('language'));
        $languages->execute();
        $languageIds = $languages->fetchAll(\PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare('INSERT INTO ' . $this->table('seo_url') . ' (store_id, language_id, `key`, `value`, keyword, sort_order) SELECT ?, ?, ?, ?, ?, -2 WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table('seo_url') . ' WHERE store_id = ? AND language_id = ? AND `key` = ? AND `value` = ?)');
        foreach ($stores->fetchAll(\PDO::FETCH_COLUMN) as $storeId) {
            foreach ($languageIds as $languageId) {
                $query->execute([$storeId, $languageId, 'language', self::CODE, self::CODE, $storeId, $languageId, 'language', self::CODE]);
            }
        }
    }

    public function uninstall(): void {
        $this->transaction(function (): void {
            if ($this->isDefault()) { throw new \DomainException('Change default languages before uninstalling'); }
            $query = $this->pdo->prepare('UPDATE ' . $this->table('language') . ' SET status = 0 WHERE code = ? AND extension = ?');
            $query->execute([self::CODE, self::PACKAGE]);
            $this->status('0');
            // Language ID and merchant-authored descriptions remain available for a reinstall.
            // The package-removal guard remains until the merchant explicitly deletes the language.
        });
    }

    private function status(string $status): void {
        $delete = $this->pdo->prepare('DELETE FROM ' . $this->table('setting') . ' WHERE code = ?');
        $delete->execute(['language_' . self::PACKAGE]);
        $insert = $this->pdo->prepare('INSERT INTO ' . $this->table('setting') . ' (store_id, code, `key`, `value`, serialized) VALUES (0, ?, ?, ?, 0)');
        $insert->execute(['language_' . self::PACKAGE, 'language_' . self::PACKAGE . '_status', $status]);
    }

    private function registerGuards(): void {
        $delete = $this->pdo->prepare('DELETE FROM ' . $this->table('event') . ' WHERE code = ?');
        $delete->execute([self::EVENT]);
        $insert = $this->pdo->prepare('INSERT INTO ' . $this->table('event') . ' (code, description, `trigger`, action, status, sort_order) VALUES (?, ?, ?, ?, 1, 0)');
        foreach (['extension/language.uninstall' => '_guardLanguage', 'marketplace/installer.uninstall' => '_guardPackage'] as $route => $method) {
            $insert->execute([self::EVENT, 'Preserve Spanish language data', 'admin/controller/' . $route . '/before', 'extension/' . self::PACKAGE . '/language/' . self::PACKAGE . '.' . $method]);
        }
    }

    public function packageIdMatches(int $id): bool {
        $query = $this->pdo->prepare('SELECT 1 FROM ' . $this->table('extension_install') . ' WHERE extension_install_id = ? AND code = ?');
        $query->execute([$id, self::PACKAGE]);
        return (bool)$query->fetchColumn();
    }

    public function cleanup(): void {
        $this->transaction(function (): void {
            if ($this->language()) { throw new \DomainException('Language must be deleted explicitly before package removal'); }
            $query = $this->pdo->prepare('DELETE FROM ' . $this->table('event') . ' WHERE code = ?');
            $query->execute([self::EVENT]);
            $query = $this->pdo->prepare('DELETE FROM ' . $this->table('setting') . ' WHERE code = ?');
            $query->execute(['language_' . self::PACKAGE]);
        });
    }
}
