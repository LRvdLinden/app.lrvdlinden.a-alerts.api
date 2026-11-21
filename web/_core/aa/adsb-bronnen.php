<?php
// Open ADS-B sources for the helicopters, the layer "Defensie en overheid" and the jets. No single free source covers
// the whole day: adsb.lol allows 500 requests a day per address, and the map asks three times a minute. So the load is
// spread over several open community sources that give the same readsb data, and a source that refuses or fails rests
// for a while. Order of a round: the source whose turn it is, then the others, OpenSky, and last the own Koyeb relay.
//
// - adsb.lol (ODbL): kept to a daily budget (AA_ADSB_LOL_DAG), so it lasts the whole day instead of the first hours.
// - adsb.fi open data: one request a second, no daily limit.
// - airplanes.live: one request a second, free for non-commercial use (A-Alerts is free).
// - OpenSky Network: anonymous 400 credits a day (this area costs 1); only as a reserve, its data lacks type/registration.
// - Koyeb relay (VERKEER_URL/adsb): adsb.lol asked from the Koyeb address, which has its own 500 a day.
// Never the NDW traffic data, never AirAssist or other commercial apps.
declare(strict_types=1);

const AA_ADSB_LAT = 52.2, AA_ADSB_LON = 5.4, AA_ADSB_NM = 160;
/** Requests a day that adsb.lol may get from this server (its limit is 500; some room for the jets and retries). */
const AA_ADSB_LOL_DAG = 400;

/** GET with the ratelimit headers; returns [http code, body string, curl error, headers]. */
function aaAdsbGet(string $url, array $extra = []): array {
  $kop = [];
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: A-Alerts/1.0 (+https://a-alerts.nl)'], $extra),
    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$kop) { $p = strpos($h, ':'); if ($p) $kop[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1)); return strlen($h); }]);
  $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_errno($ch); curl_close($ch);
  if (isset($kop['x-ratelimit-reset'])) $GLOBALS['aa_adsb_reset'] = (int)$kop['x-ratelimit-reset'];
  return [$code, (string)$raw, $cerr, $kop];
}

/** State per source (kv adsb_bronnen): rest until, last result, requests today (adsb.lol). */
function aaAdsbStaat(): array { $s = aaParse(aaKvGet('adsb_bronnen'), null); return is_array($s) ? $s : []; }
function aaAdsbStaatOpslaan(array $s): void { try { aaKvSet('adsb_bronnen', aaJson($s)); } catch (Throwable) {} }

/** May this source be asked now? Not while it rests; adsb.lol also not over its daily budget. */
function aaAdsbMag(array $s, string $id): bool {
  if (aaNow() < (int)($s[$id]['rust_tot'] ?? 0)) return false;
  if ($id === 'adsb.lol') {
    if (($s[$id]['dag'] ?? '') === gmdate('Y-m-d') && (int)($s[$id]['vandaag'] ?? 0) >= AA_ADSB_LOL_DAG) return false;
    // Spread over the day: at most one request per 216 seconds (400 a day), the jets included.
    if (aaNow() - (int)($s[$id]['laatst_gevraagd'] ?? 0) < (int)(86400000 / AA_ADSB_LOL_DAG)) return false;
  }
  return true;
}

/** Notes one request: result, and on a refusal or error a rest (adsb.lol until its reset, at most an hour). */
function aaAdsbNoteer(array &$s, string $id, int $code, int $cerr, array $kop = []): void {
  $r = $s[$id] ?? [];
  if ($id === 'adsb.lol') {
    if (($r['dag'] ?? '') !== gmdate('Y-m-d')) { $r['dag'] = gmdate('Y-m-d'); $r['vandaag'] = 0; }
    $r['vandaag'] = (int)($r['vandaag'] ?? 0) + 1; $r['laatst_gevraagd'] = aaNow();
    if (isset($kop['x-ratelimit-remaining'])) $r['over'] = (int)$kop['x-ratelimit-remaining'];
  }
  $r['laatst'] = aaIso(aaNow()); $r['http'] = $code ?: null; $r['curl'] = $cerr ?: null;
  if ($code === 200) { $r['ok'] = aaIso(aaNow()); $r['fouten'] = 0; unset($r['rust_tot']); }
  else {
    $r['fouten'] = (int)($r['fouten'] ?? 0) + 1;
    // 429: rest until the reset of the source (adsb.lol says when), else a minute; repeated errors: longer, at most 15 min.
    $rust = $code === 429 && $id === 'adsb.lol' ? 60000 * max(1, min(60, (int)ceil(((int)($kop['x-ratelimit-reset'] ?? 60)) / 60)))
      : 60000 * min(15, 2 ** min(4, $r['fouten'] - 1));
    $r['rust_tot'] = aaNow() + $rust;
  }
  $s[$id] = $r;
}

/** readsb body (adsb.lol, adsb.fi, airplanes.live, Koyeb) → ['ac'=>[], 'now'=>ms] or null. */
function aaAdsbReadsb(string $raw): ?array {
  $j = json_decode($raw, true);
  if (!is_array($j)) return null;
  $ac = $j['ac'] ?? $j['aircraft'] ?? null;
  if (!is_array($ac)) return null;
  $n = (float)($j['now'] ?? 0);
  return ['ac' => $ac, 'now' => $n > 0 ? ($n < 1e11 ? (int)($n * 1000) : (int)$n) : aaNow()];
}

/** OpenSky state vectors → readsb-like aircraft (no type or registration: those come from what was learned before). */
function aaAdsbOpenSky(string $raw): ?array {
  $j = json_decode($raw, true);
  if (!is_array($j) || !array_key_exists('states', $j)) return null;
  $now = (int)($j['time'] ?? time()) * 1000; $ac = [];
  foreach ((array)($j['states'] ?? []) as $v) {
    if (!is_array($v) || $v[6] === null || $v[5] === null) continue;
    $pos = (int)($v[3] ?? $v[4] ?? 0);
    $ac[] = ['hex' => strtolower((string)$v[0]), 'flight' => $v[1] !== null ? str_pad(trim((string)$v[1]), 8) : null,
      'lat' => (float)$v[6], 'lon' => (float)$v[5],
      'alt_baro' => !empty($v[8]) ? 'ground' : ($v[7] !== null ? (int)round($v[7] * 3.28084) : null),
      'gs' => $v[9] !== null ? round($v[9] * 1.943844, 1) : null, 'track' => $v[10],
      'baro_rate' => $v[11] !== null ? (int)round($v[11] * 196.8504) : null, 'squawk' => $v[14] ?? null,
      'seen_pos' => $pos ? max(0, ($now / 1000) - $pos) : 0, 'seen' => max(0, ($now / 1000) - (int)($v[4] ?? $pos))];
  }
  return ['ac' => $ac, 'now' => $now];
}

/** The sources of the area query: id => [name for the map, url, headers, parser]. */
function aaAdsbGebiedBronnen(): array {
  $lat = AA_ADSB_LAT; $lon = AA_ADSB_LON; $nm = AA_ADSB_NM;
  $key = setting('ADSB_LOL_API_KEY');
  $b = [
    'adsb.lol' => ['adsb.lol (ODbL)', "https://api.adsb.lol/v2/point/$lat/$lon/$nm", $key !== '' ? ['api-auth: ' . $key] : [], 'aaAdsbReadsb'],
    'adsb.fi' => ['adsb.fi', "https://opendata.adsb.fi/api/v2/lat/$lat/lon/$lon/dist/$nm", [], 'aaAdsbReadsb'],
    'airplanes.live' => ['airplanes.live', "https://api.airplanes.live/v2/point/$lat/$lon/$nm", [], 'aaAdsbReadsb'],
    // Box around the same circle (about 300 km): 1 credit per request.
    'opensky' => ['OpenSky Network', 'https://opensky-network.org/api/states/all?lamin=50.8&lomin=3.0&lamax=53.7&lomax=7.6', [], 'aaAdsbOpenSky'],
  ];
  if (setting('VERKEER_URL') !== '' && setting('VERKEER_TOKEN') !== '')
    $b['koyeb'] = ['adsb.lol (ODbL)', rtrim(setting('VERKEER_URL'), '/') . "/adsb?lat=$lat&lon=$lon&nm=$nm", ['Authorization: Bearer ' . setting('VERKEER_TOKEN')], 'aaAdsbReadsb'];
  return $b;
}

/**
 * One area round: the source whose turn it is (adsb.fi, airplanes.live and adsb.lol take turns), then the others, then
 * OpenSky and the Koyeb relay. Returns ['ac','now','bron','via','http'] or throws with the codes of every source asked.
 */
function aaAdsbGebied(): array {
  $s = aaAdsbStaat(); $alle = aaAdsbGebiedBronnen();
  $draai = ['adsb.fi', 'airplanes.live', 'adsb.lol'];
  $beurt = (int)($s['_beurt'] ?? 0); $s['_beurt'] = ($beurt + 1) % count($draai);
  $volgorde = array_merge(array_slice($draai, $beurt % count($draai)), array_slice($draai, 0, $beurt % count($draai)), ['opensky', 'koyeb']);
  $fouten = [];
  foreach ($volgorde as $id) {
    if (!isset($alle[$id]) || !aaAdsbMag($s, $id)) continue;
    [$naam, $url, $kop, $parser] = $alle[$id];
    [$code, $raw, $cerr, $hdr] = aaAdsbGet($url, $kop);
    $body = $code === 200 ? $parser($raw) : null;
    if ($code === 200 && !$body) $code = 502;
    aaAdsbNoteer($s, $id, $code, $cerr, $hdr);
    if ($body) { aaAdsbProbeerReserve($s, $alle, $id); aaAdsbStaatOpslaan($s); return $body + ['bron' => $naam, 'via' => $id, 'http' => 200]; }
    $fouten[] = $id . ' ' . ($code ?: 'curl ' . $cerr);
  }
  aaAdsbStaatOpslaan($s);
  throw new RuntimeException('ADS-B: geen bron beschikbaar' . ($fouten ? ' (' . implode(', ', $fouten) . ')' : ' (alle bronnen in rust)'));
}

/**
 * Every 30 minutes one test request to a reserve (OpenSky, Koyeb relay) that was not asked in that time, so /docs and the
 * beheer show whether the reserves work before they are needed. Costs OpenSky and Koyeb about 48 requests a day each.
 */
function aaAdsbProbeerReserve(array &$s, array $alle, string $gebruikt): void {
  foreach (['opensky', 'koyeb'] as $id) {
    if ($id === $gebruikt || !isset($alle[$id]) || aaNow() - (aaMs($s[$id]['laatst'] ?? null) ?? 0) < 1800000) continue;
    if (!aaAdsbMag($s, $id)) continue;
    [, $url, $kop, $parser] = $alle[$id];
    [$code, $raw, $cerr, $hdr] = aaAdsbGet($url, $kop);
    aaAdsbNoteer($s, $id, $code === 200 && $parser($raw) ? 200 : ($code === 200 ? 502 : $code), $cerr, $hdr);
    return; // one test per round
  }
}

/**
 * One aircraft by registration, worldwide (the jets): airplanes.live, adsb.fi, then adsb.lol within its budget.
 * Returns [readsb body or null, source id, http code].
 */
function aaAdsbRegistratie(string $reg): array {
  $s = aaAdsbStaat(); $key = setting('ADSB_LOL_API_KEY'); $r = rawurlencode($reg);
  $bronnen = [
    'airplanes.live' => ["https://api.airplanes.live/v2/reg/$r", []],
    'adsb.fi' => ["https://opendata.adsb.fi/api/v2/registration/$r", []],
    'adsb.lol' => ["https://api.adsb.lol/v2/reg/$r", $key !== '' ? ['api-auth: ' . $key] : []],
  ];
  $code = 0; $laatst = null;
  foreach ($bronnen as $id => [$url, $kop]) {
    if (!aaAdsbMag($s, $id)) continue;
    // adsb.fi and airplanes.live allow one request a second per address: the area query may have just been sent.
    if ($id !== 'adsb.lol') usleep(1100000);
    [$code, $raw, $cerr, $hdr] = aaAdsbGet($url, $kop); $laatst = $id;
    $body = $code === 200 ? aaAdsbReadsb($raw) : null;
    aaAdsbNoteer($s, $id, $body ? 200 : ($code === 200 ? 502 : $code), $cerr, $hdr);
    if ($body) { aaAdsbStaatOpslaan($s); return [$body, $id, 200]; }
  }
  aaAdsbStaatOpslaan($s);
  return [null, $laatst ?? 'geen', $code];
}

/** For /v1/eigen and the beheer: per source the last result, rest and (adsb.lol) the count of today. */
function aaAdsbOverzicht(): array {
  $s = aaAdsbStaat(); unset($s['_beurt']); $uit = [];
  foreach ($s as $id => $r) $uit[$id] = ['laatst' => $r['laatst'] ?? null, 'http' => $r['http'] ?? null, 'laatste_ok' => $r['ok'] ?? null,
    'rust_tot' => isset($r['rust_tot']) && $r['rust_tot'] > aaNow() ? aaIso((int)$r['rust_tot']) : null]
    + ($id === 'adsb.lol' ? ['vandaag' => (int)($r['vandaag'] ?? 0), 'budget' => AA_ADSB_LOL_DAG, 'over_bij_adsb_lol' => $r['over'] ?? null] : []);
  return $uit;
}
