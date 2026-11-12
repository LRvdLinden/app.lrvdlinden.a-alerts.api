<?php
// A-Alerts API gateway (api.a-alerts.nl): passes every request on to the real A-Alerts API and returns its answer
// unchanged (status, headers, body). It stores nothing, logs nothing and holds no secrets.
// Why: networks and ad blockers that block *.workers.dev can reach the API through A-Alerts' own address.
declare(strict_types=1);
require __DIR__ . '/config.php';

$upstream = rtrim(getenv('AALERTS_UPSTREAM') ?: UPSTREAM, '/');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Own status page of the gateway (does not touch the real API).
if ($path === '/_gateway') {
    header('content-type: application/json');
    header('cache-control: no-store');
    echo json_encode(['ok' => true, 'gateway' => 'a-alerts', 'versie' => GATEWAY_VERSION]);
    exit;
}
if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    http_response_code(405);
    exit;
}

// Request headers: everything except the ones that belong to this connection.
$skip = ['host', 'connection', 'content-length', 'accept-encoding', 'transfer-encoding', 'keep-alive', 'te', 'upgrade', 'expect', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip'];
$headers = [];
foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
    if (!in_array(strtolower($k), $skip, true)) $headers[] = "$k: $v";
}
// Some hosts hide Authorization from PHP; .htaccess passes it on in these variables.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
if ($auth && !preg_grep('/^authorization:/i', $headers)) $headers[] = "Authorization: $auth";
// Who asked (the real API sees this server's address otherwise) and through which address.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '') $headers[] = "X-Forwarded-For: $ip";
$headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? 'api.a-alerts.nl');
$headers[] = 'X-Forwarded-Proto: https';
$headers[] = 'Expect:'; // no "100-continue" round trip on uploads

$ch = curl_init($upstream . $uri);
$drop = ['transfer-encoding', 'connection', 'keep-alive', 'content-encoding', 'content-length', 'alt-svc', 'server', 'cf-ray', 'cf-cache-status', 'report-to', 'nel', 'server-timing', 'date'];
$sent = false;
$body = '';
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => UPSTREAM_TIMEOUT,
    CURLOPT_ENCODING => '', // let curl handle compression; the web server compresses again for the visitor
    CURLOPT_NOBODY => $method === 'HEAD',
    CURLOPT_HEADERFUNCTION => function ($ch, string $line) use ($drop, &$sent): int {
        $len = strlen($line);
        $t = trim($line);
        if ($t === '') return $len;
        if (stripos($t, 'HTTP/') === 0) {
            $code = (int) (explode(' ', $t)[1] ?? 502);
            if ($code >= 200) { http_response_code($code); $sent = true; }
            return $len;
        }
        $name = strtolower(trim(explode(':', $t, 2)[0]));
        if (!in_array($name, $drop, true)) header($t, false);
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$body): int {
        $body .= $data;
        return strlen($data);
    },
]);
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input') ?: '');
}
header_remove('x-powered-by');
$ok = curl_exec($ch);
if ($ok === false && !$sent) {
    $timeout = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
    http_response_code($timeout ? 504 : 502);
    header('content-type: application/json');
    header('cache-control: no-store');
    header('access-control-allow-origin: *');
    echo json_encode(['error' => $timeout ? 'De A-Alerts-server reageert niet op tijd.' : 'De A-Alerts-server is niet bereikbaar.']);
}
curl_close($ch);
if ($ok !== false || $sent) {
    // Compress text answers for the visitor, like the real API does (JSON of 90 kB becomes 9 kB).
    $type = '';
    foreach (headers_list() as $h) if (stripos($h, 'content-type:') === 0) $type = strtolower($h);
    $tekst = (bool) preg_match('#(json|text/|javascript|xml|svg)#', $type);
    $wil = stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
    if ($tekst && $wil && strlen($body) > 256 && function_exists('gzencode')) {
        $body = gzencode($body, 6);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding', false);
    }
    if ($method !== 'HEAD') {
        header('Content-Length: ' . strlen($body));
        echo $body;
    }
}
