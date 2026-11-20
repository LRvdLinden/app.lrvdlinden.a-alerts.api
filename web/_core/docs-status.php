<?php
declare(strict_types=1);
/**
 * Live state of the own A-Alerts API for /docs. Only reads: the database (kv, tables, collector runs), which module
 * files are deployed and which settings are present (names only, never values). Nothing here claims more than the
 * server can show: a part counts as running only with a recent sign of life in the database.
 */
require_once __DIR__.'/private-api.php';

function docsNowMs(): int { return (int)floor(microtime(true)*1000); }
function docsKv(PDO $db, string $key): ?string {
  try{ $q=$db->prepare('SELECT value FROM kv WHERE `key` = ?'); $q->execute([$key]); $v=$q->fetchColumn(); return $v===false?null:(string)$v; }catch(Throwable){ return null; }
}
function docsKvAt(PDO $db, string $key): ?int {
  try{ $q=$db->prepare('SELECT at FROM kv WHERE `key` = ?'); $q->execute([$key]); $v=$q->fetchColumn(); return $v===false||$v===null?null:(int)$v; }catch(Throwable){ return null; }
}
function docsMsOf(?string $v): ?int { if($v===null||$v==='')return null; if(ctype_digit($v))return (int)$v; $t=strtotime($v); return $t===false?null:$t*1000; }
function docsScalar(PDO $db, string $sql, array $args=[]): mixed {
  try{ $q=$db->prepare($sql); $q->execute($args); $v=$q->fetchColumn(); return $v===false?null:$v; }catch(Throwable){ return null; }
}
function docsTableExists(PDO $db, string $t): bool { return docsScalar($db,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',[$t])>0; }
/** "3 min geleden", "2 uur geleden", "20-11 14:05". */
function docsAgo(?int $ms): string {
  if(!$ms)return 'nog nooit';
  $s=max(0,intdiv(docsNowMs()-$ms,1000));
  if($s<90)return $s.' s geleden';
  if($s<5400)return round($s/60).' min geleden';
  if($s<172800)return round($s/3600).' uur geleden';
  return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i');
}
/** Module file of the own engine present on this server. */
function docsModule(string $file): bool { return is_file(__DIR__.'/aa/'.$file); }
/** Route of the catalog served by the own engine: all literal parts of its path occur in one deployed route file. */
function docsRouteInEngine(array $route): bool {
  static $files=null;
  // Regex escapes are removed first, so '\.png$' in a route pattern reads as '.png$'.
  if($files===null){ $files=[]; foreach(glob(__DIR__.'/aa/routes*.php')?:[] as $f)$files[]=str_replace('\\','',(string)file_get_contents($f)); }
  // The own API routes of this server (status, sources, comparison, mirror) live in private-api.php.
  if(($route['group']??'')==='Eigen API'&&($route['status']??'')==='supported')return true;
  $parts=array_values(array_filter(preg_split('/\{[^}]+\}/',(string)$route['path']),fn($p)=>$p!==''&&$p!=='/'));
  if(!$parts)return false;
  foreach($files as $code){
    $all=true;
    foreach($parts as $p){
      // A part that ends a path segment must end there in the code too ("/routes" is not "/routes-lucht.php").
      $re=str_ends_with($p,'/')?'~'.preg_quote($p,'~').'~':'~'.preg_quote($p,'~').'(?=[$\'"#?/(])~';
      if(!preg_match($re,$code)){$all=false;break;}
    }
    if($all)return true;
  }
  return false;
}
function docsSet(string $k): bool { return setting($k)!==''; }

/** Everything /docs shows live. Every block carries a state: live, overname, deels, gepland, fout, uit. */
function docsLiveState(): array {
  $now=docsNowMs(); $out=['db'=>false,'bronnen'=>[],'onderdelen'=>[],'tabellen'=>[],'config'=>[],'fout'=>null];
  try{ $db=privateDb(); $db->query('SELECT 1'); $out['db']=true; }catch(Throwable $e){ $out['fout']='Database niet bereikbaar'; return $out; }
  $aa=docsTableExists($db,'meldingen')&&docsTableExists($db,'kv');

  // --- tables
  foreach(['meldingen'=>'P2000-meldingen','waarschuwingen'=>'Waarschuwingen','nieuws'=>'Nieuwsartikelen','nieuws_updates'=>'Nieuwsaanvullingen','devices'=>'Toestellen (push)',
    'device_prefs'=>'Pushvoorkeuren','installs'=>'Installaties','post_inzet'=>'Kazerne-inzetten','heli_posities'=>'Vliegposities','overrides'=>'Beheercorrecties',
    'melding_feedback'=>'Gemelde fouten','weather'=>'Weer-cache','melding_routes'=>'Routes-cache','status_incidenten'=>'Statusincidenten','kv'=>'Sleutel/waarde'] as $t=>$label)
    $out['tabellen'][]=['tabel'=>$t,'label'=>$label,'aantal'=>docsTableExists($db,$t)?(int)docsScalar($db,"SELECT COUNT(*) FROM `$t`"):null];

  // --- collector runs of the first PHP collectors (api_collector_runs)
  $runs=[];
  try{ foreach($db->query("SELECT r.source, r.finished_at, r.ok, r.error_code, r.total FROM api_collector_runs r JOIN (SELECT source, MAX(id) AS id FROM api_collector_runs GROUP BY source) m ON m.id = r.id") as $r)$runs[$r['source']]=$r; }catch(Throwable){}
  $run=function(string $src)use($runs){ $r=$runs[$src]??null; return $r?['at'=>docsMsOf((string)$r['finished_at']),'ok'=>(int)$r['ok']===1,'fout'=>$r['error_code'],'n'=>(int)$r['total']]:null; };

  // --- P2000 via own Koyeb intake
  $kStart=docsMsOf(docsKv($db,'aa_koyeb_start')); $kMsg=docsMsOf(docsKv($db,'koyeb_last_message')); $kAdd=docsMsOf(docsKv($db,'koyeb_last_added'));
  $kErr=docsMsOf(docsKv($db,'koyeb_last_error')); $kErrT=docsKv($db,'koyeb_last_error_text');
  $nKoyeb=$aa?(int)docsScalar($db,"SELECT COUNT(*) FROM meldingen WHERE bron = 'koyeb'"):0;
  $p2State=$kMsg&&$now-$kMsg<180000?'live':($kStart?'fout':'gepland');
  $out['bronnen'][]=['id'=>'p2000','naam'=>'P2000 · eigen Koyeb-inname','icoon'=>'/images/website/icon_brandweer.png','staat'=>$p2State,
    'laatst'=>$kMsg,'detail'=>'Laatste nieuwe melding uit het werkgebied: '.docsAgo($kAdd).' · '.$nKoyeb.' meldingen zelf binnengehaald sinds '.($kStart?docsAgo($kStart):'—')
      .($kErr&&(!$kMsg||$kErr>$kMsg)?' · laatste fout: '.(string)$kErrT:'')];
  $pp=$run('p2000.page');
  $out['bronnen'][]=['id'=>'p2000page','naam'=>'P2000 · p2000.page (eigen poller)','icoon'=>'/images/website/icon_ambulance.png','staat'=>'uit','laatst'=>$pp['at']??null,
    'detail'=>'Gepauzeerd: de persoonlijke sleutel staat maar een beperkt aantal IP-adressen toe en de Worker gebruikt hem al. Meldingen komen via de eigen Koyeb-inname.'];

  // --- D1 copy of the Worker database
  $d1=json_decode((string)docsKv($db,'aa_d1'),true); $d1=is_array($d1)?$d1:[];
  $d1Last=max(docsMsOf($d1['vol_bezig']??null)??0,...array_map(fn($v)=>docsMsOf((string)$v)??0,array_values(is_array($d1['klein']??null)?$d1['klein']:[0])));
  $out['bronnen'][]=['id'=>'d1','naam'=>'Overname Worker-database (D1, alleen lezen)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>!empty($d1['fout'])?'fout':(!empty($d1['vol_klaar'])?($d1Last&&$now-$d1Last<1200000?'overname':'fout'):($d1?'deels':'gepland')),'laatst'=>$d1Last?:null,
    'detail'=>(!empty($d1['vol_klaar'])?'Volledige kopie klaar: '.docsAgo(docsMsOf($d1['vol_klaar'])).'. ':'Volledige kopie loopt nog. ')
      .'Waarschuwingen elke 5 min, toestellen/nieuws/teksten elke 15 min tot de overstap.'.(!empty($d1['fout'])?' Fout: '.$d1['fout']:'')];

  // --- Worker mirror
  $mirror=(string)(docsScalar($db,"SELECT MAX(updated_at) FROM api_kv WHERE key_name LIKE 'worker:/v1/%'")??'');
  $mAt=docsMsOf($mirror);
  $out['bronnen'][]=['id'=>'spiegel','naam'=>'Spiegel van workers.dev (controle)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>setting('WORKER_SPIEGEL')==='0'?'uit':($mAt&&$now-$mAt<300000?'overname':($mAt?'fout':'gepland')),'laatst'=>$mAt,
    'detail'=>'Leest de openbare routes van de Worker om te vergelijken; tijdelijk, uit met WORKER_SPIEGEL=0.'];

  // --- warnings per source: own intake module present, else data from the D1 copy
  $eigenBronnen=docsModule('bronnen.php')||docsModule('routes-bronnen.php');
  foreach([['nlalert','NL-Alert','/images/website/logo_nlalert.png'],['burgernet','Burgernet','/images/website/icon_burgernet.png'],['knmi','KNMI-weerwaarschuwingen','/images/website/icon_knmi.png'],
           ['amber','AMBER Alert','/images/website/logo_amber.png'],['vermistkind','Vermist Kind Alert','/images/website/icon_vermistkind.png'],['vnog','VNOG (Veiligheidsregio)','/images/website/logo_vnog.png']] as [$b,$naam,$ico]){
    $n=$aa?(int)docsScalar($db,'SELECT COUNT(*) FROM waarschuwingen WHERE bron = ?',[$b]):0;
    $act=$aa?(int)docsScalar($db,"SELECT COUNT(*) FROM waarschuwingen WHERE bron = ? AND status != 'beeindigd'",[$b]):0;
    $last=$aa?(int)(docsScalar($db,'SELECT MAX(updated_at) FROM waarschuwingen WHERE bron = ?',[$b])??0):0;
    $r=$run($b==='vermistkind'||$b==='amber'?'kind':$b);
    $out['bronnen'][]=['id'=>$b,'naam'=>$naam,'icoon'=>$ico,'staat'=>$eigenBronnen?'live':($n||$d1?'overname':'gepland'),'laatst'=>$last?:null,
      'detail'=>"$n opgeslagen, $act actief. ".($eigenBronnen?'Eigen inname actief.':'Gegevens via de overname uit de Worker; eigen inname volgt.')
        .($r?' Eigen testcollector: '.($r['ok']?'ok':'fout '.(string)$r['fout']).', '.docsAgo($r['at']).'.':'')];
  }
  // --- news per kind
  foreach([['politie','Politie.nl-nieuws','/images/website/icon_politie.png','politie'],['regionaal','112nieuws (regionaal)','/images/website/nieuws/112-brand-1.webp','112nieuws'],
           ['weer','KNMI-weerartikelen','/images/website/icon_knmi.png',null],['liveblog','Liveblogs','/images/website/icon_burgernet.png',null]] as [$s,$naam,$ico,$src]){
    $n=$aa?(int)docsScalar($db,'SELECT COUNT(*) FROM nieuws WHERE soort = ?',[$s]):0;
    $last=$aa?(int)(docsScalar($db,'SELECT MAX(updated_at) FROM nieuws WHERE soort = ?',[$s])??0):0;
    $r=$src?$run($src):null;
    $out['bronnen'][]=['id'=>'nieuws-'.$s,'naam'=>$naam,'icoon'=>$ico,'staat'=>$eigenBronnen?'live':($n||$d1?'overname':'gepland'),'laatst'=>$last?:null,
      'detail'=>"$n artikelen. ".($eigenBronnen?'Eigen inname actief.':'Via de overname uit de Worker; eigen inname volgt.').($r?' Eigen testcollector: '.($r['ok']?'ok':'fout').', '.docsAgo($r['at']).'.':'')];
  }
  // --- aircraft (adsb.lol)
  $heliPos=$aa?(int)(docsScalar($db,'SELECT MAX(at) FROM heli_posities')??0):0;
  $snap=docsKvAt($db,'lucht:last-known-v1');
  $lucht=docsModule('routes-lucht.php');
  $out['bronnen'][]=['id'=>'adsb','naam'=>'Helikopters & luchtvaart (adsb.lol)','icoon'=>'/images/website/heli_lifeliner.png',
    'staat'=>$lucht&&$snap&&$now-$snap<600000?'live':($lucht?'deels':($heliPos?'overname':'gepland')),'laatst'=>max($snap??0,$heliPos)?:null,
    'detail'=>($lucht?'Eigen routes staan op de server. ':'Eigen routes nog in aanbouw. ').'Laatste vastgelegde positie: '.docsAgo($heliPos?:null).'.'];
  // --- traffic
  $vk=docsKvAt($db,'verkeer'); $nd=$run('ndw');
  $out['bronnen'][]=['id'=>'verkeer','naam'=>'Verkeer (NDW, flitsers, werkzaamheden)','icoon'=>'/images/website/verkeer_file.png',
    'staat'=>!docsSet('VERKEER_URL')?'gepland':($vk&&$now-$vk<900000?'live':(docsModule('routes-lucht.php')?'deels':'gepland')),'laatst'=>$vk,
    'detail'=>(docsSet('VERKEER_URL')?'Bron ingesteld. ':'Bron nog niet ingesteld. ').($nd?'Testcollector: '.($nd['ok']?'ok':'fout').', '.docsAgo($nd['at']).'.':'')];
  // --- Apple services and push
  $apple=docsModule('routes-apple.php');
  $wAt=$aa?(int)(docsScalar($db,'SELECT MAX(fetched_at) FROM weather')??0):0; $rAt=$aa?(int)(docsScalar($db,'SELECT MAX(at) FROM melding_routes')??0):0;
  $out['bronnen'][]=['id'=>'apple','naam'=>'Apple-diensten (WeatherKit, Maps, MapKit JS)','icoon'=>'/images/website/route_applemaps.png',
    'staat'=>$apple?'deels':(docsSet('WEB_WEATHERKIT_P8')?'gepland':'gepland'),'laatst'=>max($wAt,$rAt)?:null,
    'detail'=>'Sleutels '.(docsSet('WEB_WEATHERKIT_P8')&&docsSet('MAPS_SERVER_API_P8')&&docsSet('WEB_MAPKIT_JS_P8')?'aanwezig':'nog niet compleet').'. '.($apple?'Eigen routes staan op de server.':'Eigen routes nog in aanbouw.')];
  $push=setting('PUSH_ACTIEF')==='1';
  $out['bronnen'][]=['id'=>'push','naam'=>'Pushmeldingen (APNs, FCM)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>$push?'live':(docsModule('push.php')?'uit':'gepland'),'laatst'=>null,
    'detail'=>($push?'Versturen staat aan.':'Versturen staat uit: de Worker verstuurt nu, anders krijgt iedereen alles dubbel.').' Sleutels '.(docsSet('APN_P8_CERT')&&docsSet('FCM_SERVICE_ACCOUNT')?'aanwezig':'nog niet compleet').'.'];

  // --- building blocks of the migration
  $schema=docsKv($db,'aa_schema')!==null;
  $out['onderdelen']=[
    ['naam'=>'Eigen database (MariaDB, zelfde tabellen als de Worker)','staat'=>$schema?'live':'gepland','uitleg'=>$schema?'Schema geïnstalleerd.':'Nog niet geïnstalleerd.'],
    ['naam'=>'P2000-inname zonder Cloudflare','staat'=>$p2State,'uitleg'=>'Eigen WebSocket naar Koyeb, elke minuut via cron, met plaatsfilter, ontdubbeling en incidentkoppeling.'],
    ['naam'=>'Meldingen, waarschuwingen, nieuws en vaste routes','staat'=>docsModule('meldingen.php')&&docsModule('waarschuwingen.php')&&docsModule('nieuws.php')?'live':'deels','uitleg'=>'Leesroutes draaien in de eigen motor en worden met de vergelijkingsworkflow tegen workers.dev gecontroleerd.'],
    ['naam'=>'Eigen inname waarschuwingen en nieuws','staat'=>$eigenBronnen?'live':'gepland','uitleg'=>$eigenBronnen?'Eigen collectors actief.':'Nu via de overname uit de Worker-database; eigen collectors volgen.'],
    ['naam'=>'Helikopters, luchtvaart, verkeer en radar','staat'=>$lucht?'deels':'gepland','uitleg'=>$lucht?'Module staat op de server; zie de bronnen hierboven.':'In aanbouw.'],
    ['naam'=>'Apple-diensten, toestellen, statistieken en beheer','staat'=>$apple?'deels':'gepland','uitleg'=>$apple?'Module staat op de server.':'In aanbouw.'],
    ['naam'=>'Pushmeldingen','staat'=>$push?'live':(docsModule('push.php')?'uit':'gepland'),'uitleg'=>'Pas aan bij de overstap.'],
    ['naam'=>'Overstap van de apps en site naar api.a-alerts.nl','staat'=>'gepland','uitleg'=>'Alleen na uitdrukkelijke goedkeuring; de Worker blijft tot dan productie.'],
  ];
  // --- settings present (names only)
  foreach(['P2000_PAGE_API_URL'=>'P2000.page','KOYEB_SUBPROTOCOL'=>'Koyeb-identiteit','VERKEER_URL'=>'Verkeer','ADSB_LOL_API_KEY'=>'adsb.lol','KNMI_WMS_KEY'=>'KNMI radar',
    'APN_P8_CERT'=>'APNs','FCM_SERVICE_ACCOUNT'=>'FCM','WEB_WEATHERKIT_P8'=>'WeatherKit','MAPS_SERVER_API_P8'=>'Apple Maps','WEB_MAPKIT_JS_P8'=>'MapKit JS',
    'HOMEY_WEBHOOK_ID'=>'Homey','BEHEER_USER'=>'Beheer-login','CLOUDFLARE_API_TOKEN'=>'D1-overname'] as $k=>$label)$out['config'][]=['label'=>$label,'aanwezig'=>docsSet($k)];
  return $out;
}
