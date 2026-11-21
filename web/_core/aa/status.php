<?php
declare(strict_types=1);
/**
 * Status of A-Alerts (status.js): the current state per component, 90-day bars, incidents from the beheer, the
 * minute tick (status_dag, beheer pushes on changes) and the website check. Run as CLI it is the status cron.
 */
require_once __DIR__.'/push.php';

const AA_MIN=60000;
const AA_STATUS_GROEPEN=[
  ['id'=>'meldingen','naam'=>'Meldingen','naam_en'=>'Alerts','onderdelen'=>[['id'=>'p2000','naam'=>'P2000-meldingen','naam_en'=>'P2000 alerts'],['id'=>'nlalert','naam'=>'NL-Alert','naam_en'=>'NL-Alert'],
    ['id'=>'burgernet','naam'=>'Burgernet','naam_en'=>'Burgernet'],['id'=>'knmi','naam'=>'KNMI-waarschuwingen','naam_en'=>'KNMI warnings'],
    ['id'=>'kind','naam'=>'AMBER Alert en Vermist Kind Alert','naam_en'=>'AMBER Alert and Missing Child Alert'],['id'=>'vnog','naam'=>'VNOG-incidenten','naam_en'=>'VNOG incidents']]],
  ['id'=>'push','naam'=>'Pushmeldingen','naam_en'=>'Push notifications','onderdelen'=>[['id'=>'push_apple','naam'=>'iPhone, iPad, Mac en Watch','naam_en'=>'iPhone, iPad, Mac and Watch'],['id'=>'push_android','naam'=>'Android','naam_en'=>'Android'],['id'=>'homey','naam'=>'Homey-app','naam_en'=>'Homey app']]],
  ['id'=>'live','naam'=>'Live data','naam_en'=>'Live data','onderdelen'=>[['id'=>'heli','naam'=>'Helikopters','naam_en'=>'Helicopters'],['id'=>'weer','naam'=>'Weer','naam_en'=>'Weather'],['id'=>'verkeer','naam'=>'Verkeer (NDW)','naam_en'=>'Traffic (NDW)']]],
  ['id'=>'apps','naam'=>'Server, apps en website','naam_en'=>'Server, apps and website','onderdelen'=>[['id'=>'api','naam'=>'Server (api.a-alerts.nl)','naam_en'=>'Server (api.a-alerts.nl)'],['id'=>'database','naam'=>'Database (MySQL)','naam_en'=>'Database (MySQL)'],['id'=>'web','naam'=>'Website en live pager','naam_en'=>'Website and live pager']]],
];
const AA_STATUS_RANG=['ok'=>0,'maint'=>1,'warn'=>2,'down'=>3];
function aaOnderdelen(): array { $o=[]; foreach(AA_STATUS_GROEPEN as $g)foreach($g['onderdelen'] as $x)$o[]=$x; return $o; }
function aaDagVan(int $ms): string { return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'); }
function aaStartVanDag(int $ms): int { return (int)(new DateTimeImmutable(aaDagVan($ms).' 00:00:00',new DateTimeZone('Europe/Amsterdam')))->format('Uv'); }
function aaAgo(int $ms): string { return $ms<AA_MIN?max(1,(int)round($ms/1000)).' s':($ms<3600000?(int)round($ms/AA_MIN).' min':(int)round($ms/3600000).' uur'); }
function aaAgoEn(int $ms): string { return $ms<AA_MIN?max(1,(int)round($ms/1000)).' s':($ms<3600000?(int)round($ms/AA_MIN).' min':(int)round($ms/3600000).' h'); }
function aaTijdNL(int $ms): string { return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('H:i'); }
function aaOpLeeftijd(int $okAt, mixed $fout, int $now, int $warn, int $down): array {
  if(!$okAt)return ['staat'=>'ok','detail'=>null,'detail_en'=>null,'onbekend'=>true];
  $age=$now-$okAt; $st=$age>$down?'down':($age>$warn?'warn':'ok');
  return $st==='ok'?['staat'=>$st,'detail'=>'Laatste ophaalronde '.aaAgo($age).' geleden','detail_en'=>'Last read '.aaAgoEn($age).' ago']
    :['staat'=>$st,'detail'=>'Ophalen lukt niet sinds '.aaTijdNL($okAt),'detail_en'=>'Reading has failed since '.aaTijdNL($okAt),'fout'=>$fout];
}
function aaP2000Staat(int $primary, int $backup, mixed $err, int $now): array {
  $newest=max($primary,$backup);
  if(!$newest)return ['staat'=>'down','detail'=>'Geen P2000-gegevens van de Koyeb-stream','detail_en'=>'No P2000 data from the Koyeb stream','fout'=>$err];
  $age=$now-$newest; $okP=$primary>0&&$now-$primary<=3*AA_MIN; $okB=$backup>0&&$now-$backup<=3*AA_MIN;
  if($okP||$okB){ $via=$okP&&$okB?'p2000.page en Koyeb':($okP?'p2000.page':'Koyeb'); $viaEn=$okP&&$okB?'p2000.page and Koyeb':$via;
    return ['staat'=>'ok','detail'=>"P2000 actief via $via · laatste ontvangst ".aaAgo($age).' geleden','detail_en'=>"P2000 active via $viaEn · last received ".aaAgoEn($age).' ago']; }
  return ['staat'=>$age>10*AA_MIN?'down':'warn','detail'=>'De P2000-stream levert geen recente gegevens (sinds '.aaTijdNL($newest).')',
    'detail_en'=>'The P2000 stream has not delivered recent data (since '.aaTijdNL($newest).')','fout'=>$err?:null];
}
function aaPushStaat(string $platform, int $now): array {
  $q=aaDb()->prepare('SELECT SUM(ok) AS ok, SUM(fail) AS fail, SUM(lat_sum) AS ls, SUM(lat_n) AS ln FROM status_push WHERE platform = ? AND bucket >= ?'); $q->execute([$platform,$now-30*AA_MIN]); $r=$q->fetch();
  $ok=(int)($r['ok']??0); $fail=(int)($r['fail']??0); $tot=$ok+$fail; $lat=!empty($r['ln'])?(float)$r['ls']/(float)$r['ln']:null;
  if($tot<5)return ['staat'=>'ok','detail'=>null,'detail_en'=>null,'lat'=>$lat];
  $pct=$fail/$tot; $st=$pct>0.2&&$fail>=5?'down':($pct>0.05&&$fail>=3?'warn':'ok'); if($st==='ok'&&$lat!==null&&$lat>3*AA_MIN)$st='warn';
  $afg=str_replace('.',',',(string)(round($ok/$tot*1000)/10));
  return ['staat'=>$st,'lat'=>$lat,'ok'=>$ok,'fail'=>$fail,
    'detail'=>$st==='ok'?"$afg% afgeleverd":($lat!==null&&$lat>3*AA_MIN?'Gem. '.(int)round($lat/AA_MIN).' min vertraging':(int)round($pct*100).'% mislukt'),
    'detail_en'=>$st==='ok'?"$afg% delivered":($lat!==null&&$lat>3*AA_MIN?'Avg. '.(int)round($lat/AA_MIN).' min delay':(int)round($pct*100).'% failed')];
}
function aaIncidentAPI(array $r): array {
  $u=aaParse($r['updates'],[]); usort($u,fn($a,$b)=>($b['at']??0)<=>($a['at']??0));
  return ['id'=>(int)$r['id'],'titel'=>$r['titel'],'titel_en'=>($r['titel_en']??'')?:null,'soort'=>$r['soort'],'onderdelen'=>aaParse($r['onderdelen'],[]),
    'start'=>aaIso((int)$r['start_at']),'einde'=>$r['eind_at']?aaIso((int)$r['eind_at']):null,'gepland_start'=>$r['gepland_start']?aaIso((int)$r['gepland_start']):null,
    'gepland_einde'=>$r['gepland_eind']?aaIso((int)$r['gepland_eind']):null,'updates'=>array_map(fn($x)=>['at'=>aaIso((int)$x['at'])]+$x,$u)];
}
function aaActieveIncidenten(int $now): array {
  $q=aaDb()->prepare('SELECT * FROM status_incidenten WHERE eind_at IS NULL AND (gepland_start IS NULL OR gepland_start <= ?) ORDER BY start_at DESC'); $q->execute([$now]);
  return array_map('aaIncidentAPI',$q->fetchAll());
}
function aaIncidentenLijst(int $now): array {
  $q=aaDb()->prepare('SELECT * FROM status_incidenten WHERE COALESCE(eind_at, ?) >= ? ORDER BY start_at DESC LIMIT 100'); $q->execute([$now,$now-90*86400000]);
  $a=[];$g=[];$r=[];
  foreach($q->fetchAll() as $x){ if($x['eind_at']&&(int)$x['eind_at']<=$now){ if((int)$x['eind_at']>=$now-30*86400000)$r[]=aaIncidentAPI($x); }
    elseif($x['gepland_start']&&(int)$x['gepland_start']>$now)$g[]=aaIncidentAPI($x); else $a[]=aaIncidentAPI($x); }
  usort($g,fn($p,$q)=>aaMs($p['gepland_start'])<=>aaMs($q['gepland_start']));
  return ['actief'=>$a,'gepland'=>$g,'recent'=>$r];
}
function aaHuidigeStaat(int $now): array {
  $keys=['feed_ok','feed_error','koyeb_last_message','bron_ok:nlalert','bron_ok:burgernet','bron_ok:knmi','bron_ok:kind','bron_fout:nlalert','bron_fout:burgernet','bron_fout:knmi','bron_fout:kind','bron_ok:vnog','bron_fout:vnog','bron_ok:verkeer','bron_fout:verkeer',
    'status_heli_ok','status_heli_fout','status_weer_ok','status_weer_fout','status_web_ok','status_web_fout','cron_run'];
  $q=aaDb()->prepare('SELECT `key`, value FROM kv WHERE `key` IN ('.implode(',',array_fill(0,count($keys),'?')).')'); $q->execute($keys);
  $kv=[]; foreach($q as $r)$kv[$r['key']]=$r['value']; $n=fn($k)=>(int)(is_numeric($kv[$k]??null)?$kv[$k]:0);
  // P2000 comes only through the Koyeb stream now; p2000.page is no longer read (it refuses us with HTTP 403).
  $s=['p2000'=>aaP2000Staat(0,$n('koyeb_last_message'),null,$now)];
  foreach(['nlalert','burgernet','knmi','kind','vnog'] as $b)$s[$b]=aaOpLeeftijd($n("bron_ok:$b"),$kv["bron_fout:$b"]??null,$now,10*AA_MIN,30*AA_MIN);
  $s['verkeer']=setting('VERKEER_URL')!==''?aaOpLeeftijd($n('bron_ok:verkeer'),$kv['bron_fout:verkeer']??null,$now,10*AA_MIN,30*AA_MIN):['staat'=>'ok','detail'=>null,'detail_en'=>null,'onbekend'=>true];
  $s['heli']=aaOpLeeftijd($n('status_heli_ok'),null,$now,5*AA_MIN,15*AA_MIN);
  if($s['heli']['staat']==='ok'&&empty($s['heli']['onbekend'])){ $s['heli']['detail']=str_replace('Laatste ophaalronde','Laatste positie',$s['heli']['detail']); $s['heli']['detail_en']=str_replace('Last read','Last position',$s['heli']['detail_en']); }
  $s['push_apple']=aaPushStaat('apple',$now); $s['push_android']=aaPushStaat('android',$now);
  $wo=$n('status_weer_ok'); $wf=$n('status_weer_fout');
  $s['weer']=$wf>$wo&&$now-$wf<30*AA_MIN?['staat'=>'warn','detail'=>'Weer kon niet worden geladen','detail_en'=>'Weather could not be loaded']:['staat'=>'ok','detail'=>null,'detail_en'=>null];
  // Server: the own background tasks (P2000 stream, sources) must have run in the last minutes.
  $kr=aaParse(aaKvGet('koyeb_run'),null); $br=aaParse(aaKvGet('aa_bronnen'),null);
  $taak=max((int)($kr['at']??0),(int)($br['at_ms']??0));
  $s['api']=!$taak?['staat'=>'ok','detail'=>null,'detail_en'=>null,'onbekend'=>true]:($now-$taak>15*AA_MIN?['staat'=>'down','detail'=>'Achtergrondtaken staan stil sinds '.aaTijdNL($taak),'detail_en'=>'Background tasks stopped at '.aaTijdNL($taak)]
    :($now-$taak>5*AA_MIN?['staat'=>'warn','detail'=>'Achtergrondtaken lopen achter','detail_en'=>'Background tasks are behind']:['staat'=>'ok','detail'=>'Achtergrondtaken '.aaAgo($now-$taak).' geleden','detail_en'=>'Background tasks '.aaAgoEn($now-$taak).' ago']));
  // Database: a small query; slow (> 1 s) is a warning. Reaching this line means it answers.
  $t0=microtime(true); aaDb()->query('SELECT 1')->fetchColumn(); $ms=(int)round((microtime(true)-$t0)*1000);
  $s['database']=$ms>1000?['staat'=>'warn','detail'=>"Database reageert traag ($ms ms)",'detail_en'=>"Database is slow ($ms ms)"]:['staat'=>'ok','detail'=>null,'detail_en'=>null];
  // Homey app: the webhooks of api.a-alerts.nl (only when they go out from here).
  $hl=aaParse(aaKvGet('homey_last'),null);
  $s['homey']=setting('HOMEY_VIA_API')!=='1'||!is_array($hl)?['staat'=>'ok','detail'=>null,'detail_en'=>null,'onbekend'=>true]
    :(!empty($hl['ok'])?['staat'=>'ok','detail'=>null,'detail_en'=>null]:['staat'=>$now-(int)($hl['at']??0)<30*AA_MIN?'warn':'ok','detail'=>'Homey-webhook gaf HTTP '.(int)($hl['status']??0),'detail_en'=>'Homey webhook returned HTTP '.(int)($hl['status']??0)]);
  $wfj=aaParse($kv['status_web_fout']??null,null);
  $s['web']=is_array($wfj)&&($wfj['at']??0)>$n('status_web_ok')?['staat'=>'down','detail'=>'Website niet bereikbaar','detail_en'=>'Website unreachable','fout'=>$wfj['fout']??null]:['staat'=>'ok','detail'=>null,'detail_en'=>null,'onbekend'=>!$n('status_web_ok')];
  foreach(aaActieveIncidenten($now) as $inc){ if($inc['soort']!=='onderhoud')continue; foreach($inc['onderdelen'] as $id)if(isset($s[$id])&&$s[$id]['staat']!=='down')$s[$id]['staat']='maint'; }
  return $s;
}
function aaAfwijkingen(int $now): array {
  $out=[]; $b=aaDb()->query('SELECT COUNT(*) AS n, MIN(created_at) AS oudste FROM outbox')->fetch();
  if((int)$b['n']>300||($b['oudste']&&$now-(int)$b['oudste']>5*AA_MIN))$out[]=['id'=>'wachtrij','tekst'=>'Push-wachtrij loopt achter: '.(int)$b['n'].' wachtend, oudste '.aaAgo($now-(int)$b['oudste'])];
  $uur=(int)(new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('G');
  $l=aaDb()->query('SELECT MAX(datum) FROM meldingen')->fetchColumn();
  if($uur>=8&&$uur<23&&$l&&$now-(int)$l>3*3600000)$out[]=['id'=>'stil','tekst'=>'Al '.aaAgo($now-(int)$l).' geen nieuwe P2000-melding (overdag ongebruikelijk)'];
  return $out;
}
function aaDagStaat(?array $r): array {
  if(!$r)return ['staat'=>'geen','uptime'=>null]; $t=$r['ok']+$r['warn']+$r['down']+$r['maint']; if(!$t)return ['staat'=>'geen','uptime'=>null];
  $st=$r['down']>=5?'down':($r['warn']+$r['down']>=5?'warn':($r['maint']>0?'maint':'ok'));
  return ['staat'=>$st,'uptime'=>($t-$r['down'])/$t];
}
function aaStatusOverzicht(bool $beheer=false, ?int $now=null): array {
  $now??=aaNow(); $s=aaHuidigeStaat($now); $dagen=[]; for($i=89;$i>=0;$i--)$dagen[]=aaDagVan($now-$i*86400000);
  $q=aaDb()->prepare('SELECT * FROM status_dag WHERE dag >= ?'); $q->execute([$dagen[0]]); $per=[];
  foreach($q as $r){ foreach(['ok','warn','down','maint'] as $k)$r[$k]=(int)$r[$k]; $per[$r['onderdeel'].'|'.$r['dag']]=$r; }
  $groepen=[];
  foreach(AA_STATUS_GROEPEN as $g){ $ond=[];
    foreach($g['onderdelen'] as $o){ $cur=$s[$o['id']]??['staat'=>'ok']; $som=0;$tot=0; $d=[];
      foreach($dagen as $dag){ $r=$per[$o['id'].'|'.$dag]??null; if($r){$som+=$r['ok']+$r['warn']+$r['maint'];$tot+=$r['ok']+$r['warn']+$r['down']+$r['maint'];} $d[]=['dag'=>$dag]+aaDagStaat($r); }
      $x=['id'=>$o['id'],'naam'=>$o['naam'],'naam_en'=>$o['naam_en'],'staat'=>$cur['staat'],'detail'=>($cur['detail']??null)?:null,'detail_en'=>($cur['detail_en']??null)?:null,
        'uptime'=>$tot?round($som/$tot*10000)/100:null,'dagen'=>$d];
      if($beheer&&!empty($cur['fout']))$x['fout']=mb_substr((string)$cur['fout'],0,300);
      $ond[]=$x; }
    $groepen[]=['id'=>$g['id'],'naam'=>$g['naam'],'naam_en'=>$g['naam_en'],'onderdelen'=>$ond]; }
  $ergste='ok'; foreach(aaOnderdelen() as $o){ $st=$s[$o['id']]['staat']??'ok'; if((AA_STATUS_RANG[$st]??0)>(AA_STATUS_RANG[$ergste]??0))$ergste=$st; }
  $start=aaStartVanDag($now);
  $q=aaDb()->prepare('SELECT COUNT(*) AS n, MAX(datum) AS laatste FROM meldingen WHERE datum >= ?'); $q->execute([$start]); $m=$q->fetch();
  $l=aaDb()->query('SELECT MAX(datum) FROM meldingen')->fetchColumn();
  $q=aaDb()->prepare('SELECT SUM(ok) AS ok, SUM(lat_sum) AS ls, SUM(lat_n) AS ln FROM status_push WHERE bucket >= ?'); $q->execute([$start-10*AA_MIN]); $p=$q->fetch();
  $o=['bijgewerkt'=>aaIso($now),'staat'=>$ergste,'groepen'=>$groepen,'incidenten'=>aaIncidentenLijst($now),
    'vandaag'=>['meldingen'=>(int)($m['n']??0),'pushmeldingen'=>(int)($p['ok']??0),'push_gem_s'=>!empty($p['ln'])?(int)round((float)$p['ls']/(float)$p['ln']/1000):null,'laatste_p2000'=>$l?aaIso((int)$l):null]];
  if($beheer)$o['afwijkingen']=aaAfwijkingen($now);
  return $o;
}
// ------------------------------------------------------------------ incidents (beheer)
function aaCleanTxt(mixed $s, int $n): string { return mb_substr(trim((string)($s??'')),0,$n); }
function aaMaakIncident(array $b): array {
  $titel=aaCleanTxt($b['titel']??'',140); if($titel==='')throw new AaHttpError(400,'Titel ontbreekt');
  $soort=in_array($b['soort']??null,['storing','vertraagd','onderhoud'],true)?$b['soort']:'storing';
  $namen=array_column(aaOnderdelen(),'id'); $ond=array_values(array_filter(is_array($b['onderdelen']??null)?$b['onderdelen']:[],fn($o)=>in_array($o,$namen,true)));
  $now=aaNow(); $gs=!empty($b['gepland_start'])?aaMs($b['gepland_start']):null; $ge=!empty($b['gepland_einde'])?aaMs($b['gepland_einde']):null;
  $tekst=aaCleanTxt($b['tekst']??'',1000);
  $up=$tekst!==''?[['at'=>$now,'fase'=>$soort==='onderhoud'?'gepland':(aaCleanTxt($b['fase']??'',20)?:'onderzoek'),'tekst'=>$tekst,'tekst_en'=>aaCleanTxt($b['tekst_en']??'',1000)?:null]]:[];
  aaDb()->prepare('INSERT INTO status_incidenten (titel, titel_en, soort, onderdelen, start_at, eind_at, gepland_start, gepland_eind, updates, created_at, updated_at) VALUES (?,?,?,?,?,NULL,?,?,?,?,?)')
    ->execute([$titel,aaCleanTxt($b['titel_en']??'',140)?:null,$soort,aaJson($ond),$gs?:$now,$gs,$ge,aaJson($up),$now,$now]);
  $q=aaDb()->prepare('SELECT * FROM status_incidenten WHERE id = ?'); $q->execute([(int)aaDb()->lastInsertId()]); return aaIncidentAPI($q->fetch());
}
function aaUpdateIncident(int $id, array $b): ?array {
  $q=aaDb()->prepare('SELECT * FROM status_incidenten WHERE id = ?'); $q->execute([$id]); $r=$q->fetch(); if(!$r)return null;
  $now=aaNow(); $up=aaParse($r['updates'],[]);
  $fase=in_array($b['fase']??null,['onderzoek','oorzaak','herstel','monitoren','opgelost','gepland','bezig','afgerond'],true)?$b['fase']:(!empty($b['opgelost'])?($r['soort']==='onderhoud'?'afgerond':'opgelost'):'onderzoek');
  $tekst=aaCleanTxt($b['tekst']??'',1000); if($tekst!=='')$up[]=['at'=>$now,'fase'=>$fase,'tekst'=>$tekst,'tekst_en'=>aaCleanTxt($b['tekst_en']??'',1000)?:null];
  $einde=!empty($b['opgelost'])||$fase==='opgelost'||$fase==='afgerond'?$now:null;
  aaDb()->prepare('UPDATE status_incidenten SET updates = ?, eind_at = COALESCE(?, eind_at), titel = COALESCE(?, titel), updated_at = ? WHERE id = ?')->execute([aaJson($up),$einde,aaCleanTxt($b['titel']??'',140)?:null,$now,$id]);
  $q->execute([$id]); return aaIncidentAPI($q->fetch());
}

// ------------------------------------------------------------------ cron: website check, minute tick, housekeeping
function aaCheckWebsite(): void {
  $last=(int)(aaKvGet('status_web_try')??0); if(aaNow()-$last<5*AA_MIN)return; aaKvSet('status_web_try',(string)aaNow());
  $url=setting('STATUS_WEB_URL')?:'https://a-alerts.nl/';
  $probe=function()use($url){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>10,CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>'A-Alerts status']);
    curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $e=curl_errno($ch)?curl_error($ch):null; curl_close($ch); return [$st,$e]; };
  [$st,$e]=$probe(); if(!$e&&($st<200||$st>299)){sleep(8);[$st,$e]=$probe();}
  if(!$e&&$st>=200&&$st<300){ aaKvSet('status_web_ok',(string)aaNow()); aaDb()->prepare('DELETE FROM kv WHERE `key` = ?')->execute(['status_web_fout']); }
  else aaKvSet('status_web_fout',aaJson(['at'=>aaNow(),'fout'=>$e?mb_substr($e,0,120):"HTTP $st"]));
}
function aaPushAdmins(string $title, string $body, ?string $kind=null): array {
  require_once __DIR__.'/beheer.php';
  return aaPushAdminsBeheer($title,$body,$kind);
}
function aaStatusTick(int $now): void {
  $s=aaHuidigeStaat($now); $dag=aaDagVan($now); $buf=aaParse(aaKvGet('aa_status_buf'),null);
  $flush=function($b){ $st=aaDb()->prepare('INSERT INTO status_dag (onderdeel, dag, ok, warn, down, maint) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ok=ok+VALUES(ok), warn=warn+VALUES(warn), down=down+VALUES(down), maint=maint+VALUES(maint)');
    foreach($b['n']??[] as $id=>$c)$st->execute([$id,$b['dag'],$c['ok']??0,$c['warn']??0,$c['down']??0,$c['maint']??0]); };
  if($buf&&$buf['dag']!==$dag){$flush($buf);$buf=null;}
  $buf??=['dag'=>$dag,'since'=>$now,'n'=>[]];
  foreach(aaOnderdelen() as $o){ if(!empty($s[$o['id']]['onbekend']))continue; $st=$s[$o['id']]['staat']; $buf['n'][$o['id']][$st]=($buf['n'][$o['id']][$st]??0)+1; }
  if($now-$buf['since']>=10*AA_MIN){$flush($buf);$buf=['dag'=>$dag,'since'=>$now,'n'=>[]];}
  aaKvSet('aa_status_buf',aaJson($buf));
  // Beheer pushes on changes: only when this server is the production one (PUSH_ACTIEF), else the beheer gets everything twice.
  if(aaPushModus()!=='alle')return; // admin pushes stay with the Worker until the switch
  $meld=[]; $vorige=aaParse(aaKvGet('status_alerts'),[])?:[]; $sinds=aaParse(aaKvGet('status_sinds'),[])?:[];
  // Only a real outage ("storing" for 10 minutes) and "werkt weer" after 5 minutes fine; warn stays on the status page.
  foreach(aaOnderdelen() as $o){ $st=$s[$o['id']]['staat']??'ok'; $fase=$st==='down'?'down':($st==='warn'?'warn':'ok');
    if(empty($sinds[$o['id']])||$sinds[$o['id']]['staat']!==$fase)$sinds[$o['id']]=['staat'=>$fase,'at'=>$now];
    $gem=$vorige[$o['id']]??null;
    if($fase==='down'&&$now-$sinds[$o['id']]['at']>=10*AA_MIN&&!$gem){ $extra=implode(' · ',array_filter([$s[$o['id']]['detail']??null,$s[$o['id']]['fout']??null]));
      $meld[]=['🔴 '.$o['naam'].': storing',$extra?:'Al 10 minuten een storing.']; $vorige[$o['id']]='down'; }
    elseif($fase==='ok'&&$gem&&$now-$sinds[$o['id']]['at']>=5*AA_MIN){ $meld[]=['✅ '.$o['naam'].' werkt weer','Weer normaal.']; unset($vorige[$o['id']]); }
    if($fase==='ok'&&!$gem)unset($sinds[$o['id']]); }
  $afw=aaAfwijkingen($now);
  foreach($afw as $a)if(empty($vorige['x:'.$a['id']])){$meld[]=['🟠 A-Alerts wijkt af',$a['tekst']];$vorige['x:'.$a['id']]='warn';}
  $act=array_map(fn($a)=>'x:'.$a['id'],$afw); foreach(array_keys($vorige) as $k)if(str_starts_with($k,'x:')&&!in_array($k,$act,true))unset($vorige[$k]);
  aaKvSet('status_sinds',aaJson((object)$sinds)); aaKvSet('status_alerts',aaJson((object)$vorige));
  if(count($meld)===1)aaPushAdmins($meld[0][0],$meld[0][1],'bronnen');
  elseif(count($meld)>1)aaPushAdmins('A-Alerts: '.count($meld).' wijzigingen in de status',implode(' · ',array_map(fn($m)=>preg_replace('/^\S+\s/u','',$m[0]),$meld)),'bronnen');
}
function aaStatusHousekeeping(int $now): void {
  aaDb()->prepare('DELETE FROM status_push WHERE bucket < ?')->execute([$now-2*86400000]);
  aaDb()->prepare('DELETE FROM status_dag WHERE dag < ?')->execute([aaDagVan($now-95*86400000)]);
  aaDb()->prepare('DELETE FROM status_incidenten WHERE eind_at IS NOT NULL AND eind_at < ?')->execute([$now-90*86400000]);
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)){
  $lock=fopen(sys_get_temp_dir().'/aalerts-status-'.substr(hash('sha256',__DIR__),0,12).'.lock','c');
  if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
  try{
    aaEnsureSchema(aaDb()); $now=aaNow();
    // Our own background tasks run: the component "Server (API)" looks at this.
    aaKvSet('cron_run',(string)$now);
    aaCheckWebsite(); aaStatusTick($now);
    if((int)gmdate('i')===17)aaStatusHousekeeping($now);
    $d=aaDrain(45);
    echo 'status ok push='.json_encode($d)."\n";
  }catch(Throwable $e){ fwrite(STDERR,'status '.get_class($e).' '.$e->getMessage()."\n"); exit(1); }
}
