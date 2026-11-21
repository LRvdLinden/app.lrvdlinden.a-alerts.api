<?php
declare(strict_types=1);
// homey.js port: new alerts, changed warnings (VNOG apart) and new news ids go to every Homey with the A-Alerts app,
// through the Homey webhook service (channel "aalerts"). Only when HOMEY_VIA_API is "1": until then the Worker sends
// them, and both at once would make the Homey app see everything twice. The cursor stays on failure (sent again).
require_once __DIR__.'/base.php';
require_once __DIR__.'/meldingen.php';
require_once __DIR__.'/waarschuwingen.php';

function aaHomeyActief(): bool { return setting('HOMEY_VIA_API')==='1'&&setting('HOMEY_WEBHOOK_ID')!==''; }

function aaHomeyChanges(int $since): array {
  $db=aaDb();
  $q=$db->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND m.created_at > ? ORDER BY m.created_at ASC LIMIT 40'); $q->execute([$since]);
  $meldingen=array_map('aaMeldingAPI',$q->fetchAll());
  $q=$db->prepare(AA_PUBLIC_SELECT.' AND (w.updated_at > ? OR w.first_seen > ? OR COALESCE(wo.updated_at, 0) > ?) ORDER BY w.start_at DESC LIMIT 40');
  $q->execute([$since,$since,$since]);
  $w=array_values(array_filter(array_map(fn($r)=>aaWarningAPI(aaWithOverride($r),false),$q->fetchAll())));
  $q=$db->prepare('SELECT id FROM nieuws WHERE hidden = 0 AND created_at > ? ORDER BY created_at ASC LIMIT 20'); $q->execute([$since]);
  return ['meldingen'=>$meldingen,'waarschuwingen'=>array_values(array_filter($w,fn($x)=>($x['bron']??'')!=='vnog')),
    'vnog'=>array_values(array_filter($w,fn($x)=>($x['bron']??'')==='vnog')),'nieuws'=>array_column($q->fetchAll(),'id')];
}

function aaNotifyHomey(): array {
  if(!aaHomeyActief())return ['sent'=>false,'reason'=>'not configured'];
  $now=aaNow(); $raw=aaKvGet('homey_cursor');
  if($raw===null){ aaKvSet('homey_cursor',(string)$now); return ['sent'=>false,'reason'=>'cursor set']; }
  $since=max((int)$raw?:$now,$now-30*60000); // never resend more than 30 minutes
  $c=aaHomeyChanges($since);
  if(!$c['meldingen']&&!$c['waarschuwingen']&&!$c['vnog']&&!$c['nieuws']){ aaKvSet('homey_cursor',(string)$now); return ['sent'=>false,'reason'=>'nothing new']; }
  $ch=curl_init('https://webhooks.athom.com/webhook/'.rawurlencode(setting('HOMEY_WEBHOOK_ID')));
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>aaJson(['channel'=>'aalerts','sent_at'=>aaIso($now)]+$c)]);
  curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $ok=$code>=200&&$code<300;
  if($ok)aaKvSet('homey_cursor',(string)$now); else error_log('[aa homey] HTTP '.$code);
  aaKvSet('homey_last',aaJson(['at'=>$now,'ok'=>$ok,'status'=>$code,'meldingen'=>count($c['meldingen']),'waarschuwingen'=>count($c['waarschuwingen']),'nieuws'=>count($c['nieuws'])]));
  return ['sent'=>$ok,'status'=>$code];
}
