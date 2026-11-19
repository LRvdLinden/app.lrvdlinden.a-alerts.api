<?php
declare(strict_types=1);
// Run ONLY through PHP CLI cron, never via HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/common.php';
$lock = fopen(sys_get_temp_dir().'/aalerts-p2000-poll.lock','c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$source = 'p2000';
$now = gmdate('Y-m-d H:i:s');
try {
  $url = requiredEnv('P2000_PAGE_API_URL');
  $parts = parse_url($url);
  if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) throw new RuntimeException('P2000 URL must use HTTPS');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>['Accept: application/json'],
    CURLOPT_USERAGENT=>'A-Alerts-private-ingestor/0.1', CURLOPT_MAXREDIRS=>0,
  ]);
  $body = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  if ($body === false || $code !== 200) throw new RuntimeException('P2000 fetch failed HTTP '.(int)$code);
  if (strlen($body) > 5_000_000) throw new RuntimeException('P2000 payload too large');
  $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
  $list = $decoded['meldingen'] ?? $decoded['data'] ?? $decoded['results'] ?? $decoded;
  if (!is_array($list) || !array_is_list($list)) throw new RuntimeException('Unexpected P2000 feed shape');
  if (count($list) > 5000) throw new RuntimeException('Too many records');
  $sql = 'INSERT IGNORE INTO alerts (id,source,source_id,occurred_at,title,body,payload) VALUES (:id,:source,:source_id,:occurred_at,:title,:body,:payload)';
  $stmt = database()->prepare($sql);
  $inserted = 0;
  foreach ($list as $row) {
    if (!is_array($row)) continue;
    $payload = json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $sourceId = (string)($row['id'] ?? $row['uuid'] ?? $row['message_id'] ?? '');
    $id = hash('sha256', $source.'|'.($sourceId !== '' ? $sourceId : hash('sha256',$payload)));
    $title = mb_substr((string)($row['title'] ?? $row['message'] ?? $row['melding'] ?? 'P2000-melding'),0,500);
    $bodyText = isset($row['description']) ? (string)$row['description'] : null;
    $stmt->execute([':id'=>$id,':source'=>$source,':source_id'=>$sourceId ?: null,':occurred_at'=>null,':title'=>$title,':body'=>$bodyText,':payload'=>$payload]);
    $inserted += $stmt->rowCount();
  }
  $st = database()->prepare('INSERT INTO collector_status (source,last_attempt_at,last_success_at,last_error,inserted_last_run) VALUES (?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL,?) ON DUPLICATE KEY UPDATE last_attempt_at=VALUES(last_attempt_at),last_success_at=VALUES(last_success_at),last_error=NULL,inserted_last_run=VALUES(inserted_last_run)');
  $st->execute([$source,$inserted]);
  echo "P2000 processed ".count($list)." inserted $inserted\n";
} catch (Throwable $e) {
  error_log('A-Alerts P2000 collector: '.$e->getMessage());
  try { database()->prepare('INSERT INTO collector_status (source,last_attempt_at,last_error) VALUES (?,UTC_TIMESTAMP(3),?) ON DUPLICATE KEY UPDATE last_attempt_at=VALUES(last_attempt_at),last_error=VALUES(last_error)')->execute([$source,mb_substr($e->getMessage(),0,500)]); } catch(Throwable $ignored){}
  exit(1);
} finally {
  flock($lock,LOCK_UN);
  fclose($lock);
}