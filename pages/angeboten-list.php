<?php
declare(strict_types=1);

// pages/angeboten-list.php
// Enhanced offers list with improved security, performance, and modern design
// Requires: config/db.php, assets/js/lists.shared.js, assets/css/lists.css



require_once __DIR__ . '/../config/db.php';

// Generate CSRF token
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Secure helper functions
if (!function_exists('esc')) {
    function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path = ''): string {
        // Sanitize path and prevent directory traversal
        $path = ltrim($path, '/');
        $path = preg_replace('/[^a-zA-Z0-9\-._\/]/', '', $path);
        return '/' . $path;
    }
}

if (!function_exists('api_url')) {
    function api_url(string $endpoint): string {
        return '/pages/api/' . ltrim($endpoint, '/');
    }
}

// Configuration
$config = [
    'per_page' => 25,
    'max_export_rows' => 10000,
    'allowed_statuses' => ['open', 'accepted', 'expired', 'rejected'],
    'allowed_sorts' => ['nummer', 'kunde', 'betrag', 'valid_until', 'status']
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= esc($_SESSION['csrf_token']) ?>">
    <title>Angebote — Enhanced Liste</title>

    <script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link href="../public/css/theme.css" rel="stylesheet">

    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" as="style">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Local CSS -->
    <link rel="stylesheet" href="<?= esc(asset_url('assets/css/lists.css')) ?>">
    <link rel="stylesheet" href="<?= esc(asset_url('assets/css/crm-ultimate.css')) ?>">

    <style>
        :root {
            --primary-color: #0d6efd;
            --success-color: #198754;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --border-radius: 12px;
            --box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.2s ease;
        }

        .toolbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2563eb 100%);
            color: white;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
        }

        .toolbar h1 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .quick-actions {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
        }

        .crm-btn {
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            cursor: pointer;
        }

        .crm-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-decoration: none;
        }

        .crm-btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        .crm-btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #2563eb);
            color: white;
        }

        .crm-btn-success {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
        }

        .crm-btn-warning {
            background: linear-gradient(135deg, #fd7e14, var(--warning-color));
            color: white;
        }

        .crm-btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #e74c3c);
            color: white;
        }

        .stats-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: var(--transition);
        }

        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
        }

        .stats-label {
            color: #6c757d;
            font-size: 0.875rem;
            margin: 0;
        }

        .table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-open {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-accepted {
            background-color: #d1e7dd;
            color: #0a3622;
        }

        .status-expired {
            background-color: #f8d7da;
            color: #58151c;
        }

        .status-rejected {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .bulk-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .bulk-actions.visible {
            transform: translateY(0);
            opacity: 1;
        }

        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            max-width: 400px;
            z-index: 1050;
        }

        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Accessibility improvements */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Mobile improvements */
        @media (max-width: 768px) {
            .quick-actions .row {
                flex-direction: column;
                gap: 1rem;
            }

            .bulk-actions {
                bottom: 1rem;
                right: 1rem;
                left: 1rem;
                padding: 0.75rem;
            }

            .stats-number {
                font-size: 1.5rem;
            }
        }
        /* Minimal clean: hide non-essential bulk/CSV controls */
        #export-csv,
        #bulk-convert,
        #bulk-email,
        #bulk-delete { display: none !important; }
    </style>
    <!-- Design system overrides: unify with global theme.css and touch targets -->
    <style>
      .toolbar { background: var(--color-surface); color: var(--color-text); border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .quick-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .stats-card { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .stats-number { color: var(--color-primary); }
      #list-table { background: var(--color-surface); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .bulk-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); }
      /* Buttons unify */
      .crm-btn, .action-btn { min-height:44px; border:1px solid var(--color-border); background: var(--color-surface-2); color: inherit; border-radius: var(--radius-sm); }
      .crm-btn:hover, .action-btn:hover { background: color-mix(in srgb, var(--color-surface) 92%, #000); }
      .crm-btn:focus-visible, .action-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 30%, transparent); }
      .crm-btn-primary, .action-btn-primary { background: var(--color-primary) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-primary) !important; }
      .crm-btn-success, .action-btn-success { background: var(--color-success) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-success) !important; }
      .crm-btn-warning, .action-btn-warning { background: var(--color-warning) !important; color: var(--color-text) !important; border-color: var(--color-warning) !important; }
      .crm-btn-danger, .action-btn-danger { background: var(--color-danger) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-danger) !important; }
    </style>
</head>
<body>
<main class="container-fluid px-4 py-3">
    <!-- Enhanced Header -->
    <header class="toolbar p-4">
        <div class="d-flex justify-content-between align-items-center">
            <h1>
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                Angebote verwalten
            </h1>
            <div class="d-flex gap-2">
                <button class="btn btn-light" id="refreshData" aria-label="Daten aktualisieren">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    <span class="sr-only">Aktualisieren</span>
                </button>
                <a href="angebot.php" class="btn btn-light">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                    Neues Angebot
                </a>
            </div>
        </div>
    </header>

    <!-- Quick Stats Row -->
    <div class="row g-3 mb-4" role="region" aria-label="Statistiken">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsTotal" aria-live="polite">0</div>
                <div class="stats-label">Gesamt</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsOpen" aria-live="polite">0</div>
                <div class="stats-label">Offen</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsAccepted" aria-live="polite">0</div>
                <div class="stats-label">Angenommen</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsExpired" aria-live="polite">0</div>
                <div class="stats-label">Abgelaufen</div>
            </div>
        </div>
    </div>

    <!-- Enhanced Controls -->
    <div class="quick-actions">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="d-flex gap-2 flex-wrap">
                    <label class="sr-only" for="search">Suchen</label>
                    <input id="search" type="search" class="form-control"
                           placeholder="Suche nach Nummer, Kunde oder Artikel"
                           style="max-width: 300px;"
                           aria-describedby="search-help" />
                    <div id="search-help" class="sr-only">Suchbegriff eingeben um Angebote zu filtern</div>

                    <label class="sr-only" for="filter-status">Status filtern</label>
                    <select id="filter-status" class="form-select" style="max-width: 150px;">
                        <option value="">Alle Status</option>
                        <option value="open">Offen</option>
                        <option value="accepted">Akzeptiert</option>
                        <option value="expired">Abgelaufen</option>
                        <option value="rejected">Abgelehnt</option>
                    </select>

                    <label class="sr-only" for="filter-validity">Gültigkeit filtern</label>
                    <select id="filter-validity" class="form-select" style="max-width: 180px;">
                        <option value="">Alle Gültigkeiten</option>
                        <option value="today">Heute ablaufend</option>
                        <option value="next7">Nächste 7 Tage</option>
                        <option value="expired">Bereits abgelaufen</option>
                    </select>
                </div>
            </div>
            <div class="col-lg-6 text-end">
                <div class="btn-group" role="group" aria-label="Bulk-Aktionen">
                    <button class="crm-btn crm-btn-primary" id="export-csv"
                            aria-describedby="export-help">
                        <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>
                        CSV Export
                    </button>
                    <div id="export-help" class="sr-only">Exportiert die aktuell gefilterten Angebote als CSV-Datei</div>

                    <button class="crm-btn crm-btn-success" id="bulk-convert"
                            disabled aria-describedby="convert-help">
                        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                        Zu Rechnungen
                    </button>
                    <div id="convert-help" class="sr-only">Konvertiert ausgewählte Angebote zu Rechnungen</div>

                    <button class="crm-btn crm-btn-warning" id="bulk-email"
                            disabled aria-describedby="email-help">
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        E-Mail senden
                    </button>
                    <div id="email-help" class="sr-only">Sendet E-Mails für ausgewählte Angebote</div>

                    <button class="crm-btn crm-btn-danger" id="bulk-delete"
                            disabled aria-describedby="delete-help">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        Löschen
                    </button>
                    <div id="delete-help" class="sr-only">Löscht ausgewählte Angebote unwiderruflich</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Table Section -->
    <section class="table-container" role="region" aria-label="Angebote Tabelle">
        <div class="table-responsive">
            <table id="list-table" class="table table-hover mb-0"
                   role="table" aria-label="Liste der Angebote">
                <thead class="table-light">
                <tr role="row">
                    <th scope="col" style="width: 40px;">
                        <input id="select-all" type="checkbox" class="form-check-input"
                               aria-label="Alle Angebote auswählen">
                    </th>
                    <th scope="col" data-sort="nummer" role="columnheader"
                        aria-sort="none" tabindex="0">
                        Nummer
                        <i class="bi bi-arrow-down-up text-muted" aria-hidden="true"></i>
                    </th>
                    <th scope="col" data-sort="kunde" role="columnheader"
                        aria-sort="none" tabindex="0">
                        Kunde
                        <i class="bi bi-arrow-down-up text-muted" aria-hidden="true"></i>
                    </th>
                    <th scope="col" data-sort="betrag" class="text-end" role="columnheader"
                        aria-sort="none" tabindex="0">
                        Betrag
                        <i class="bi bi-arrow-down-up text-muted" aria-hidden="true"></i>
                    </th>
                    <th scope="col" data-sort="valid_until" role="columnheader"
                        aria-sort="none" tabindex="0">
                        Gültig bis
                        <i class="bi bi-arrow-down-up text-muted" aria-hidden="true"></i>
                    </th>
                    <th scope="col" data-sort="status" role="columnheader"
                        aria-sort="none" tabindex="0">
                        Status
                        <i class="bi bi-arrow-down-up text-muted" aria-hidden="true"></i>
                    </th>
                    <th scope="col" style="width: 200px;">Aktionen</th>
                </tr>
                </thead>
                <tbody id="table-body" role="rowgroup">
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <div class="spinner me-2"></div>
                        Lade Angebote...
                    </td>
                </tr>
                </tbody>
            </table>

            <!-- Enhanced Pagination -->
            <nav class="d-flex justify-content-between align-items-center p-3 bg-light"
                 aria-label="Pagination Navigation">
                <div class="pagination-controls">
                    <button id="prev-page" class="btn btn-outline-primary btn-sm"
                            disabled aria-label="Vorherige Seite">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        Vorherige
                    </button>
                    <span id="page-info" class="mx-3 align-self-center" aria-live="polite">
                            Seite 1
                        </span>
                    <button id="next-page" class="btn btn-outline-primary btn-sm"
                            disabled aria-label="Nächste Seite">
                        Nächste
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="text-muted small">
                    <span id="total-count" aria-live="polite">0 Einträge</span>
                </div>
            </nav>
        </div>
    </section>

    <!-- Enhanced Bulk Actions (Floating) -->
    <div class="bulk-actions" id="floatingActions" role="region" aria-label="Bulk-Aktionen">
        <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-bold text-primary" id="selectedCount" aria-live="polite">
                    0 ausgewählt
                </span>
            <div class="vr d-none d-md-block"></div>
            <button id="bulk-mark" class="btn btn-success btn-sm"
                    aria-describedby="mark-help">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
                Als angenommen
            </button>
            <div id="mark-help" class="sr-only">Markiert ausgewählte Angebote als angenommen</div>

            <!-- New: Set status selector -->
            <div class="d-flex align-items-center gap-2">
                <label for="bulk-status" class="visually-hidden">Status setzen</label>
                <select id="bulk-status" class="form-select form-select-sm" style="width:auto; min-width: 180px;">
                    <option value="">— Status wählen —</option>
                    <option value="offen">Offen</option>
                    <option value="angenommen">Angenommen</option>
                    <option value="abgelehnt">Abgelehnt</option>
                </select>
                <button id="bulk-set-status" class="btn btn-outline-primary btn-sm" disabled>
                    <i class="bi bi-flag"></i> Status setzen
                </button>
            </div>

            <button id="bulk-pdf" class="btn btn-info btn-sm"
                    aria-describedby="pdf-help">
                <i class="bi bi-file-pdf" aria-hidden="true"></i>
                PDF generieren
            </button>
            <div id="pdf-help" class="sr-only">Generiert PDF-Dateien für ausgewählte Angebote</div>

            <button id="bulk-duplicate" class="btn btn-warning btn-sm"
                    aria-describedby="duplicate-help">
                <i class="bi bi-copy" aria-hidden="true"></i>
                Duplizieren
            </button>
            <div id="duplicate-help" class="sr-only">Erstellt Kopien der ausgewählten Angebote</div>

            <button class="btn btn-secondary btn-sm" onclick="clearSelection()"
                    aria-label="Auswahl aufheben">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
                Abbrechen
            </button>
        </div>
    </div>
</main>

<!-- Enhanced Modal -->
<div id="modal" class="modal fade" tabindex="-1" aria-hidden="true" aria-labelledby="modal-title">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-title">Angebot Details</h5>
                <button id="modal-close" type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div id="modal-body" class="modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Notification Container -->
<div id="notificationContainer" class="notification" aria-live="polite"></div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100
                                   bg-black bg-opacity-25 d-flex align-items-center
                                   justify-content-center" style="z-index: 2000;">
    <div class="bg-white rounded-3 p-4 text-center">
        <div class="spinner mb-2"></div>
        <div>Verarbeite Anfrage...</div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<script>
    'use strict';

    // Enhanced LIST config with secure endpoints
    window.LIST_CONFIG = {
        api_list: <?= json_encode(api_url('angeboten_list.php')) ?>,
        api_get: <?= json_encode(api_url('angeboten_get.php')) ?>,
        api_bulk: <?= json_encode(api_url('angeboten_bulk.php')) ?>,
        api_export: <?= json_encode(api_url('angeboten_export.php')) ?>,
        api_convert: <?= json_encode(api_url('convert_angebot_to_rechnung.php')) ?>,
        pdf_url: '/pages/angebot_pdf.php',
        per_page: <?= $config['per_page'] ?>,
        default_sort: 'valid_until',
        allowed_statuses: <?= json_encode($config['allowed_statuses']) ?>,
        max_export_rows: <?= $config['max_export_rows'] ?>
    };

    // CSRF token for secure requests
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

    // State management
    const LIST_CONFIG = window.LIST_CONFIG;
    let currentPage = 1;
    let totalPages = 1;
    let currentSort = LIST_CONFIG.default_sort;
    let sortDirection = 'desc';
    let filters = {};
    let selectedItems = new Set();
    let isLoading = false;

    // Cache DOM elements
    const elements = {
        tableBody: document.getElementById('table-body'),
        statsTotal: document.getElementById('statsTotal'),
        statsOpen: document.getElementById('statsOpen'),
        statsAccepted: document.getElementById('statsAccepted'),
        statsExpired: document.getElementById('statsExpired'),
        totalCount: document.getElementById('total-count'),
        pageInfo: document.getElementById('page-info'),
        prevBtn: document.getElementById('prev-page'),
        nextBtn: document.getElementById('next-page'),
        selectAll: document.getElementById('select-all'),
        selectedCount: document.getElementById('selectedCount'),
        floatingActions: document.getElementById('floatingActions'),
        searchInput: document.getElementById('search'),
        statusFilter: document.getElementById('filter-status'),
        validityFilter: document.getElementById('filter-validity'),
        notificationContainer: document.getElementById('notificationContainer'),
        loadingOverlay: document.getElementById('loadingOverlay')
    };

    // Utility functions
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('de-DE', {
            style: 'currency',
            currency: 'EUR'
        }).format(amount || 0);
    }

    function formatDate(dateString) {
        if (!dateString) return '—';
        return new Date(dateString).toLocaleDateString('de-DE');
    }

    // Enhanced notification system
    function showNotification(message, type = 'info', duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show`;
        notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-${getNotificationIcon(type)} me-2" aria-hidden="true"></i>
                    <span>${escapeHtml(message)}</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
            `;

        elements.notificationContainer.appendChild(notification);

        // Auto-remove after duration
        setTimeout(() => {
            if (notification.parentNode) {
                const bsAlert = new bootstrap.Alert(notification);
                bsAlert.close();
            }
        }, duration);

        // Focus for screen readers
        notification.focus();
    }

    function getNotificationIcon(type) {
        const icons = {
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'danger': 'x-circle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    // Enhanced error handling
    function handleError(error, userMessage = 'Es ist ein Fehler aufgetreten') {
        console.error('Application Error:', error);
        showNotification(userMessage, 'danger');
        setLoading(false);
    }

    // Loading state management
    function setLoading(loading) {
        isLoading = loading;
        if (loading) {
            elements.loadingOverlay.classList.remove('d-none');
            document.body.style.overflow = 'hidden';
        } else {
            elements.loadingOverlay.classList.add('d-none');
            document.body.style.overflow = '';
        }
    }

    // Secure fetch with CSRF protection
    async function secureFetch(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };

        const mergedOptions = {
            ...defaultOptions,
            ...options,
            headers: { ...defaultOptions.headers, ...options.headers }
        };

        try {
            const response = await fetch(url, mergedOptions);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            return data;
        } catch (error) {
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error('Netzwerkfehler. Bitte prüfen Sie Ihre Internetverbindung.');
            }
            throw error;
        }
    }

    // Data loading and rendering
    async function loadData() {
        if (isLoading) return;

        setLoading(true);

        try {
            const params = new URLSearchParams({
                page: currentPage,
                per_page: LIST_CONFIG.per_page,
                sort: currentSort,
                direction: sortDirection,
                ...filters
            });

            const data = await secureFetch(`${LIST_CONFIG.api_list}?${params}`);

            renderTable(data.items || []);
            updateStats(data.stats || {});
            updatePagination(data.pagination || {});

        } catch (error) {
            handleError(error, 'Fehler beim Laden der Angebote');
            renderTableError();
        } finally {
            setLoading(false);
        }
    }

    function renderTable(items) {
        if (!Array.isArray(items) || items.length === 0) {
            elements.tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
                            Keine Angebote gefunden
                        </td>
                    </tr>
                `;
            return;
        }

        const rows = items.map(item => `
                <tr data-id="${escapeHtml(item.id)}">
                    <td>
                        <input type="checkbox" class="form-check-input item-checkbox"
                               name="selected[]" value="${escapeHtml(item.id)}"
                               aria-label="Angebot ${escapeHtml(item.nummer || item.id)} auswählen">
                    </td>
                    <td>
                        <strong>${escapeHtml(item.nummer || item.id)}</strong>
                        ${item.created_at ? `<br><small class="text-muted">${formatDate(item.created_at)}</small>` : ''}
                    </td>
                    <td>
                        <div>
                            <strong>${escapeHtml(item.kunde_name || 'Unbekannt')}</strong>
                            ${item.kunde_email ? `<br><small class="text-muted">${escapeHtml(item.kunde_email)}</small>` : ''}
                        </div>
                    </td>
                    <td class="text-end" data-sort-value="${(Number(item.betrag || 0)).toFixed(2)}">
                        <strong>${formatCurrency(item.betrag)}</strong>
                    </td>
                    <td>
                        ${formatDate(item.valid_until)}
                        ${isExpiringSoon(item.valid_until) ? '<br><small class="text-warning">Läuft bald ab</small>' : ''}
                    </td>
                    <td>
                        <span class="status-badge status-${escapeHtml(normalizeStatus(item.status || 'unknown'))}">
                            ${getStatusText(item.status)}
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Aktionen für Angebot ${escapeHtml(item.nummer || item.id)}">
                            <button class="btn btn-outline-primary btn-sm"
                                    onclick="viewItem('${escapeHtml(item.id)}')"
                                    aria-label="Angebot ${escapeHtml(item.nummer || item.id)} anzeigen">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary btn-sm"
                                    onclick="convertSingle('${escapeHtml(item.id)}')"
                                    aria-label="Angebot ${escapeHtml(item.nummer || item.id)} in Rechnung umwandeln">
                                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-success btn-sm"
                                    onclick="generatePdf('${escapeHtml(item.id)}')"
                                    aria-label="PDF für Angebot ${escapeHtml(item.nummer || item.id)} generieren">
                                <i class="bi bi-file-pdf" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="deleteItem('${escapeHtml(item.id)}')"
                                    aria-label="Angebot ${escapeHtml(item.nummer || item.id)} löschen">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');

        elements.tableBody.innerHTML = rows;

        // Restore selection state
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            if (selectedItems.has(checkbox.value)) {
                checkbox.checked = true;
            }
        });

        updateSelectionUI();
    }

    function renderTableError() {
        elements.tableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle fs-1 d-block mb-2" aria-hidden="true"></i>
                        Fehler beim Laden der Daten
                        <br>
                        <button class="btn btn-outline-primary btn-sm mt-2" onclick="loadData()">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                            Erneut versuchen
                        </button>
                    </td>
                </tr>
            `;
    }

    function isExpiringSoon(dateString, days = 7) {
        if (!dateString) return false;
        const expiryDate = new Date(dateString);
        const warningDate = new Date();
        warningDate.setDate(warningDate.getDate() + days);
        return expiryDate <= warningDate;
    }

    function normalizeStatus(status) {
        if (!status) return '';
        const s = String(status).toLowerCase().trim();
        switch (s) {
            case 'open':
            case 'offen':
                return 'open';
            case 'accepted':
            case 'akzeptiert':
            case 'angenommen':
                return 'accepted';
            case 'expired':
            case 'abgelaufen':
                return 'expired';
            case 'rejected':
            case 'abgelehnt':
                return 'rejected';
            default:
                return s; // fall back to original (may still be handled elsewhere)
        }
    }

    function getStatusText(status) {
        const statusTexts = {
            'open': 'Offen',
            'accepted': 'Angenommen',
            'expired': 'Abgelaufen',
            'rejected': 'Abgelehnt'
        };
        const key = normalizeStatus(status);
        return statusTexts[key] || 'Unbekannt';
    }

    function updateStats(stats) {
        elements.statsTotal.textContent = stats.total || 0;
        elements.statsOpen.textContent = stats.open || 0;
        elements.statsAccepted.textContent = stats.accepted || 0;
        elements.statsExpired.textContent = stats.expired || 0;
    }

    function updatePagination(pagination) {
        currentPage = pagination.current_page || 1;
        totalPages = pagination.total_pages || 1;

        elements.pageInfo.textContent = `Seite ${currentPage} von ${totalPages}`;
        elements.totalCount.textContent = `${pagination.total_items || 0} Einträge`;

        elements.prevBtn.disabled = currentPage <= 1;
        elements.nextBtn.disabled = currentPage >= totalPages;
    }

    // Selection management
    function updateSelectionUI() {
        const checkboxes = document.querySelectorAll('.item-checkbox');
        const checkedBoxes = document.querySelectorAll('.item-checkbox:checked');

        elements.selectAll.checked = checkboxes.length > 0 && checkboxes.length === checkedBoxes.length;
        elements.selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;

        elements.selectedCount.textContent = `${checkedBoxes.length} ausgewählt`;

        const hasSelection = checkedBoxes.length > 0;
        elements.floatingActions.classList.toggle('visible', hasSelection);

        // Enable/disable bulk action buttons
        document.getElementById('bulk-convert').disabled = !hasSelection;
        document.getElementById('bulk-email').disabled = !hasSelection;
        document.getElementById('bulk-delete').disabled = !hasSelection;
        const sel = document.getElementById('bulk-status');
        const setBtn = document.getElementById('bulk-set-status');
        if (sel && setBtn) setBtn.disabled = !(hasSelection && sel.value);
    }

    function clearSelection() {
        selectedItems.clear();
        document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
        elements.selectAll.checked = false;
        elements.selectAll.indeterminate = false;
        updateSelectionUI();
    }

    // Action handlers
    function viewItem(id) {
        // Implement view functionality
        showNotification(`Anzeige für Angebot ${id} wird geladen...`, 'info');
    }

    async function convertSingle(id) {
            if (!confirm('Angebot in Rechnung umwandeln und PDF öffnen?')) return;
            try {
                setLoading(true);
                const result = await secureFetch(LIST_CONFIG.api_convert + '?id=' + encodeURIComponent(id) + '&generate_pdf=1', { method: 'GET' });
                if (result && Array.isArray(result.results) && result.results.length && result.results[0].success) {
                    const entry = result.results[0];
                    const url = entry.pdf_url ? String(entry.pdf_url) : ('/pages/rechnung_pdf.php?id=' + encodeURIComponent(entry.rechnung_id) + '&force=1');
                    window.open(url, '_blank');
                    // optional: refresh lists
                    await loadData();
                } else {
                    throw new Error((result && (result.error || result.message)) || 'Konvertierung fehlgeschlagen');
                }
            } catch (error) {
                handleError(error, 'Fehler bei der Umwandlung in Rechnung');
            } finally {
                setLoading(false);
            }
        }

        async function generatePdf(id) {
        try {
            // Use convert endpoint to create invoice and open its PDF
            const url = `${LIST_CONFIG.api_convert}?id=${encodeURIComponent(id)}&open_pdf=1`;
            window.open(String(url), '_blank');
        } catch (error) {
            handleError(error, 'Fehler beim Generieren der PDF');
        }
    }

    async function deleteItem(id) {
        if (!confirm('Dieses Angebot wirklich löschen?')) return;

        try {
            setLoading(true);
            await secureFetch(LIST_CONFIG.api_bulk, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'delete',
                    ids: [id],
                    _token: CSRF_TOKEN
                })
            });

            showNotification('Angebot erfolgreich gelöscht', 'success');
            await loadData();
        } catch (error) {
            handleError(error, 'Fehler beim Löschen des Angebots');
        }
    }

    // Bulk actions
    async function bulkConvert() {
        const selected = Array.from(selectedItems);
        if (selected.length === 0) {
            showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
            return;
        }

        if (!confirm(`${selected.length} Angebot(e) zu Rechnungen konvertieren?`)) return;

        try {
            setLoading(true);
            const result = await secureFetch(LIST_CONFIG.api_convert, {
                method: 'POST',
                body: JSON.stringify({
                    ids: selected,
                    _token: CSRF_TOKEN
                })
            });

            showNotification(`${selected.length} Angebot(e) erfolgreich konvertiert!`, 'success');
            clearSelection();
            await loadData();
        } catch (error) {
            handleError(error, 'Fehler bei der Konvertierung');
        } finally {
            setLoading(false);
        }
    }

    async function bulkSetStatus() {
        const selected = Array.from(selectedItems);
        const sel = document.getElementById('bulk-status');
        if (selected.length === 0) {
            showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
            return;
        }
        if (!sel || !sel.value) {
            showNotification('Bitte wählen Sie einen Status.', 'warning');
            return;
        }
        try {
            setLoading(true);
            const canonical = normalizeStatus(sel.value);
            // Map canonical (en) to API (de) values expected by backend
            const apiStatusMap = { open: 'offen', accepted: 'angenommen', rejected: 'abgelehnt', expired: 'abgelaufen' };
            const apiStatus = apiStatusMap[canonical] || sel.value;
            const result = await secureFetch(`${LIST_CONFIG.api_bulk}`, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'set_status',
                    ids: selected,
                    status: apiStatus,
                    _token: CSRF_TOKEN
                })
            });
            if (result && result.success) {
                const label = getStatusText(canonical);
                showNotification(`Status auf "${label}" gesetzt ( ${selected.length} Eintrag/Einträge )`, 'success');
                await loadData();
                updateSelectionUI();
            } else {
                throw new Error((result && (result.error || result.message)) || 'Unbekannter Fehler');
            }
        } catch (error) {
            handleError(error, 'Fehler beim Setzen des Status');
        } finally {
            setLoading(false);
        }
    }

    // enable/disable the button on status change
    document.addEventListener('change', (e) => {
        if (e.target && e.target.id === 'bulk-status') {
            updateSelectionUI();
        }
    });

    async function bulkEmail() {
        const selected = Array.from(selectedItems);
        if (selected.length === 0) {
            showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
            return;
        }

        try {
            setLoading(true);
            const result = await secureFetch(`${LIST_CONFIG.api_bulk}`, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'email',
                    ids: selected,
                    _token: CSRF_TOKEN
                })
            });

            showNotification(`${selected.length} E-Mail(s) versendet!`, 'success');
        } catch (error) {
            handleError(error, 'Fehler beim E-Mail-Versand');
        }
    }

    async function bulkMarkAndConvert() {
        const selected = Array.from(selectedItems);
        if (selected.length === 0) {
            showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
            return;
        }
        try {
            setLoading(true);
            // 1) Mark as accepted
            await secureFetch(`${LIST_CONFIG.api_bulk}`, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'accept_offer',
                    ids: selected,
                    _token: CSRF_TOKEN
                })
            });
            // 2) Convert to invoices
            await secureFetch(LIST_CONFIG.api_convert, {
                method: 'POST',
                body: JSON.stringify({
                    ids: selected,
                    _token: CSRF_TOKEN
                })
            });
            showNotification(`${selected.length} Angebot/Angebote angenommen und zu Rechnung(en) konvertiert.`, 'success');
            clearSelection();
            await loadData();
        } catch (error) {
            handleError(error, 'Fehler beim Annehmen und Konvertieren');
        } finally {
            setLoading(false);
        }
    }

    async function bulkDelete() {
        const selected = Array.from(selectedItems);
        if (selected.length === 0) {
            showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
            return;
        }

        if (!confirm(`${selected.length} Angebot(e) wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`)) return;

        try {
            setLoading(true);
            await secureFetch(LIST_CONFIG.api_bulk, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'delete',
                    ids: selected,
                    _token: CSRF_TOKEN
                })
            });

            showNotification(`${selected.length} Angebot(e) erfolgreich gelöscht`, 'success');
            clearSelection();
            await loadData();
        } catch (error) {
            handleError(error, 'Fehler beim Löschen der Angebote');
        }
    }

    async function exportCsv() {
        try {
            setLoading(true);
            const params = new URLSearchParams({
                format: 'csv',
                ...filters,
                _token: CSRF_TOKEN
            });

            const response = await fetch(`${LIST_CONFIG.api_export}?${params}`, {
                headers: { 'X-CSRF-Token': CSRF_TOKEN }
            });

            if (!response.ok) throw new Error('Export fehlgeschlagen');

            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `angebote_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            showNotification('CSV-Export erfolgreich heruntergeladen', 'success');
        } catch (error) {
            handleError(error, 'Fehler beim CSV-Export');
        } finally {
            setLoading(false);
        }
    }

    // Event listeners
    function initializeEventListeners() {
        // Search input with debouncing
        elements.searchInput.addEventListener('input', debounce((e) => {
            filters.search = e.target.value.trim();
            currentPage = 1;
            loadData();
        }, 300));

        // Filter selects
        elements.statusFilter.addEventListener('change', (e) => {
            filters.status = e.target.value;
            currentPage = 1;
            loadData();
        });

        elements.validityFilter.addEventListener('change', (e) => {
            filters.validity = e.target.value;
            currentPage = 1;
            loadData();
        });

        // Pagination
        elements.prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                loadData();
            }
        });

        elements.nextBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                loadData();
            }
        });

        // Select all checkbox
        elements.selectAll.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = e.target.checked;
                if (e.target.checked) {
                    selectedItems.add(cb.value);
                } else {
                    selectedItems.delete(cb.value);
                }
            });
            updateSelectionUI();
        });

        // Individual checkboxes (delegated event handling)
        document.addEventListener('change', (e) => {
            if (e.target.matches('.item-checkbox')) {
                if (e.target.checked) {
                    selectedItems.add(e.target.value);
                } else {
                    selectedItems.delete(e.target.value);
                }
                updateSelectionUI();
            }
        });

        // Bulk action buttons
        document.getElementById('bulk-convert').addEventListener('click', bulkConvert);
        document.getElementById('bulk-email').addEventListener('click', bulkEmail);
        document.getElementById('bulk-delete').addEventListener('click', bulkDelete);
        // Bulk PDF for Angebote
        document.getElementById('bulk-pdf').addEventListener('click', function() {
            const selected = Array.from(selectedItems);
            if (!selected.length) {
                showNotification('Bitte wählen Sie mindestens ein Angebot aus.', 'warning');
                return;
            }
            const base = LIST_CONFIG.pdf_url || '/pages/angebot_pdf.php';
            selected.forEach((id, idx) => {
                const url = base + '?id=' + encodeURIComponent(id);
                setTimeout(() => window.open(url, '_blank'), idx * 150);
            });
            showNotification(`${selected.length} PDF-Ansicht(en) geöffnet.`, 'info');
        });
        document.getElementById('export-csv').addEventListener('click', exportCsv);

        // New: Set status for selected Angebote
        document.getElementById('bulk-set-status').addEventListener('click', bulkSetStatus);
        // New: Mark as accepted and convert
        const markBtn = document.getElementById('bulk-mark');
        if (markBtn) markBtn.addEventListener('click', bulkMarkAndConvert);

        // Refresh button
        document.getElementById('refreshData').addEventListener('click', () => {
            clearSelection();
            loadData();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'r':
                        e.preventDefault();
                        loadData();
                        break;
                    case 'f':
                        e.preventDefault();
                        elements.searchInput.focus();
                        break;
                    case 'a':
                        if (e.shiftKey) {
                            e.preventDefault();
                            elements.selectAll.click();
                        }
                        break;
                }
            }

            if (e.key === 'Escape') {
                clearSelection();
            }
        });

        // Sort headers
        document.querySelectorAll('[data-sort]').forEach(header => {
            header.addEventListener('click', () => {
                const sortField = header.dataset.sort;
                if (currentSort === sortField) {
                    sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort = sortField;
                    sortDirection = 'asc';
                }

                // Update aria-sort attributes
                document.querySelectorAll('[data-sort]').forEach(h => h.setAttribute('aria-sort', 'none'));
                header.setAttribute('aria-sort', sortDirection === 'asc' ? 'ascending' : 'descending');

                loadData();
            });
        });
    }

    // Initialize application
    document.addEventListener('DOMContentLoaded', () => {
        initializeEventListeners();
        loadData();

        // Show help message for first-time users
        if (!localStorage.getItem('angebote_help_shown')) {
            setTimeout(() => {
                showNotification('Tipp: Verwenden Sie Strg+F zum Suchen und Strg+A zum Auswählen aller Einträge.', 'info', 8000);
                localStorage.setItem('angebote_help_shown', 'true');
            }, 2000);
        }
    });

    // Global functions for onclick handlers
    window.viewItem = viewItem;
    window.generatePdf = generatePdf;
    window.deleteItem = deleteItem;
    window.clearSelection = clearSelection;
</script>

<script src="/assets/js/lists.shared.js"></script>
<!-- Load additional scripts -->
<script src="<?= esc(asset_url('assets/js/lists.shared.create.link.js')) ?>"></script>
<script src="<?= esc(asset_url('assets/js/lists.fix-links.js')) ?>"></script>

<?php if(function_exists('renderPdfModalAssets')): ?>
    <?= renderPdfModalAssets() ?>
<?php endif; ?>

<script src="<?= esc(asset_url('public/js/list-enhancements.js')) ?>"></script>
</body>
</html>