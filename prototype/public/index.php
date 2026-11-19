<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
header('Access-Control-Allow-Origin: none');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!authenticated()) jsonOut(401, ['error' => 'unauthorized']);
if ($method !== 'GET') {
  header('Allow: GET');
  jsonOut(405, ['error' => 'method_not_allowed']);
}
try {
  if ($path === '/v1/meldingen') {
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $source = (string) ($_GET['bron'] ?? 'p2000');
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $source)) jsonOut(400, ['error'=>'invalid_source']);
    $before = (string) ($_GET['before'] ?? '');
    if ($before !== '' && !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,3})?$/', $before)) jsonOut(400, ['error'=>'invalid_cursor']);
    $sql = 'SELECT id,source,source_id,occurred_at,received_at,title,body,payload FROM alerts WHERE source=:source';
    $args = [':source'=>$source];
    if ($before !== '') { $sql .= ' AND received_at < :before'; $args[':before']=$before; }
    $sql .= ' ORDER BY received_at DESC, id DESC LIMIT ' . $limit;
    $stmt = database()->prepare($sql);
    $stmt->execute($args);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) $item['payload'] = json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR);
    unset($item);
    jsonOut(200, ['data'=>$items,'count'=>count($items)]);
  }
  if ($path === '/v1/status') {
    $rows = database()->query('SELECT source,last_attempt_at,last_success_at,last_error,inserted_last_run FROM collector_status ORDER BY source')->fetchAll();
    jsonOut(200, ['collectors'=>$rows]);
  }
  jsonOut(404, ['error'=>'not_found']);
} catch (Throwable $e) {
  error_log('A-Alerts private API request failed: '.$e->getMessage());
  jsonOut(503, ['error'=>'service_unavailable']);
}