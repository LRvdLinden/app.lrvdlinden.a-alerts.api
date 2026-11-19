<?php
declare(strict_types=1);
require dirname(__DIR__).'/web/_core/normalize.php';
function testAssert(bool $value,string $message):void{
 if(!$value){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
}
$record=static fn(string $place)=>[
 'uid'=>'123456789012','melding'=>'P1 BRANDWEER Inzet Apeldoorn',
 'plaats'=>$place,'timestamp'=>1795100000,'dienst'=>'brandweer',
 'latlong'=>'52.211, 5.970','capcodes_json'=>'{"capcodes":[]}',
 'voertuigen_json'=>'{"voertuig":[]}'];
$valid=normalizeP2000([$record('Apeldoorn'),$record('Rotterdam'),$record('Wenum-Wiesel')]);
testAssert(count($valid)===2,'P2000 town whitelist excludes foreign districts');
testAssert($valid[0]['datum']===1795100000000,'P2000 milliseconds');
testAssert($valid[0]['lat']===52.211,'P2000 coordinates');
$adsb=normalizeAircraft([
 ['hex'=>'486449','flight'=>'NCG06','r'=>'PH-SAR','t'=>'A189','lat'=>52.2,'lon'=>5.4,'seen_pos'=>5],
 ['hex'=>'484abc','flight'=>'LIFELN3','lat'=>52.2,'lon'=>5.4,'seen_pos'=>5],
 ['hex'=>'489def','flight'=>'ZXP24','lat'=>52.2,'lon'=>5.4,'seen_pos'=>5],
 ['hex'=>'abc123','flight'=>'KLM123','lat'=>52.2,'lon'=>5.4,'seen_pos'=>5],
]);
testAssert(count($adsb)===3,'ADSB excludes unrelated airline');
testAssert(($adsb[0]['soort']??'')==='kustwacht','PH-SAR coastguard');
testAssert(($adsb[1]['id']??'')==='lifeliner3','Lifeliner 3 identity');
testAssert(($adsb[2]['id']??'')==='zulu24','Zulu 24 identity');
$amber=normalizeKind([['AlertId'=>'123','AlertLevel'=>'10','Message'=>['Title'=>'Voorbeeld AMBER'],'Sent'=>'2026-11-19T18:00:00Z']]);
testAssert(count($amber)===1&&$amber[0]['bron']==='amber','AMBER parsing');
echo "PASS: normalizers and region filtering\n";
