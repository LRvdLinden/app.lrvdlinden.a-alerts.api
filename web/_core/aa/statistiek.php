<?php
declare(strict_types=1);
/**
 * GET /v1/statistiek?dagen=28[&plaats=NAAM][&eenheid=CODE]: the P2000 figures of the last days, worked out from the
 * stored alerts (no estimates). Test calls and drills do not count, hidden alerts neither. Times in Dutch local time.
 * Kept per question for 15 minutes in kv (stat:...).
 */
require_once __DIR__.'/meldingen.php';

const AA_STAT_TTL = 900000;

/** Same rule as aaIsTestOproep (push.php), plus drills ("oefening"): never in the figures. */
function aaStatTelNiet(array $r): bool {
  $t = (string)(($r['full_melding'] ?? '') ?: ($r['title'] ?? ''));
  return (bool)preg_match('/\btest(oproep|bericht|melding|alarm|page|paging)?\b|\bproef(alarm|oproep)\b|\boefening\b/iu', $t);
}

/** The code of a unit as it is asked for: digits only (capcode), at most 12. */
function aaStatCode(mixed $c): string { return substr((string)preg_replace('/\D/', '', (string)($c ?? '')), 0, 12); }

function aaStatistiek(mixed $dagen = 28, mixed $plaats = null, mixed $eenheid = null): array {
  $dagen = max(1, min(90, (int)($dagen ?: 28)));
  $plaats = trim(mb_substr((string)($plaats ?? ''), 0, 60));
  $code = aaStatCode($eenheid);
  $key = 'stat:' . $dagen . '|' . aaPlaatsKey($plaats) . '|' . $code;
  $oud = aaParse(aaKvGet($key), null);
  if (is_array($oud) && aaNow() - (int)($oud['_at'] ?? 0) < AA_STAT_TTL) { unset($oud['_at']); return $oud; }
  $uit = aaStatistiekReken($dagen, $plaats, $code);
  try { aaKvSet($key, aaJson($uit + ['_at' => aaNow()])); } catch (Throwable) {}
  return $uit;
}

function aaStatistiekReken(int $dagen, string $plaats, string $code): array {
  $tz = new DateTimeZone('Europe/Amsterdam');
  $nu = new DateTimeImmutable('now', $tz);
  $vandaag = $nu->setTime(0, 0);
  // Whole days: today and the $dagen-1 days before it.
  $start = $vandaag->modify('-' . ($dagen - 1) . ' days');
  $sql = 'SELECT m.guid, COALESCE(o.title, m.title) AS title, m.full_melding, m.dienst, m.prioriteit, m.datum, m.stad, m.eenheden
    FROM meldingen m LEFT JOIN overrides o ON o.guid = m.guid WHERE m.datum >= ? AND COALESCE(o.hidden, 0) = 0';
  $arg = [$start->getTimestamp() * 1000];
  if ($code !== '') { $sql .= ' AND m.eenheden LIKE ?'; $arg[] = '%"' . $code . '"%'; }
  $q = aaDb()->prepare($sql); $q->execute($arg);

  $perUur = array_fill(0, 24, 0); $perWeekdag = array_fill(0, 7, 0); $perDienst = []; $perPrio = [];
  $perDag = []; for ($d = $start; $d <= $vandaag; $d = $d->modify('+1 day')) $perDag[$d->format('Y-m-d')] = ['datum' => $d->format('Y-m-d'), 'totaal' => 0, 'per_dienst' => []];
  $plaatsen = []; $eenheden = []; $heli = []; $heliTotaal = 0; $totaal = 0;
  $gisteren = $vandaag->modify('-1 day')->format('Y-m-d');
  require_once __DIR__ . '/heli-lib.php';
  $pk = aaPlaatsKey($plaats);
  foreach ($q->fetchAll() as $r) {
    if (aaStatTelNiet($r)) continue;
    if ($pk !== '' && aaPlaatsKey($r['stad']) !== $pk) continue;
    $eh = aaParse($r['eenheden'] ?? null) ?: [];
    if ($code !== '') { $hit = false; foreach ($eh as $u) if (is_array($u) && aaStatCode($u['code'] ?? '') === $code) { $hit = true; break; } if (!$hit) continue; }
    $t = (new DateTimeImmutable('@' . intdiv((int)$r['datum'], 1000)))->setTimezone($tz);
    $dag = $t->format('Y-m-d'); if (!isset($perDag[$dag])) continue;
    $totaal++;
    $perUur[(int)$t->format('G')]++;
    $perWeekdag[(int)$t->format('N') - 1]++;
    $dienst = (string)(($r['dienst'] ?? '') ?: 'onbekend');
    $perDienst[$dienst] = ($perDienst[$dienst] ?? 0) + 1;
    $prio = strtoupper(trim((string)($r['prioriteit'] ?? '')));
    if ($prio !== '') $perPrio[$prio] = ($perPrio[$prio] ?? 0) + 1;
    $perDag[$dag]['totaal']++; $perDag[$dag]['per_dienst'][$dienst] = ($perDag[$dag]['per_dienst'][$dienst] ?? 0) + 1;
    $st = trim((string)($r['stad'] ?? ''));
    if ($st !== '') { $k = aaPlaatsKey($st); $plaatsen[$k] ??= ['plaats' => $st, 'aantal' => 0]; $plaatsen[$k]['aantal']++; }
    $gezien = [];
    foreach ($eh as $u) {
      if (!is_array($u)) continue; $c = aaStatCode($u['code'] ?? ''); if ($c === '' || isset($gezien[$c])) continue; $gezien[$c] = true;
      $eenheden[$c] ??= ['code' => $c, 'naam' => (string)($u['naam'] ?? $c), 'dienst' => aaStatDienst($u, $dienst), 'aantal' => 0];
      $eenheden[$c]['aantal']++;
    }
    $m = ['dienst' => $dienst, 'title' => $r['title'], 'full_melding' => $r['full_melding'], 'eenheden' => $eh];
    if (aaIsHeliMelding($m)) {
      $heliTotaal++;
      $naam = ($g = aaGenoemd($m)) ? 'Lifeliner ' . substr($g, -1) : 'Traumahelikopter';
      $heli[$naam] = ($heli[$naam] ?? 0) + 1;
    }
  }
  $sorteer = function (array $a): array { usort($a, fn($x, $y) => $y['aantal'] <=> $x['aantal'] ?: strcmp((string)reset($x), (string)reset($y))); return array_slice($a, 0, 10); };
  arsort($perDienst); ksort($perPrio);
  $eerdere = array_values(array_filter($perDag, fn($d) => $d['datum'] < $vandaag->format('Y-m-d')));
  $gem = $eerdere ? array_sum(array_column($eerdere, 'totaal')) / count($eerdere) : 0.0;
  $heliLijst = []; arsort($heli); foreach ($heli as $n => $a) $heliLijst[] = ['naam' => $n, 'aantal' => $a];
  $eerste = (int)(aaDb()->query('SELECT MIN(datum) FROM meldingen')->fetchColumn() ?: 0);
  return [
    'bijgewerkt' => aaIso(aaNow()), 'dagen' => $dagen,
    'plaats' => $plaats !== '' ? $plaats : null, 'eenheid' => $code !== '' ? $code : null,
    'totaal' => $totaal, 'per_uur' => $perUur, 'per_weekdag' => $perWeekdag,
    'per_dienst' => (object)$perDienst, 'per_prioriteit' => (object)$perPrio,
    'per_dag' => array_map(fn($d) => $d + ['per_dienst' => (object)$d['per_dienst']], array_values($perDag)),
    'top_plaatsen' => $sorteer(array_values($plaatsen)), 'top_eenheden' => $sorteer(array_values($eenheden)),
    'gisteren' => ['totaal' => $perDag[$gisteren]['totaal'] ?? 0, 'gemiddeld' => round($gem, 1)],
    'helikopters' => ['inzetten' => $heliTotaal, 'per_toestel' => $heliLijst],
    // Since when alerts are stored: with fewer days of data the figures cover a shorter period.
    'gegevens_sinds' => $eerste ? aaIso($eerste) : null,
  ];
}

function aaStatDienst(array $u, string $meldingDienst): string {
  $t = mb_strtolower((string)($u['type'] ?? ''));
  return match (true) { str_contains($t, 'brandweer') => 'brandweer', str_contains($t, 'ambulance') => 'ambulance', str_contains($t, 'politie') => 'politie', default => $meldingDienst };
}
