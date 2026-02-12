<?php
// includes/vies.php — EU VIES VAT validation via SOAP
// Official endpoint: https://ec.europa.eu/taxation_customs/vies/services/checkVatService

declare(strict_types=1);

if (!function_exists('vies_normalize_vat')) {
    function vies_normalize_vat(string $raw): array {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
        if ($s === '') return ['country_code' => null, 'vat_number' => null];
        if (str_starts_with($s, 'ATU')) {
            return ['country_code' => 'AT', 'vat_number' => substr($s, 3)];
        }
        if (preg_match('/^([A-Z]{2})([A-Z0-9]+)$/', $s, $m)) {
            return ['country_code' => $m[1], 'vat_number' => $m[2]];
        }
        if (preg_match('/^[0-9A-Z]+$/', $s)) {
            return ['country_code' => 'AT', 'vat_number' => $s];
        }
        return ['country_code' => null, 'vat_number' => null];
    }
}

if (!function_exists('vies_check_vat')) {
    function vies_check_vat(string $rawVatId, array $opts = []): array {
        $norm = vies_normalize_vat($rawVatId);
        $cc = $norm['country_code'];
        $num = $norm['vat_number'];
        if (!$cc || !$num) {
            return [
                'success' => false,
                'is_valid' => null,
                'country_code' => $cc,
                'vat_number' => $num,
                'validation_date' => null,
                'name' => null,
                'address' => null,
                'error' => 'Ungültige USt-IdNr.'
            ];
        }

        // Timeouts and safety nets
        $endpoint = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';
        $wsdlUrl  = $endpoint . '?wsdl';
        $timeout  = isset($opts['timeout']) ? max(2, (int)$opts['timeout']) : 6;
        $ua       = 'Rechnung-app (+VIES)';

        // Build a shared stream context for HTTP/SSL timeouts
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => $ua,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                // Allow system CA bundle
                'capture_peer_cert' => false,
            ],
        ]);

        // Preflight: quick HEAD/GET to WSDL to avoid SoapClient hanging on network issues
        $oldSockTo = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string)$timeout);
        try {
            $headers = @get_headers($wsdlUrl, true, $ctx);
            if ($headers === false) {
                return [
                    'success' => false,
                    'is_valid' => null,
                    'country_code' => $cc,
                    'vat_number' => $num,
                    'validation_date' => null,
                    'name' => null,
                    'address' => null,
                    'error' => 'VIES Dienst derzeit nicht erreichbar (WSDL)'
                ];
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'is_valid' => null,
                'country_code' => $cc,
                'vat_number' => $num,
                'validation_date' => null,
                'name' => null,
                'address' => null,
                'error' => 'VIES Vorabprüfung fehlgeschlagen: ' . $e->getMessage()
            ];
        } finally {
            if ($oldSockTo !== false) { @ini_set('default_socket_timeout', (string)$oldSockTo); }
        }

        // Create SOAP client with strict timeouts
        try {
            $client = new SoapClient($wsdlUrl, [
                'exceptions' => true,
                'connection_timeout' => $timeout,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'trace' => false,
                'user_agent' => $ua,
                'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
                'stream_context' => $ctx,
            ]);

            // Apply a per-call timeout using default_socket_timeout during request
            $oldSockTo2 = ini_get('default_socket_timeout');
            @ini_set('default_socket_timeout', (string)$timeout);
            try {
                $res = $client->checkVat([
                    'countryCode' => $cc,
                    'vatNumber' => $num,
                ]);
            } finally {
                if ($oldSockTo2 !== false) { @ini_set('default_socket_timeout', (string)$oldSockTo2); }
            }

            $isValid = isset($res->valid) ? (bool)$res->valid : null;
            $name = isset($res->name) ? trim((string)$res->name) : null;
            $addr = isset($res->address) ? trim((string)$res->address) : null;
            $date = isset($res->requestDate) ? date('Y-m-d', strtotime((string)$res->requestDate)) : date('Y-m-d');
            return [
                'success' => true,
                'is_valid' => $isValid,
                'country_code' => $cc,
                'vat_number' => $num,
                'validation_date' => $date,
                'name' => ($name && strtoupper($name) !== '---') ? $name : null,
                'address' => ($addr && strtoupper($addr) !== '---') ? $addr : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            // Gracefully degrade on timeouts or service errors
            $msg = $e->getMessage();
            if (stripos($msg, 'timed') !== false || stripos($msg, 'timeout') !== false) {
                $msg = 'Zeitüberschreitung beim VIES-Dienst';
            }
            return [
                'success' => false,
                'is_valid' => null,
                'country_code' => $cc,
                'vat_number' => $num,
                'validation_date' => null,
                'name' => null,
                'address' => null,
                'error' => 'VIES Fehler: ' . $msg,
            ];
        }
    }
}
