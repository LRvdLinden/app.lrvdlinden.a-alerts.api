<?php
// One private jet, recorded only: PH-UTL (Max Verstappen, ICAO hex 4867e6). A-Alerts never shows it on a map or in a
// list; the positions only go into the table jet_posities. Asked once a minute by hex (worldwide), from adsb.lol while
// it has requests left (500 a day per address, shared with the helicopters), otherwise from adsb.fi.
declare(strict_types=1);

const AA_JETS = [['hex' => '4867e6', 'registratie' => 'PH-UTL']];

function aaJetHexen(): array { return array_map(fn($j) => $j['hex'], AA_JETS); }

function aaJetHaal(string $url, array $extra = []): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: A-Alerts/1.0 (+https://a-alerts.nl)'], $extra)]);
  $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = $code === 200 ? json_decode((string)$raw, true) : null;
  return [$code, is_array($j) ? $j : null];
}

/** Returns how many new positions were stored. */
function aaJetTick(): int {
  $db = aaDb(); $nieuw = 0;
  foreach (AA_JETS as $jet) {
    $body = null; $bron = 'adsb.lol';
    if (aaNow() >= (int)(aaKvGet('adsb_pauze_tot') ?? 0)) {
      $key = setting('ADSB_LOL_API_KEY');
      [$code, $body] = aaJetHaal('https://api.adsb.lol/v2/hex/' . $jet['hex'], $key !== '' ? ['api-auth: ' . $key] : []);
      if ($code === 429) aaKvSet('adsb_pauze_tot', (string)(aaNow() + 60000));
    }
    if (!is_array($body['ac'] ?? null)) {
      [$code, $body] = aaJetHaal('https://opendata.adsb.fi/api/v2/hex/' . $jet['hex']);
      $bron = 'adsb.fi';
      if (!is_array($body)) { aaKvSet('jet_fout', aaJson(['at' => aaIso(aaNow()), 'http' => $code])); continue; }
    }
    $n = (float)($body['now'] ?? 0); $now = $n > 0 ? ($n < 1e11 ? (int)($n * 1000) : (int)$n) : aaNow();
    foreach (($body['ac'] ?? $body['aircraft'] ?? []) as $ac) {
      if (!is_array($ac) || strtolower((string)($ac['hex'] ?? '')) !== $jet['hex']) continue;
      $p = aaPositie($ac); if (!$p) continue;
      $at = $now - (int)round($p['oud'] * 1000);
      $grond = ($ac['alt_baro'] ?? null) === 'ground';
      $st = $db->prepare('INSERT IGNORE INTO jet_posities (hex, registratie, callsign, type, at, lat, lon, hoogte_ft, op_grond, snelheid_kt, koers, stijgsnelheid_fpm, squawk, bron, ontvangen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $st->execute([$jet['hex'], aaOr($ac['r'] ?? null) ?: $jet['registratie'], trim((string)($ac['flight'] ?? '')) ?: null, aaOr($ac['t'] ?? null),
        $at, $p['lat'], $p['lon'], $grond ? 0 : aaRond($ac['alt_baro'] ?? null), $grond ? 1 : 0, aaRond($ac['gs'] ?? null),
        aaRond($ac['track'] ?? null) ?? aaRond($ac['true_heading'] ?? null), aaRond($ac['baro_rate'] ?? null), aaOr($ac['squawk'] ?? null), $bron, aaNow()]);
      $nieuw += $st->rowCount();
    }
    aaKvSet('jet_laatste', aaJson(['at' => aaIso(aaNow()), 'bron' => $bron, 'nieuw' => $nieuw]));
  }
  return $nieuw;
}
