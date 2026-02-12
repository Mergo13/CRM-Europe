<?php
declare(strict_types=1);

/**
 * InventoryService
 * Lightweight inventory (Lagerverwaltung) using movement-based stock.
 *
 * Tables (auto-created best-effort on first use):
 * - lager (warehouses)
 * - lagerbestand (optional snapshot/min level per product per lager)
 * - lager_bewegungen (atomic movements)
 *
 * Movement types:
 *  - EINGANG        (+qty)
 *  - AUSGANG        (-qty)
 *  - RESERVIERUNG   (reserved, not part of free stock)
 *  - KORREKTUR      (+/- qty)
 *
 * getStock() returns an array with: total, reserved, free for a product (and optional lager).
 *
 * Data integrity:
 *  - Uses transactions only at caller level. Methods expect an active PDO connection (same transaction scope).
 *  - Prevent negative free stock if configured (CRMConfig::$inventory['prevent_negative_stock']).
 */
class InventoryService
{
    public const TYPE_IN  = 'EINGANG';
    public const TYPE_OUT = 'AUSGANG';
    public const TYPE_RES = 'RESERVIERUNG';
    public const TYPE_COR = 'KORREKTUR';

    private PDO $pdo;

    /** @var array{prevent_negative_stock:bool, default_warehouse_id:int|min-string|null} */
    private array $cfg;

    /**
     * Ensure we only attempt schema creation once per request/process.
     */
    private static bool $schemaEnsuredOnce = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // Avoid running DDL while a transaction is active (would cause implicit commit in MySQL)
        if (!self::$schemaEnsuredOnce && !$this->pdo->inTransaction()) {
            $this->ensureSchema();
            self::$schemaEnsuredOnce = true;
        }
        $this->cfg = $this->loadConfig();
    }

    private function loadConfig(): array
    {
        // Safe defaults if CRMConfig not available
        $prevent = false;
        $defaultWid = 1;
        if (class_exists('CRMConfig') && property_exists('CRMConfig', 'inventory') && is_array(CRMConfig::$inventory ?? null)) {
            $prevent = (bool)(CRMConfig::$inventory['prevent_negative_stock'] ?? false);
            $defaultWid = (int) (CRMConfig::$inventory['default_warehouse_id'] ?? 1);
        }
        return [
            'prevent_negative_stock' => $prevent,
            'default_warehouse_id'   => $defaultWid,
        ];
    }

    /** Create tables if missing (best-effort, MySQL and SQLite minimal support). */
    private function ensureSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // In SQLite, INTEGER PRIMARY KEY provides auto-increment behavior implicitly
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lager (id INTEGER PRIMARY KEY, name TEXT NOT NULL, code TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT)");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lagerbestand (id INTEGER PRIMARY KEY, lager_id INTEGER NOT NULL, produkt_id INTEGER NOT NULL, min_bestand REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT)");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lager_bewegungen (id INTEGER PRIMARY KEY, lager_id INTEGER NOT NULL, produkt_id INTEGER NOT NULL, typ TEXT NOT NULL, menge REAL NOT NULL, bezug_tabelle TEXT NULL, bezug_id INTEGER NULL, bemerkung TEXT NULL, created_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lager (\n              id INT AUTO_INCREMENT PRIMARY KEY,\n              name VARCHAR(191) NOT NULL,\n              code VARCHAR(64) NULL,\n              created_by BIGINT NULL,\n              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n              updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n              deleted_at DATETIME NULL\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lagerbestand (\n              id INT AUTO_INCREMENT PRIMARY KEY,\n              lager_id INT NOT NULL,\n              produkt_id INT NOT NULL,\n              min_bestand DECIMAL(18,3) NULL DEFAULT 0,\n              created_by BIGINT NULL,\n              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n              updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n              deleted_at DATETIME NULL,\n              KEY idx_lb_lager_prod (lager_id, produkt_id),\n              CONSTRAINT fk_lb_lager FOREIGN KEY (lager_id) REFERENCES lager(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS lager_bewegungen (\n              id BIGINT AUTO_INCREMENT PRIMARY KEY,\n              lager_id INT NOT NULL,\n              produkt_id INT NOT NULL,\n              typ ENUM('EINGANG','AUSGANG','RESERVIERUNG','KORREKTUR') NOT NULL,\n              menge DECIMAL(18,3) NOT NULL,\n              bezug_tabelle VARCHAR(64) NULL,\n              bezug_id BIGINT NULL,\n              bemerkung TEXT NULL,\n              created_by BIGINT NULL,\n              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n              KEY idx_mov_lager_prod (lager_id, produkt_id),\n              KEY idx_mov_ref (bezug_tabelle, bezug_id),\n              CONSTRAINT fk_mov_lager FOREIGN KEY (lager_id) REFERENCES lager(id) ON DELETE RESTRICT\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        // Ensure a default warehouse exists (id=1 best-effort)
        try {
            $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM lager")->fetchColumn();
            if ($cnt === 0) {
                $stmt = $this->pdo->prepare("INSERT INTO lager (name, code) VALUES (?, ?)");
                $stmt->execute(['Hauptlager', 'MAIN']);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * Add a movement. Quantity is absolute (positive). Type determines sign.
     * Returns inserted movement id.
     */
    public function addMovement(int $produktId, float $menge, string $typ, ?int $lagerId = null, ?string $refTable = null, ?int $refId = null, ?string $note = null, ?int $userId = null): int
    {
        if ($menge <= 0 || $produktId <= 0) { return 0; }
        $lagerId = $lagerId ?: $this->cfg['default_warehouse_id'];
        $typ = strtoupper($typ);
        if (!in_array($typ, [self::TYPE_IN, self::TYPE_OUT, self::TYPE_RES, self::TYPE_COR], true)) {
            throw new InvalidArgumentException('Unbekannter Bewegungstyp: ' . $typ);
        }

        if ($this->cfg['prevent_negative_stock'] && in_array($typ, [self::TYPE_OUT, self::TYPE_RES], true)) {
            $stock = $this->getStock($produktId, $lagerId);
            $free = (float)$stock['free'];
            if ($typ === self::TYPE_OUT && $menge > $free) {
                throw new RuntimeException('Nicht genügend Lagerbestand (frei) für AUSGANG: benötigt ' . $menge . ', verfügbar ' . $free);
            }
            if ($typ === self::TYPE_RES && $menge > $free) {
                throw new RuntimeException('Nicht genügend Lagerbestand (frei) für RESERVIERUNG: benötigt ' . $menge . ', verfügbar ' . $free);
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO lager_bewegungen (lager_id, produkt_id, typ, menge, bezug_tabelle, bezug_id, bemerkung, created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$lagerId, $produktId, $typ, $menge, $refTable, $refId, $note, $userId]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Convert existing reservation movements for a reference (table+id) into OUT movements.
     * If no reservations exist, it's a no-op. Returns number of converted rows.
     */
    public function convertReservationToOut(string $refTable, int $refId, ?int $lagerId = null): int
    {
        $lagerId = $lagerId ?: $this->cfg['default_warehouse_id'];
        // Fetch reservations
        $sel = $this->pdo->prepare("SELECT id, produkt_id, menge FROM lager_bewegungen WHERE typ = 'RESERVIERUNG' AND bezug_tabelle = ? AND bezug_id = ? AND lager_id = ?");
        $sel->execute([$refTable, $refId, $lagerId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        $converted = 0;
        foreach ($rows as $r) {
            $this->addMovement((int)$r['produkt_id'], (float)$r['menge'], self::TYPE_OUT, $lagerId, $refTable, $refId, 'Autom. aus Reservierung');
            $del = $this->pdo->prepare("DELETE FROM lager_bewegungen WHERE id = ?");
            $del->execute([(int)$r['id']]);
            $converted++;
        }
        return $converted;
    }

    /**
     * Release reservations for a reference (e.g., reject/cancel offer).
     */
    public function releaseReservation(string $refTable, int $refId, ?int $lagerId = null): int
    {
        $lagerId = $lagerId ?: $this->cfg['default_warehouse_id'];
        $del = $this->pdo->prepare("DELETE FROM lager_bewegungen WHERE typ = 'RESERVIERUNG' AND bezug_tabelle = ? AND bezug_id = ? AND lager_id = ?");
        $del->execute([$refTable, $refId, $lagerId]);
        return $del->rowCount();
    }

    public function reserveStock(int $produktId, float $menge, ?int $lagerId = null, ?string $refTable = null, ?int $refId = null, ?string $note = null, ?int $userId = null): int
    {
        return $this->addMovement($produktId, $menge, self::TYPE_RES, $lagerId, $refTable, $refId, $note, $userId);
    }

    /**
     * Calculate stock numbers for a product.
     * Returns [total, reserved, free].
     */
    public function getStock(int $produktId, ?int $lagerId = null): array
    {
        $lagerId = $lagerId ?: $this->cfg['default_warehouse_id'];

        $sumIn = $this->sumByTypes($produktId, $lagerId, [self::TYPE_IN])
               + $this->sumByTypes($produktId, $lagerId, [self::TYPE_COR], true);
        $sumOut = $this->sumByTypes($produktId, $lagerId, [self::TYPE_OUT])
               + $this->sumByTypes($produktId, $lagerId, [self::TYPE_COR], false);
        $reserved = $this->sumByTypes($produktId, $lagerId, [self::TYPE_RES]);

        $total = $sumIn - $sumOut;
        $free = $total - $reserved;
        return [
            'total' => (float)$total,
            'reserved' => (float)$reserved,
            'free' => (float)$free,
        ];
    }

    /** Sum movements for a set of types. For KORREKTUR, $positive=true counts positive amounts; false counts negative. */
    private function sumByTypes(int $produktId, int $lagerId, array $types, ?bool $korrekturPositive = null): float
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = [$lagerId, $produktId, ...$types];
        $sql = "SELECT COALESCE(SUM(menge),0) AS s FROM lager_bewegungen WHERE lager_id = ? AND produkt_id = ? AND typ IN ($placeholders)";
        if ($korrekturPositive !== null) {
            $sql .= $korrekturPositive ? " AND (typ != 'KORREKTUR' OR (typ='KORREKTUR' AND menge > 0))" : " AND (typ != 'KORREKTUR' OR (typ='KORREKTUR' AND menge < 0))";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
}
