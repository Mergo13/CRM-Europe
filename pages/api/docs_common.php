<?php
// pages/api/docs_common.php
// Shared helpers for document APIs (rechnungen, angebote, mahnungen, lieferscheine)

declare(strict_types=1);

function doc_type_config(string $docType): array
{
    $t = strtolower($docType);
    // Default config tries to be generic and introspects
    $defaults = [
        'table' => $t,
        'pk' => 'id',
        'number_field' => in_array($t, ['rechnungen','rechnung']) ? 'rechnungsnummer' : (in_array($t,['angebote','angebot','angeboten']) ? 'angebotsnummer' : 'nummer'),
        'client_join' => [
            'join' => 'LEFT JOIN clients c ON t.client_id = c.id',
            'select' => 'c.name AS kunde, c.email AS kunde_email',
            'field' => 'kunde',
        ],
        'amount_field' => 'betrag',
        'status_field' => 'status',
        'date_field' => in_array($t, ['rechnungen','rechnung']) ? 'datum' : (in_array($t,['mahnungen','mahnung']) ? 'mahn_datum' : 'datum'),
        'due_field' => in_array($t, ['rechnungen','rechnung']) ? 'faelligkeit' : (in_array($t,['mahnungen','mahnung']) ? 'faellig_bis' : ''),
        'search_fields' => ['rechnungsnummer','angebotsnummer','nummer','kunde','beschreibung'],
        'sortable' => [
            'nummer' => 't.rechnungsnummer',
            'kunde' => 'c.name',
            'betrag' => 't.betrag',
            'status' => 't.status',
            'faelligkeit' => 't.faelligkeit',
            'datum' => 't.datum',
            'date' => 't.datum',
        ],
    ];

    // Load centralized overrides if present
    $overrides = [];
    $overridesFile = __DIR__ . '/../../config/doc_types.php';
    if (file_exists($overridesFile)) {
        $cfg = require $overridesFile;
        if (is_array($cfg) && isset($cfg[$t]) && is_array($cfg[$t])) {
            $overrides = $cfg[$t];
        }
    }

    // Specific overrides per known type
    switch ($t) {
        case 'rechnungen':
        case 'rechnung':
            $specific = [
                'table' => 'rechnungen',
                'number_field' => 'rechnungsnummer',
                'date_field' => 'datum',
                'due_field' => 'faelligkeit',
            ];
            return array_replace($defaults, $specific, $overrides);
        case 'angebote':
        case 'angebot':
        case 'angeboten':
            $specific = [
                'table' => 'angebote',
                'number_field' => 'angebotsnummer',
                'date_field' => 'datum',
                'due_field' => '',
                // Override sortable to match Angebote schema
                'sortable' => [
                    'nummer'      => 't.angebotsnummer',
                    'kunde'       => 'c.name',
                    'betrag'      => 't.betrag',
                    'status'      => 't.status',
                    'valid_until' => 't.gueltig_bis',
                    'datum'       => 't.datum',
                    'date'        => 't.datum',
                ],
            ];
            return array_replace($defaults, $specific, $overrides);
        case 'mahnungen':
        case 'mahnung':
            $specific = [
                'table' => 'mahnungen',
                // Number comes from joined invoice
                'number_field' => 'rechnung_nummer',
                'date_field' => 'datum',
                'due_field' => '',
                // Join through rechnungen to clients to expose invoice number and customer name
                'client_join' => [
                    'join' => 'LEFT JOIN rechnungen r ON t.rechnung_id = r.id LEFT JOIN clients c ON r.client_id = c.id',
                    'select' => 'r.rechnungsnummer AS rechnung_nummer, c.name AS kunde, c.email AS kunde_email',
                    'field' => 'kunde',
                ],
                // Sorting keys used by the UI list
                'sortable' => [
                    'rechnung_nummer' => 'r.rechnungsnummer',
                    'kunde'           => 'c.name',
                    'betrag'          => 't.total_due',
                    'stufe'           => 't.stufe',
                    'created_at'      => 't.created_at',
                    'status'          => 't.status',
                    'datum'           => 't.datum',
                ],
            ];
            return array_replace($defaults, $specific, $overrides);
        case 'lieferscheine':
        case 'lieferschein':
            $specific = [
                'table' => 'lieferscheine',
                'number_field' => 'lieferschein_nummer',
                'date_field' => 'datum',
                'due_field' => '',
            ];
            return ($defaults + $specific) + $overrides;
        default:
            return $defaults + $overrides;
    }
}

function build_like_filter(array $colNames, array $candidates, string $paramName = ':q'): array
{
    $likeParts = [];
    foreach ($candidates as $cand) {
        if (in_array($cand, $colNames, true)) {
            $likeParts[] = "t.$cand LIKE $paramName";
        }
    }
    return $likeParts;
}
