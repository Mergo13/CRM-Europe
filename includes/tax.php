<?php
// includes/tax.php — Central VAT/Reverse Charge resolver

declare(strict_types=1);

if (!function_exists('tax_eu_countries')) {
    function tax_eu_countries(): array {
        return [
            'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'
        ];
    }
}

if (!function_exists('tax_is_eu')) {
    function tax_is_eu(?string $cc): bool {
        if (!$cc) return false;
        return in_array(strtoupper($cc), tax_eu_countries(), true);
    }
}

if (!function_exists('tax_decide_mode_for_client')) {
    /**
     * Decide tax mode in a simple, explicit way (no VIES/network calls).
     * Priority:
     * 1) client['tax_mode'] if provided ('eu_reverse_charge' or 'standard_vat')
     * 2) client['reverse_charge'] truthy → 'eu_reverse_charge'
     * 3) otherwise 'standard_vat'
     */
    function tax_decide_mode_for_client(?array $client): string {
        if (!$client) return 'standard_vat';
        $explicit = strtolower((string)($client['tax_mode'] ?? ''));
        if ($explicit === 'eu_reverse_charge') return 'eu_reverse_charge';
        if ($explicit === 'standard_vat') return 'standard_vat';
        $rc = $client['reverse_charge'] ?? $client['rc'] ?? null;
        if (!empty($rc) && ($rc === true || $rc === 1 || $rc === '1' || strtolower((string)$rc) === 'yes')) {
            return 'eu_reverse_charge';
        }
        return 'standard_vat';
    }
}

if (!function_exists('tax_legal_text')) {
    function tax_legal_text(string $mode): ?string {
        if ($mode === 'eu_reverse_charge') {
            return 'Steuerschuldnerschaft des Leistungsempfängers gemäß Art. 196 MwSt-RL (Reverse Charge).';
        }
        return null;
    }
}
