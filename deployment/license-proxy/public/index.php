<?php

declare(strict_types=1);

const UPSTREAM = 'http://127.0.0.1:5127';
const MAX_REQUEST_BYTES = 32768;
const CLOUDFLARE_NETWORKS = [
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$isDownload = $method === 'POST' &&
    preg_match('#^/api/v1/update/download/[0-9]+\.[0-9]+\.[0-9]+$#', $path) === 1;
$allowed = ($method === 'GET' && $path === '/health') ||
    ($method === 'POST' && $path === '/api/v1/license/refresh') ||
    ($method === 'POST' && $path === '/api/v1/update/check') ||
    $isDownload;
if (!$allowed) {
    header('Content-Type: application/json; charset=utf-8');
    respond(404, ['success' => false, 'message' => 'Not found.']);
}
if (!$isDownload) {
    header('Content-Type: application/json; charset=utf-8');
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > MAX_REQUEST_BYTES) {
    respond(413, ['success' => false, 'message' => 'Request is too large.']);
}

$body = $method === 'POST' ? file_get_contents('php://input') : '';
if ($body === false || strlen($body) > MAX_REQUEST_BYTES) {
    respond(413, ['success' => false, 'message' => 'Request is too large.']);
}

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$cloudflareIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
$clientIp = $remoteIp;
if (isCloudflareProxy($remoteIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP) !== false) {
    $clientIp = $cloudflareIp;
}
if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
    respond(400, ['success' => false, 'message' => 'Client IP could not be verified.']);
}

$request = curl_init(UPSTREAM . $path);
if ($request === false) {
    respond(502, ['success' => false, 'message' => 'License service is unavailable.']);
}

$options = [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => !$isDownload,
    CURLOPT_CONNECTTIMEOUT_MS => 1500,
    CURLOPT_TIMEOUT_MS => $isDownload ? 600000 : 12000,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-KMT-Client-IP: ' . $clientIp,
    ],
];
if ($isDownload) {
    $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $header): int {
        $length = strlen($header);
        $trimmed = trim($header);
        if ($trimmed === '') {
            return $length;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmed, $match) === 1) {
            http_response_code((int) $match[1]);
            return $length;
        }
        if (preg_match('#^(Content-Type|Content-Length|Content-Disposition):\s*(.+)$#i', $trimmed, $match) === 1) {
            header($match[1] . ': ' . $match[2], true);
        }
        return $length;
    };
    $options[CURLOPT_WRITEFUNCTION] = static function ($curl, string $chunk): int {
        echo $chunk;
        if (function_exists('fastcgi_finish_request')) {
            flush();
        }
        return strlen($chunk);
    };
}
if ($method === 'POST') {
    $options[CURLOPT_POSTFIELDS] = $body;
}
curl_setopt_array($request, $options);

$responseBody = curl_exec($request);
if ($responseBody === false) {
    error_log('KMTGuard License proxy upstream error: ' . curl_error($request));
    if ($isDownload && headers_sent()) {
        exit;
    }
    respond(502, ['success' => false, 'message' => 'License service is unavailable.']);
}

$status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
if ($isDownload) {
    if ($status < 100 || $status > 599) {
        http_response_code(502);
    }
    exit;
}

if ($status < 100 || $status > 599) {
    $status = 502;
}
http_response_code($status);
echo $responseBody;

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function isCloudflareProxy(string $remoteIp): bool
{
    if (filter_var($remoteIp, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    foreach (CLOUDFLARE_NETWORKS as $network) {
        if (ipInCidr($remoteIp, $network)) {
            return true;
        }
    }
    return false;
}

function ipInCidr(string $ip, string $network): bool
{
    [$subnet, $prefixText] = explode('/', $network, 2);
    $addressBytes = inet_pton($ip);
    $subnetBytes = inet_pton($subnet);
    if ($addressBytes === false || $subnetBytes === false || strlen($addressBytes) !== strlen($subnetBytes)) {
        return false;
    }

    $prefix = (int) $prefixText;
    $maximumBits = strlen($addressBytes) * 8;
    if ($prefix < 0 || $prefix > $maximumBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($subnetBytes, 0, $wholeBytes)) {
        return false;
    }
    $remainingBits = $prefix % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($subnetBytes[$wholeBytes]) & $mask);
}
