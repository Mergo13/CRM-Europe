<?php
declare(strict_types=1);

if (!class_exists('CRMConfig', false)) {
    class CRMConfig {
        // Authentication / Registration config
        public static array $auth = [
            'invite_secret' => '', // set to '<INVITE_SECRET>' in deployment
            'allow_public_registration' => true,
        ];

        public static array $mahnung = [
            // Days AFTER Fälligkeitsdatum (due date)
            'zahlungserinnerung_days' => 7,   // e.g., Tag 15 bei Zahlungsziel 8 Tage
            'mahnung1_days' => 14,            // e.g., Tag 22
            'letzte_mahnung_days' => 21,      // e.g., Tag 29
            // legacy keys kept for backward compat (not used by new cron)
            'erinnerung_days' => 7,
            'mahnung2_days' => 21,
            'letzte_days' => 30,
            // Monetary additions explicitly disabled by requirements
            'interest_enabled' => false,
            'fee_mahnung1' => 0.0,
            'fee_letzte' => 0.0,
        ];

        public static $themes = [
            'default' => [
                'primary' => '#0d6efd',
                'success' => '#198754',
                'warning' => '#ffc107',
                'danger' => '#dc3545',
                'info' => '#0dcaf0',
                'dark' => '#212529'
            ],
            'corporate' => [
                'primary' => '#2c3e50',
                'success' => '#27ae60',
                'warning' => '#f39c12',
                'danger' => '#e74c3c',
                'info' => '#3498db',
                'dark' => '#34495e'
            ],
            'creative' => [
                'primary' => '#9b59b6',
                'success' => '#1abc9c',
                'warning' => '#f1c40f',
                'danger' => '#e67e22',
                'info' => '#3498db',
                'dark' => '#2c3e50'
            ]
        ];

        public static $chartConfigs = [
            'revenue' => [
                'type' => 'line',
                'gradient' => true,
                'animations' => ['easeInOutQuart', 2000],
                'responsive' => true,
                'plugins' => ['tooltip', 'legend', 'datalabels']
            ],
            'performance' => [
                'type' => 'doughnut',
                'cutout' => '70%',
                'animations' => ['easeInOutBounce', 1500],
                'colors' => ['#ff6384', '#36a2eb', '#cc65fe', '#ffce56']
            ]
        ];

        // Inventory configuration
        public static array $inventory = [
            'prevent_negative_stock' => false, // set true to prevent negative free stock on OUT/RESERVIERUNG
            'default_warehouse_id' => 1,
            'min_stock_warning' => 0, // optional threshold; 0 disables warnings
        ];

        public static $animations = [
            'fadeIn' => 'animate__fadeIn',
            'slideInUp' => 'animate__slideInUp',
            'bounceIn' => 'animate__bounceIn',
            'zoomIn' => 'animate__zoomIn',
            'pulse' => 'animate__pulse',
            'heartBeat' => 'animate__heartBeat'
        ];

        public static $quickActions = [
            'invoice' => [
                'icon' => 'bi-receipt-cutoff',
                'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'shortcut' => 'Ctrl+I',
                'url' => 'rechnung.php?quick=1'
            ],
            'offer' => [
                'icon' => 'bi-file-earmark-plus',
                'gradient' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                'shortcut' => 'Ctrl+O',
                'url' => 'angebot.php?quick=1'
            ],
            'client' => [
                'icon' => 'bi-person-plus',
                'gradient' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                'shortcut' => 'Ctrl+C',
                'url' => 'register_client.php?quick=1'
            ]
        ];
    }
}