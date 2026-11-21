<?php
// Jets that are recorded worldwide, three times a minute, by registration: PH-UTL (Max Verstappen, hex 4867e6; never shown in
// A-Alerts) and PH-GOV (the government aircraft). Positions only go into the table jet_posities. From airplanes.live,
// adsb.fi or adsb.lol (within its daily budget), see aa/adsb-bronnen.php.
declare(strict_types=1);
require_once __DIR__ . '/adsb-bronnen.php';

const AA_JETS = [
  ['hex' => '4867e6', 'registratie' => 'PH-UTL', 'soort' => 'bijzonder', 'icoon' => 'verstappen', 'naam' => 'Max Verstappen (PH-UTL)'],
  ['hex' => null, 'registratie' => 'PH-GOV', 'soort' => 'regering', 'icoon' => 'phgov', 'naam' => 'Regeringsvliegtuig PH-GOV'],
];


/** Returns how many new positions were stored. */
function aaJetTick(): int {
  $db = aaDb(); $nieuw = 0;
  foreach (AA_JETS as $jet) {
    // airplanes.live, adsb.fi, then adsb.lol within its daily budget (aa/adsb-bronnen.php).
    [$body, $bron, $code] = aaAdsbRegistratie($jet['registratie']);
    if (!is_array($body)) { aaKvSet('jet_fout', aaJson(['at' => aaIso(aaNow()), 'toestel' => $jet['registratie'], 'bron' => $bron, 'http' => $code])); $ronde[$jet['registratie']] = ['bron' => $bron, 'http' => $code]; continue; }
    $now = (int)$body['now'];
    foreach ($body['ac'] as $ac) {
      if (!is_array($ac)) continue;
      $zelfde = strtoupper(trim((string)($ac['r'] ?? ''))) === $jet['registratie'] || ($jet['hex'] && strtolower((string)($ac['hex'] ?? '')) === $jet['hex']);
      if (!$zelfde) continue;
      $p = aaPositie($ac); if (!$p) continue;
      $at = $now - (int)round($p['oud'] * 1000);
      $grond = ($ac['alt_baro'] ?? null) === 'ground';
      $st = $db->prepare('INSERT IGNORE INTO jet_posities (hex, registratie, callsign, type, at, lat, lon, hoogte_ft, op_grond, snelheid_kt, koers, stijgsnelheid_fpm, squawk, bron, ontvangen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $st->execute([strtolower((string)($ac['hex'] ?? $jet['hex'] ?? '')), $jet['registratie'], trim((string)($ac['flight'] ?? '')) ?: null, aaOr($ac['t'] ?? null),
        $at, $p['lat'], $p['lon'], $grond ? 0 : aaRond($ac['alt_baro'] ?? null), $grond ? 1 : 0, aaRond($ac['gs'] ?? null),
        aaRond($ac['track'] ?? null) ?? aaRond($ac['true_heading'] ?? null), aaRond($ac['baro_rate'] ?? null), aaOr($ac['squawk'] ?? null), $bron, aaNow()]);
      $nieuw += $st->rowCount();
    }
    $ronde[$jet['registratie']] = ['bron' => $bron, 'http' => $code ?? null];
  }
  aaKvSet('jet_laatste', aaJson(['at' => aaIso(aaNow()), 'nieuw' => $nieuw, 'per_toestel' => $ronde ?? []]));
  return $nieuw;
}

/**
 * The jets as /v1/lucht aircraft, worldwide, last known position of the past 10 minutes, same fields as the other aircraft.
 * PH-GOV is always in /v1/lucht (also for A-Alerts itself); Max only with ?bijzonder=1 (112Heli), never on the A-Alerts map.
 */
function aaJetToestellen(bool $metBijzonder): array {
  $out = [];
  $q = aaDb()->prepare('SELECT * FROM jet_posities WHERE registratie = ? AND at >= ? ORDER BY at DESC LIMIT 1');
  foreach (AA_JETS as $jet) {
    if ($jet['soort'] === 'bijzonder' && !$metBijzonder) continue;
    $q->execute([$jet['registratie'], aaNow() - 600000]); $r = $q->fetch(); if (!$r) continue;
    $out[] = ['id' => 'lucht-' . $r['hex'], 'soort' => $jet['soort'], 'icoon' => $jet['icoon'], 'naam' => $jet['naam'],
      'callsign' => $r['callsign'] ?: null, 'registratie' => $jet['registratie'], 'type' => $r['type'] ?: null, 'hex' => $r['hex'],
      'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'koers' => $r['koers'] === null ? null : (int)$r['koers'],
      'hoogte_ft' => $r['hoogte_ft'] === null ? null : (int)$r['hoogte_ft'], 'op_grond' => (bool)$r['op_grond'],
      'snelheid_kt' => $r['snelheid_kt'] === null ? null : (int)$r['snelheid_kt'], 'gezien' => aaIso((int)$r['at']), 'wereldwijd' => true];
  }
  return $out;
}
