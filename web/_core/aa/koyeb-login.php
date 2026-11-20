<?php
declare(strict_types=1);
require_once __DIR__.'/base.php';

/**
 * How to connect: with P2000_CODE (given by the operator of the stream) the website login website-<unix seconds>-<code>,
 * made fresh for every connection; without it the own synthetic Homey identity as before. The protocol holds the code:
 * it is never logged or stored.
 */
function aaKoyebVerbinding(): ?array {
  $url=trim(setting('P2000_URL')?:(setting('KOYEB_WEBSOCKET_URL')?:'wss://p2000-martijnpoppen.koyeb.app'));
  if(!str_starts_with($url,'wss://'))return null;
  $code=trim(setting('P2000_CODE'));
  if(preg_match('/^[A-Za-z0-9]{8,128}$/',$code))return ['url'=>$url,'protocol'=>'website-'.time().'-'.$code,'soort'=>'website'];
  $id=setting('KOYEB_SUBPROTOCOL');
  if(preg_match('/^homey-[0-9a-f]{24}-nl\.lrvdlinden\.p2000$/',$id))return ['url'=>trim(setting('KOYEB_WEBSOCKET_URL')?:'wss://p2000-martijnpoppen.koyeb.app'),'protocol'=>$id,'soort'=>'homey'];
  return null;
}
