<?php
declare(strict_types=1);

/**
 * NumberGeneratorService
 * Centralizes number generation for offers, invoices, delivery notes.
 * Keeps backward-compatible formats used in current pages when possible.
 */
class NumberGeneratorService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Angebotsnummer: A-ddmm-#### (count per date)
    public function nextOfferNumber(string $date): string
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM angebote WHERE datum = ?');
        $stmt->execute([$date]);
        $count = (int)$stmt->fetchColumn();
        $next = $count + 1;
        return 'A-' . date('dm', strtotime($date)) . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    // Rechnungsnummer: R-YYYYMMDD-#### (max suffix per date)
    public function nextInvoiceNumber(string $date): string
    {
        $prefix = 'R-' . date('Ymd', strtotime($date)) . '-';
        $stmt = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(rechnungsnummer, -4) AS UNSIGNED)) FROM rechnungen WHERE datum = ? AND rechnungsnummer LIKE ?");
        $stmt->execute([$date, $prefix . '%']);
        $seq = ((int)($stmt->fetchColumn() ?: 0)) + 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    // Lieferscheinnummer: LS-YYYYMM-#### (max suffix per month)
    public function nextDeliveryNumber(string $date): string
    {
        $prefix = 'LS-' . date('Ym', strtotime($date)) . '-';
        $stmt = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(nummer, -4) AS UNSIGNED)) FROM lieferscheine WHERE DATE_FORMAT(datum, '%Y-%m') = DATE_FORMAT(?, '%Y-%m') AND nummer LIKE ?");
        try {
            $stmt->execute([$date, $prefix . '%']);
            $seq = ((int)($stmt->fetchColumn() ?: 0)) + 1;
            return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            // Fallback if DATE_FORMAT not available (e.g., SQLite)
            return 'LS-' . time();
        }
    }
}
