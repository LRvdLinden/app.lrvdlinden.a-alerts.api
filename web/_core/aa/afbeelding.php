<?php
declare(strict_types=1);
/**
 * GET /v1/meldingen/{guid}/afbeelding.png?formaat=og|vierkant|staand: a share image of one alert (PHP GD).
 * og 1200x630 (link previews), vierkant 1080x1350 (Instagram post), staand 1080x1920 (story).
 * Service colour and icon, priority, the alert text, place and time, the A-Alerts brand, and a map from the PDOK BRT
 * background map (open data, "Kaart: PDOK/Kadaster"); never OpenStreetMap tiles. An ambulance alert gets a map of the
 * neighbourhood, not of the street. Without a place, or when PDOK does not answer, the image has no map (no fake map).
 * Cached on disk per alert, size and content.
 */
require_once __DIR__ . '/meldingen.php';

const AA_BEELD_VERSIE = 2;
const AA_BEELD_FORMATEN = ['og' => [1200, 630], 'vierkant' => [1080, 1350], 'staand' => [1080, 1920]];
const AA_BEELD_KLEUR = ['brandweer' => [227, 6, 19], 'ambulance' => [244, 196, 0], 'politie' => [21, 66, 139], 'traumaheli' => [147, 51, 234], 'lifeliner' => [147, 51, 234]];
const AA_BEELD_NAAM = ['brandweer' => 'Brandweer', 'ambulance' => 'Ambulance', 'politie' => 'Politie', 'traumaheli' => 'Traumaheli', 'lifeliner' => 'Traumaheli'];

function aaBeeldMap(): string { $d = __DIR__ . '/../cache'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function aaBeeldFont(string $w): string { return __DIR__ . '/beeld/Poppins-' . $w . '.ttf'; }

function aaAfbeeldingRoute(string $guid, string $formaat): never {
  if (!isset(AA_BEELD_FORMATEN[$formaat])) $formaat = 'og';
  $m = aaGetMelding($guid);
  if (!$m) aaReply(404, ['error' => 'Not found']);
  $sleutel = md5(AA_BEELD_VERSIE . '|' . $formaat . '|' . $m['title'] . '|' . $m['locatie'] . '|' . $m['lat'] . '|' . $m['lon'] . '|' . $m['prioriteit']);
  $pad = aaBeeldMap() . '/beeld-' . preg_replace('/[^\w-]/', '', $guid) . '-' . $formaat . '-' . $sleutel . '.png';
  if (!is_file($pad)) {
    $png = aaAfbeeldingMaak($m, $formaat);
    @file_put_contents($pad . '.tmp', $png); @rename($pad . '.tmp', $pad);
    aaBeeldOpruimen();
  } else $png = (string)file_get_contents($pad);
  http_response_code(200);
  header('Content-Type: image/png');
  header('Access-Control-Allow-Origin: *');
  header('Cache-Control: public, max-age=3600');
  header('Content-Length: ' . strlen($png));
  echo $png; exit;
}

/** Keeps the cache small: images older than 14 days and tiles older than 30 days go. At most once an hour. */
function aaBeeldOpruimen(): void {
  $vlag = aaBeeldMap() . '/.opgeruimd'; if (is_file($vlag) && time() - filemtime($vlag) < 3600) return; @touch($vlag);
  foreach (glob(aaBeeldMap() . '/beeld-*.png') ?: [] as $f) if (time() - filemtime($f) > 14 * 86400) @unlink($f);
  foreach (glob(aaBeeldMap() . '/tegel-*.png') ?: [] as $f) if (time() - filemtime($f) > 30 * 86400) @unlink($f);
}

/** One PDOK BRT tile (pastel, EPSG:3857), from the disk cache when present. Null when PDOK does not answer. */
function aaBeeldTegel(int $z, int $x, int $y): ?GdImage {
  $pad = aaBeeldMap() . "/tegel-$z-$x-$y.png";
  if (!is_file($pad)) {
    $ch = curl_init("https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/pastel/EPSG:3857/$z/$x/$y.png");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => ['User-Agent: A-Alerts/2.0 (+https://a-alerts.nl)']]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c !== 200 || !is_string($b) || strlen($b) < 100) return null;
    @file_put_contents($pad, $b);
  }
  $im = @imagecreatefrompng($pad);
  return $im ?: null;
}

/** Map of w x h around lat/lon at zoom z, from PDOK tiles, with a marker. Null when a tile is missing. */
function aaBeeldKaart(float $lat, float $lon, int $z, int $w, int $h, array $kleur, bool $precies): ?GdImage {
  $n = 2 ** $z;
  $px = ($lon + 180) / 360 * $n * 256;
  $py = (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n * 256;
  $x0 = $px - $w / 2; $y0 = $py - $h / 2;
  $kaart = imagecreatetruecolor($w, $h);
  for ($tx = (int)floor($x0 / 256); $tx <= (int)floor(($x0 + $w - 1) / 256); $tx++) {
    for ($ty = (int)floor($y0 / 256); $ty <= (int)floor(($y0 + $h - 1) / 256); $ty++) {
      $t = aaBeeldTegel($z, $tx, $ty);
      if (!$t) return null;
      imagecopy($kaart, $t, (int)round($tx * 256 - $x0), (int)round($ty * 256 - $y0), 0, 0, 256, 256);
    }
  }
  $cx = (int)round($w / 2); $cy = (int)round($h / 2);
  [$r, $g, $b] = $kleur;
  if ($precies) {
    imagefilledellipse($kaart, $cx, $cy, 46, 46, imagecolorallocatealpha($kaart, $r, $g, $b, 80));
    imagefilledellipse($kaart, $cx, $cy, 26, 26, imagecolorallocate($kaart, 255, 255, 255));
    imagefilledellipse($kaart, $cx, $cy, 18, 18, imagecolorallocate($kaart, $r, $g, $b));
  } else {
    // Neighbourhood only: a wide circle, no pin on a house.
    imagesetthickness($kaart, 4);
    imagefilledellipse($kaart, $cx, $cy, 220, 220, imagecolorallocatealpha($kaart, $r, $g, $b, 100));
    imageellipse($kaart, $cx, $cy, 220, 220, imagecolorallocate($kaart, $r, $g, $b));
  }
  return $kaart;
}

/** Breaks text into at most $max lines that fit $breed pixels; the last one gets an ellipsis when it is cut. */
function aaBeeldRegels(string $tekst, string $font, float $pt, int $breed, int $max): array {
  $woorden = preg_split('/\s+/u', trim($tekst)) ?: []; $regels = []; $r = '';
  foreach ($woorden as $w) {
    $probeer = $r === '' ? $w : "$r $w";
    $bb = imagettfbbox($pt, 0, $font, $probeer);
    if ($bb[2] - $bb[0] <= $breed || $r === '') { $r = $probeer; continue; }
    $regels[] = $r; $r = $w;
    if (count($regels) === $max) { $r = ''; break; }
  }
  if ($r !== '') $regels[] = $r;
  $afgekapt = count($regels) > $max || count($woorden) > count(preg_split('/\s+/u', implode(' ', $regels)) ?: []);
  $regels = array_slice($regels, 0, $max);
  if ($afgekapt && $regels) {
    $l = array_pop($regels);
    while (mb_strlen($l) > 1) { $bb = imagettfbbox($pt, 0, $font, $l . '…'); if ($bb[2] - $bb[0] <= $breed) break; $l = mb_substr($l, 0, -1); }
    $regels[] = rtrim($l) . '…';
  }
  return $regels;
}

function aaBeeldTekst(GdImage $im, string $t, string $font, float $pt, int $x, int $y, int $kleur): int {
  imagettftext($im, $pt, 0, $x, $y, $kleur, $font, $t);
  $bb = imagettfbbox($pt, 0, $font, $t); return $bb[2] - $bb[0];
}

/** The text of the image: the alert without the priority code and capcodes at the start/end. */
function aaBeeldOmschrijving(array $m): string {
  $t = trim((string)($m['title'] ?? ''));
  $t = (string)preg_replace('/^(?:P\s?[0-3]|A[0-2]|B[1-3]|PRIO\s?[0-3])\b\s*/iu', '', $t);
  // Capcodes and the incident number at the end, but not the ride or GMS number itself ("Rit 360058").
  $t = (string)preg_replace('/(?<!Rit)(?<!GMS)(?<!Rit:)(\s+\d{6,7})+\s*$/iu', '', $t);
  return $t !== '' ? $t : 'P2000-melding';
}

function aaAfbeeldingMaak(array $m, string $formaat): string {
  [$W, $H] = AA_BEELD_FORMATEN[$formaat];
  $dienst = (string)($m['dienst'] ?? '');
  $kleur = AA_BEELD_KLEUR[$dienst] ?? [220, 38, 38];
  $im = imagecreatetruecolor($W, $H);
  imagealphablending($im, true); imagesavealpha($im, false);
  $wit = imagecolorallocate($im, 255, 255, 255); $tekst = imagecolorallocate($im, 17, 17, 24); $grijs = imagecolorallocate($im, 107, 107, 120);
  $lijn = imagecolorallocate($im, 230, 230, 236); $dkleur = imagecolorallocate($im, ...$kleur);
  imagefilledrectangle($im, 0, 0, $W, $H, $wit);
  $s = $W / 1200; if ($formaat !== 'og') $s = $W / 1000;
  $bold = aaBeeldFont('Bold'); $med = aaBeeldFont('Medium'); $reg = aaBeeldFont('Regular');

  // Map area.
  // With only the centre of the place (most ambulance alerts): a map of the place with a circle, never a pin.
  $heeftPlek = ($m['lat'] ?? null) !== null && ($m['lon'] ?? null) !== null;
  $centrum = !empty($m['plaatscentrum']);
  $ambu = $dienst === 'ambulance';
  $z = !empty($m['hm']) ? 14 : ($centrum ? 12 : ($ambu ? 13 : 15));
  if ($formaat === 'og') { $kaartVak = [$W - 520, 0, 520, $H]; $tekstBreed = $W - 520 - 2 * (int)(56 * $s); }
  elseif ($formaat === 'vierkant') { $kaartVak = [0, (int)($H * 0.52), $W, $H - (int)($H * 0.52) - (int)(110 * $s)]; $tekstBreed = $W - 2 * (int)(64 * $s); }
  else { $kaartVak = [0, (int)($H * 0.46), $W, (int)($H * 0.40)]; $tekstBreed = $W - 2 * (int)(64 * $s); }
  $kaart = $heeftPlek ? aaBeeldKaart((float)$m['lat'], (float)$m['lon'], $z, $kaartVak[2], $kaartVak[3], $kleur, !$ambu && !$centrum) : null;
  if ($kaart) {
    imagecopy($im, $kaart, $kaartVak[0], $kaartVak[1], 0, 0, $kaartVak[2], $kaartVak[3]);
    // Attribution on the map, as PDOK asks.
    $f = 12 * max(1, $s * 0.9); $txt = 'Kaart: PDOK/Kadaster';
    $bb = imagettfbbox($f, 0, $reg, $txt); $bw = $bb[2] - $bb[0];
    $bx = $kaartVak[0] + $kaartVak[2] - $bw - 18; $by = $kaartVak[1] + $kaartVak[3] - 14;
    imagefilledrectangle($im, $bx - 8, $by - (int)$f - 8, $bx + $bw + 8, $by + 6, imagecolorallocatealpha($im, 255, 255, 255, 30));
    imagettftext($im, $f, 0, $bx, $by, $grijs, $reg, $txt);
  } else {
    {
      // No map: a light panel in the service colour with its icon (decoration, clearly not a map).
      [$kx, $ky, $kw, $kh] = $kaartVak;
      imagefilledrectangle($im, $kx, $ky, $kx + $kw, $ky + $kh, imagecolorallocate($im, (int)(255 - (255 - $kleur[0]) * 0.12), (int)(255 - (255 - $kleur[1]) * 0.12), (int)(255 - (255 - $kleur[2]) * 0.12)));
      $ip = __DIR__ . '/beeld/icon_' . ($dienst === 'lifeliner' ? 'traumaheli' : $dienst) . '.png';
      if (is_file($ip) && ($ic = @imagecreatefrompng($ip))) { $ih = (int)($kh * 0.42); $iw = (int)round(imagesx($ic) * $ih / imagesy($ic));
        imagecopyresampled($im, $ic, $kx + (int)(($kw - $iw) / 2), $ky + (int)(($kh - $ih) / 2), 0, 0, $iw, $ih, imagesx($ic), imagesy($ic)); }
    }
  }

  // Service band at the top.
  $pad = (int)(($formaat === 'og' ? 56 : 64) * $s);
  $band = (int)(14 * $s);
  imagefilledrectangle($im, 0, 0, $formaat === 'og' ? $W - 520 : $W, $band, $dkleur);
  $y = $band + (int)(58 * $s);
  $iconPad = __DIR__ . '/beeld/icon_' . ($dienst === 'lifeliner' ? 'traumaheli' : $dienst) . '.png';
  $x = $pad;
  if (is_file($iconPad) && ($ic = @imagecreatefrompng($iconPad))) {
    $ih = (int)(52 * $s); $iw = (int)round(imagesx($ic) * $ih / imagesy($ic));
    imagecopyresampled($im, $ic, $x, $y - (int)(40 * $s), 0, 0, $iw, $ih, imagesx($ic), imagesy($ic));
    $x += $iw + (int)(16 * $s);
  }
  $x += aaBeeldTekst($im, AA_BEELD_NAAM[$dienst] ?? 'P2000', $bold, 26 * $s, $x, $y, $tekst) + (int)(18 * $s);
  $prio = strtoupper(trim((string)($m['prioriteit'] ?? '')));
  if ($prio !== '') {
    $pt = 20 * $s; $bb = imagettfbbox($pt, 0, $bold, $prio); $pw = $bb[2] - $bb[0] + (int)(26 * $s);
    $tekstOpKleur = $dienst === 'ambulance' ? $tekst : $wit;
    imagefilledrectangle($im, $x, $y - (int)(32 * $s), $x + $pw, $y + (int)(10 * $s), $dkleur);
    imagettftext($im, $pt, 0, $x + (int)(13 * $s), $y + (int)(2 * $s), $tekstOpKleur, $bold, $prio);
  }

  // The alert text.
  $y += (int)(40 * $s);
  $groot = $formaat === 'og' ? 40 : 52;
  $max = $formaat === 'og' ? 4 : ($formaat === 'vierkant' ? 4 : 5);
  $pt = $groot * $s; $regels = aaBeeldRegels(aaBeeldOmschrijving($m), $bold, $pt, $tekstBreed, $max);
  if (count($regels) > 3 && $formaat === 'og') { $pt = 34 * $s; $regels = aaBeeldRegels(aaBeeldOmschrijving($m), $bold, $pt, $tekstBreed, $max); }
  foreach ($regels as $r) { $y += (int)($pt * 1.45); imagettftext($im, $pt, 0, $pad, $y, $tekst, $bold, $r); }

  // Place, road post and time (Dutch local time).
  $tz = new DateTimeZone('Europe/Amsterdam');
  $t = isset($m['datum_tijd']) ? (new DateTimeImmutable((string)$m['datum_tijd']))->setTimezone($tz) : null;
  $maanden = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
  $tijd = $t ? $t->format('j') . ' ' . $maanden[(int)$t->format('n') - 1] . ' ' . $t->format('Y') . ', ' . $t->format('H:i') : '';
  $plaats = trim((string)($m['stad'] ?? ''));
  // An ambulance alert: never the street in the image.
  $straat = !$ambu ? trim((string)($m['locatie'] ?? '')) : '';
  $regel = implode(' · ', array_filter([!empty($m['hm']['tekst']) ? $m['hm']['tekst'] : ($straat !== '' && mb_strtolower($straat) !== mb_strtolower($plaats) ? $straat : ''), $plaats]));
  $y += (int)(30 * $s);
  if ($regel !== '') { $y += (int)(30 * $s); foreach (aaBeeldRegels($regel, $med, 22 * $s, $tekstBreed, 2) as $r) { imagettftext($im, 22 * $s, 0, $pad, $y, $grijs, $med, $r); $y += (int)(34 * $s); } $y -= (int)(34 * $s); }
  if ($tijd !== '') { $y += (int)(38 * $s); imagettftext($im, 22 * $s, 0, $pad, $y, $grijs, $reg, $tijd); }

  // Brand at the bottom.
  $voetY = $formaat === 'og' ? $H - (int)(48 * $s) : $H - (int)(48 * $s);
  if ($formaat !== 'og') imagefilledrectangle($im, 0, $H - (int)(110 * $s), $W, $H, $wit);
  imageline($im, $pad, $voetY - (int)(52 * $s), ($formaat === 'og' ? $W - 520 : $W) - $pad, $voetY - (int)(52 * $s), $lijn);
  $lx = $pad;
  if (($logo = @imagecreatefrompng(__DIR__ . '/beeld/aalerts-icon-180.png'))) {
    $ls = (int)(40 * $s); imagecopyresampled($im, $logo, $lx, $voetY - (int)(32 * $s), 0, 0, $ls, $ls, imagesx($logo), imagesy($logo)); $lx += $ls + (int)(14 * $s);
  }
  $lx += aaBeeldTekst($im, 'A-Alerts', $bold, 22 * $s, $lx, $voetY, $tekst) + (int)(14 * $s);
  aaBeeldTekst($im, 'a-alerts.nl · P2000 live', $reg, 18 * $s, $lx, $voetY, $grijs);

  ob_start(); imagepng($im, null, 7); $png = (string)ob_get_clean();
  imagedestroy($im); if ($kaart) imagedestroy($kaart);
  return $png;
}
