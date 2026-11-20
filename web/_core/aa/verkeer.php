<?php
declare(strict_types=1);
// Cron "verkeer" (every minute): the traffic list of the verkeer service into kv, as the Worker cron does. CLI only.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/verkeer-lib.php';
$lock=fopen(sys_get_temp_dir().'/aalerts-verkeer-'.substr(hash('sha256',__DIR__),0,12).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
try{ aaEnsureSchema(aaDb()); $r=aaVerkeerRun(); echo 'verkeer '.json_encode($r)."\n"; }
catch(Throwable $e){ fwrite(STDERR,'verkeer fout '.substr($e->getMessage(),0,120)."\n"); exit(1); }
