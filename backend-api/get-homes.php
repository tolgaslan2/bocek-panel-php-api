<?php
/*
 * Villa Arama API Endpoint
 * villaAra.asp → PHP REST API dönüşümü
 *
 * Kullanım: GET /get-homes.php?site=1&start=2026-07-01&end=2026-07-14&...
 */
// ─────────────────────────────────────────────────────────────────────────────
// Başlıklar
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Yardımcı Fonksiyonlar
// ─────────────────────────────────────────────────────────────────────────────
function respond($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function p($key, $default = '') {
    $val = isset($_GET[$key]) ? $_GET[$key] : (isset($_POST[$key]) ? $_POST[$key] : $default);
    return trim((string)$val);
}

function pInt($key, $default = 0) {
    $v = p($key);
    return is_numeric($v) ? (int)$v : $default;
}

function parseDate($s) {
    if ($s === '') return null;
    foreach (array('Y-m-d', 'd.m.Y', 'm/d/Y') as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d !== false) {
            $d->setTime(0, 0, 0);
            return $d;
        }
    }
    return null;
}

function d104($d) { return $d ? $d->format('d.m.Y') : ''; }
function dISO($d) { return $d ? $d->format('Y-m-d') : ''; }

function safeIntList($s) {
    $parts = array_map('intval', explode(',', $s));
    $valid = array_filter($parts, function($v) { return $v > 0; });
    return $valid ? implode(',', $valid) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Veritabanı Bağlantısı ve Konfigürasyon (backend-api-config Üzerinden)
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/backend-api-config.php';

// ─────────────────────────────────────────────────────────────────────────────
// Parametreleri Oku & Doğrula
// ─────────────────────────────────────────────────────────────────────────────
$now = new DateTime();

$siteId = pInt('site', 1);
$site   = isset($SITES[$siteId]) ? $SITES[$siteId] : (isset($SITES[1]) ? $SITES[1] : ['dbtable' => '']);
$dbt    = isset($site['dbtable']) ? $site['dbtable'] : '';

$startRaw = p('start') ? p('start') : p('searchdate1');
$endRaw   = p('end')   ? p('end')   : p('searchdate2');

$hasDate = ($startRaw !== '' || $endRaw !== '');

$tarih1 = parseDate($startRaw);
$tarih2 = parseDate($endRaw);

if ($hasDate) {
    if ($tarih1 === null) { $tarih1 = clone $now; }
    if ($tarih2 === null) { $tarih2 = clone $tarih1; $tarih2->modify('+7 days'); }
}

$netTarih = p('netTarih', '1');
$season   = pInt('season', (int)$now->format('n'));

if ($hasDate && $netTarih === '0') {
    $year   = (int)$now->format('Y');
    $tarih1 = new DateTime("{$year}-{$season}-01");
    $tarih2 = clone $tarih1;
    $tarih2->modify('last day of this month');
}

$t1  = d104($tarih1);
$t2  = d104($tarih2);
$gun = ($tarih1 && $tarih2) ? (int)$tarih1->diff($tarih2)->days : 0;

$nd1 = '';
$nd2 = '';
if ($tarih1 && $tarih2) {
    $nd1Clone = clone $tarih1; $nd1Clone->modify('+1 day');
    $nd2Clone = clone $tarih2; $nd2Clone->modify('-1 day');
    $nd1 = dISO($nd1Clone);
    $nd2 = dISO($nd2Clone);
}

$min       = pInt('min', 0);
$max       = pInt('max', 0);
$priceType = pInt('priceType', 0);

// ─────────────────────────────────────────────────────────────────────────────
// Döviz Çevirici ve Kur Eşleştirme
// ─────────────────────────────────────────────────────────────────────────────
$dovizRaw  = p('doviz');
$eskiKur   = p('kur');

if ($eskiKur !== '') {
    if ($eskiKur == '1' || $eskiKur == '0') { $dovizRaw = 'tl'; }
    elseif ($eskiKur == '2') { $dovizRaw = 'dolar'; }
    elseif ($eskiKur == '3') { $dovizRaw = 'euro'; }
    elseif ($eskiKur == '4' || $eskiKur == '5') { $dovizRaw = 'pound'; }
}

$doviz = in_array($dovizRaw, array('tl', 'dolar', 'euro', 'pound')) ? $dovizRaw : 'tl';

$hedefKur = 1.0;
if ($doviz !== 'tl') {
    try {
        $rateStmt = $pdo->prepare("SELECT rate FROM rate WHERE CurrencyName = ?");
        $rateStmt->execute(array($doviz));
        $rateRow = $rateStmt->fetch();
        if ($rateRow && (float)$rateRow['rate'] > 0) {
            $hedefKur = (float)$rateRow['rate'];
        }
    } catch (PDOException $e) {
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FİYAT ARALIĞI — ASP ile birebir aynı mantık:
//   priceType=1 → min=1,     max=20000
//   priceType=2 → min=20000, max=50000
//   priceType=3 → min=50000, max=100000
//   priceType yoksa → URL'deki min/max parametrelerini kullan, TL'ye çevir
// ─────────────────────────────────────────────────────────────────────────────
if ($priceType === 1) {
    $minTL = 1;
    $maxTL = 20000;
} elseif ($priceType === 2) {
    $minTL = 20000;
    $maxTL = 50000;
} elseif ($priceType === 3) {
    $minTL = 50000;
    $maxTL = 100000;
} else {
    $minTL = (int)round($min * $hedefKur);
    $maxTL = (int)round($max * $hedefKur);
}

// ─────────────────────────────────────────────────────────────────────────────
// Parametreler ve TIP Yakalayıcı (Ayrı ayrı tip=7&tip=12 gönderimi için)
// ─────────────────────────────────────────────────────────────────────────────
$kisi = pInt('kisi', 0);

// BİRDEN FAZLA "tip" PARAMETRESİNİ YAKALAMA MANTIĞI
$tipValues = [];
$rawQuery = $_SERVER['QUERY_STRING'] ?? '';
if (!empty($rawQuery)) {
    $pairs = explode('&', $rawQuery);
    foreach ($pairs as $pair) {
        $kv = explode('=', $pair);
        if (count($kv) >= 2 && urldecode($kv[0]) === 'tip') {
            $val = trim(urldecode($kv[1]));
            if ($val !== '') {
                $tipValues[] = $val;
            }
        }
    }
}
// Eğer URL'den "tip" parametreleri birden çok geldiyse virgülle birleştir (Örn: "7,12")
// Gelmediyse standart $_GET/$_POST kontrolüne başvur.
$tipx = !empty($tipValues) ? implode(',', $tipValues) : p('tip');

$tip2         = p('tip2', '1');
$bolge        = p('bolge');
$ozellik      = p('ozellik');
$villaadi     = p('villaadi');
$orderBy      = pInt('order_by', 0);

$gKuraliParam = p('gavelkurali');
if ($gKuraliParam === '') $gKuraliParam = '1';

$takvimKuraliReq = p('takvimkurali');
$geceRaw    = p('gece', '');
$kisasureli = (p('kisasureli') === '1') || (is_numeric($geceRaw) && (int)$geceRaw > 0);
$geceSql    = $geceRaw !== '' ? (safeIntList($geceRaw) ? safeIntList($geceRaw) : '2,3,4,5,6') : '2,3,4,5,6';

$page    = max(1, pInt('page', 1));
$perPage = max(1, pInt('per_page', 50));
$offset  = ($page - 1) * $perPage;

// ─────────────────────────────────────────────────────────────────────────────
// Dinamik SQL Parçaları (Takvim & Müsaitlik)
// ─────────────────────────────────────────────────────────────────────────────
$takvimSelect = "0 AS gecemax, 0 AS sezongece,";
$takvimCross  = "";
$takvimWhere  = "";

if ($hasDate) {
    if ($takvimKuraliReq === '1') {
        $takvimSelect = "0 AS gecemax, sezon.gece AS sezongece,";
        $takvimCross = "CROSS APPLY (
            SELECT TOP 1 (CASE WHEN ISNULL(gece,'')='' THEN 0 ELSE gece END) AS gece,
            ISNULL((SELECT DATEDIFF(day, MAX(CONVERT(date, dd.tarih2, 104)), CONVERT(date, '{$t1}', 104)) FROM dolu dd WHERE dd.emlak = h.id AND dd.durum = 3 AND CONVERT(date, dd.tarih2, 104) <= CONVERT(date, '{$t1}', 104)), gece) AS girisara,
            ISNULL((SELECT DATEDIFF(day, CONVERT(date, '{$t2}', 104), MIN(CONVERT(date, dd.tarih, 104))) FROM dolu dd WHERE dd.emlak = h.id AND dd.durum = 3 AND CONVERT(date, dd.tarih, 104) >= CONVERT(date, '{$t2}', 104)), gece) AS cikisara
            FROM sezonlar
            WHERE site = 1
              AND islem = 'emlak'
              AND islem_id = h.id
              AND LEN(tarih2) = 10
              AND CONVERT(date, '{$t1}', 104) >= CONVERT(date, sezonlar.tarih1, 104)
              AND CONVERT(date, '{$t1}', 104) <= CONVERT(date, sezonlar.tarih2, 104)
        ) AS sezon ";

        $takvimWhere = " AND (sezon.girisara >= sezon.gece OR sezon.girisara = 0)
                         AND (sezon.cikisara >= sezon.gece OR sezon.cikisara = 0)
                         AND DATEDIFF(day, CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104)) >= sezon.gece ";
    } else {
        if ($gun !== 0) {
            $takvimCross = "CROSS APPLY (
                SELECT TOP 1 (CASE WHEN ISNULL(gece,'')='' THEN 0 ELSE gece END) AS gece
                FROM sezonlar
                WHERE site = 1
                  AND islem = 'emlak'
                  AND islem_id = h.id
                  AND LEN(tarih2) = 10
                  AND CONVERT(date, '{$t1}', 104) >= CONVERT(date, sezonlar.tarih1, 104)
                  AND CONVERT(date, '{$t1}', 104) <= CONVERT(date, sezonlar.tarih2, 104)
            ) AS sezon ";
            $takvimSelect = " sezon.gece, (CASE WHEN DATEDIFF(day, CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104)) >= sezon.gece THEN 0 ELSE 1 END) AS gecemax, sezon.gece AS sezongece, ";
        }
    }
}

// Fiyat Hesaplama mantığı
if (!$hasDate) {
    $dsql2 = "SELECT ISNULL((SELECT TOP 1 fiyat FROM sezonlar WHERE islem_id = h.id AND site = 1 ORDER BY fiyat ASC), 1) AS sqfiyat";
} elseif ($netTarih === '0') {
    $dsql2 = "SELECT nettarih.fiyat AS sqfiyat";
} elseif ($kisasureli) {
    $dsql2 = "SELECT dbo.Fn_yenifiyathesapla(kisasureli.tarih, kisasureli.tarih2, h.id, {$siteId}) AS sqfiyat";
} else {
    $dsql2 = "SELECT dbo.Fn_yenifiyathesapla(CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104), h.id, 1) AS sqfiyat";
}

$ksqlx = '';
$ksql  = '';
if ($hasDate && $kisasureli) {
    $ksqlx = "CONVERT(varchar, kisasureli.tarih,  104) AS tarih1, CONVERT(varchar, kisasureli.tarih2, 104) AS tarih2,";
    $ksql = "CROSS APPLY (
        SELECT TOP 1
            tarih2 AS tarih,
            DATEADD(DAY,
                DATEDIFF(DAY, tarih2,
                    (SELECT MIN(d2.tarih) FROM dolu d2
                     WHERE d2.emlak = dolu.emlak
                       AND d2.durum = 3
                       AND d2.tarih >= dolu.tarih2)
                ),
                tarih2
            ) AS tarih2
        FROM dolu
        WHERE emlak = h.id
          AND durum = 3
          AND DATEDIFF(DAY, tarih2,
                (SELECT MIN(d2.tarih) FROM dolu d2
                 WHERE d2.emlak = dolu.emlak
                   AND d2.durum = 3
                   AND d2.tarih >= dolu.tarih2)
              ) IN ({$geceSql})
          AND CONVERT(date, tarih,  103) >= CONVERT(date, GETDATE(), 103)
          AND CONVERT(date, tarih2, 103) >= CONVERT(date, GETDATE(), 103)
    ) AS kisasureli";
}

$nettarihSql = '';
if ($hasDate && $netTarih === '0') {
    $maxForNet   = $maxTL > 0 ? $maxTL : 99999999;
    $currentYear = (int)$now->format('Y');
    $nettarihSql = "CROSS APPLY (
        SELECT TOP 1 *
            FROM dbo.musaitlik({$season}, {$season}, {$currentYear}, h.id, {$siteId})
        WHERE fiyat * rate.rate BETWEEN {$minTL} AND {$maxForNet}
          AND (
              SELECT TOP 1 COUNT(dolu.id) FROM dolu
              WHERE dolu.emlak = h.id
                AND dolu.durum = 3
                AND (    (DATEADD(day, -1, tarih2) BETWEEN dolu.tarih AND dolu.tarih2)
                      OR (DATEADD(day, +1, tarih1) BETWEEN dolu.tarih AND dolu.tarih2)
                      OR (dolu.tarih  BETWEEN DATEADD(day, 1, tarih1)  AND DATEADD(day, -1, tarih2))
                      OR (dolu.tarih2 BETWEEN DATEADD(day, 1, tarih1)  AND DATEADD(day, -1, tarih2))
                 )
          ) = 0
    ) AS nettarih";
}

// ─────────────────────────────────────────────────────────────────────────────
// Ana SQL Sorgusu
// ─────────────────────────────────────────────────────────────────────────────
$sql = "
SELECT
    COUNT(*) OVER()         AS totalCount,
    h.yatak_odasi,
    h.id,
    h.url{$dbt}              AS url,
    h.title{$dbt}           AS title,
    h.kisa_icerik{$dbt}     AS kisa_icerik,
    CASE WHEN h.kur{$dbt} > 0 THEN h.kur{$dbt} ELSE 0 END AS kur,
    h.baslik{$dbt}          AS baslik,
    h.baslik                AS basliko,
    h.icerik{$dbt},
    h.enlem,
    h.boylam,
    h.resim,
    h.ribbon{$dbt}          AS ribbon,
    h.ribbon2{$dbt}         AS ribbon2,
    h.yuzme_havuzu,
    h.kisi,
    h.oda_sayisi,
    '{$doviz}'              AS doviz,
    d2.baslik{$dbt}         AS d2baslik,
    {$takvimSelect}
    CAST(ROUND(
        (CASE 
            WHEN h.doviz{$dbt} = '{$doviz}' THEN fiyatlar.sqfiyat
            WHEN h.doviz{$dbt} = 'tl' THEN (fiyatlar.sqfiyat / NULLIF({$hedefKur}, 0))
            WHEN '{$doviz}' = 'tl' THEN (fiyatlar.sqfiyat * (CASE WHEN h.kur{$dbt} > 0 THEN h.kur{$dbt} ELSE rate.rate END))
            ELSE (fiyatlar.sqfiyat * (CASE WHEN h.kur{$dbt} > 0 THEN h.kur{$dbt} ELSE rate.rate END) / NULLIF({$hedefKur}, 0))
        END), 0
    ) AS INT) AS fiyat,
    {$ksqlx}
    h.banyo,
    d1.baslik{$dbt} + ' / ' + d2.baslik{$dbt} AS bolgebaslik,
    mm.val                  AS mm,
    bosluklar.girisbosluk,
    bosluklar.cikisbosluk

FROM homes AS h

INNER JOIN rate ON rate.CurrencyName = h.doviz{$dbt}

{$ksql}
{$nettarihSql}
{$takvimCross}

CROSS APPLY (
    SELECT ISNULL((
        SELECT TOP 1 gece FROM sezonlar
        WHERE site     = 1
          AND islem    = 'emlak'
          AND islem_id = h.id
          " . ($hasDate ? "AND LEN(CONVERT(date, '{$t1}', 104)) >= 8 AND CONVERT(date, '{$t1}', 104) BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)" : "") . "
    ), 0) AS val
) AS mm

CROSS APPLY (
    SELECT
        ISNULL((
            SELECT TOP 1 DATEDIFF(day, CONVERT(date, od.tarih2, 103), " . ($hasDate ? "CONVERT(date, '{$t1}', 103)" : "GETDATE()") . ")
            FROM dolu od
            WHERE od.emlak = h.id
              AND od.Durum = 3
              " . ($hasDate ? "AND CONVERT(date, od.tarih2, 103) <= CONVERT(date, '{$t1}', 104)" : "") . "
              AND CONVERT(date, od.tarih2, 103) >= CONVERT(date, GETDATE(), 103)
            ORDER BY od.tarih2 DESC
        ), 999) AS girisbosluk,
        ISNULL((
            SELECT TOP 1 DATEDIFF(day, " . ($hasDate ? "CONVERT(date, '{$t2}', 103)" : "GETDATE()") . ", CONVERT(date, od.tarih, 103))
            FROM dolu od
            WHERE od.emlak = h.id
              AND od.Durum = 3
              " . ($hasDate ? "AND CONVERT(date, od.tarih, 103) >= CONVERT(date, '{$t2}', 104)" : "") . "
            ORDER BY od.tarih ASC
        ), 999) AS cikisbosluk
) AS bosluklar

CROSS APPLY ({$dsql2}) AS fiyatlar

INNER JOIN tip          t  ON t.id  = h.emlak_tipi
INNER JOIN destinations d2 ON d2.id = h.emlak_bolgesi
INNER JOIN destinations d1 ON d1.id = d2.cat
INNER JOIN destinations d0 ON d0.id = d1.cat
LEFT  JOIN destinations d  ON d.id  = d0.cat
LEFT  JOIN kanun7464   kanun ON kanun.homeId = h.id

WHERE h.aktif{$dbt}   = 1
  AND d2.aktif         = 1
  AND d1.aktif         = 1
  AND t.aktif          = 1
  AND fiyatlar.sqfiyat > 0
  {$takvimWhere}
";

// ─────────────────────────────────────────────────────────────────────────────
// Dinamik WHERE Koşulları
// ─────────────────────────────────────────────────────────────────────────────
$params = array();

if ($gKuraliParam === '1') {
    $sql .= " AND ISNULL(kanun.gavel, 0) = 0 ";
}

// Tarih varsa doluluk kontrolü yapılıyor
if ($hasDate && $netTarih !== '0') {
    if (!$kisasureli) {
        $sql .= "
            AND (
                SELECT TOP 1 COUNT(dolu.id) FROM dolu
                WHERE dolu.emlak = h.id
                  AND dolu.durum = 3
                  AND (    ('{$nd2}' BETWEEN dolu.tarih  AND dolu.tarih2)
                        OR ('{$nd1}' BETWEEN dolu.tarih  AND dolu.tarih2)
                        OR (dolu.tarih  BETWEEN '{$nd1}' AND '{$nd2}')
                        OR (dolu.tarih2 BETWEEN '{$nd1}' AND '{$nd2}')
                   )
            ) = 0";
    } else {
        $sql .= "
            AND (    ('{$nd2}' BETWEEN kisasureli.tarih  AND kisasureli.tarih2)
                  OR ('{$nd1}' BETWEEN kisasureli.tarih  AND kisasureli.tarih2)
                  OR (kisasureli.tarih  BETWEEN '{$nd1}' AND '{$nd2}')
                  OR (kisasureli.tarih2 BETWEEN '{$nd1}' AND '{$nd2}')
            )";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FİYAT FİLTRESİ — $maxTL üzerinden kontrol edilir.
// Böylece priceType=1 gibi gönderilerde $max=0 olsa bile
// $maxTL=20000 set edildiği için filtre doğru çalışır (ASP davranışıyla birebir).
// ─────────────────────────────────────────────────────────────────────────────
if ($maxTL > 0) {
    $sql .= " AND fiyatlar.sqfiyat * rate.rate BETWEEN {$minTL} AND {$maxTL}";
}

$orderByEk = '';
if ($kisi > 0) {
    $orderByEk = " h.kisi ASC, ";
    $sql .= " AND h.kisi >= {$kisi}";
}

if ($tipx !== '' && $tipx !== '0') {
    $tipIds = array_filter(array_map('intval', explode(',', $tipx)), function($v) { return $v > 0; });
    if ($tipIds) {
        if ($tip2 === '2') {
            $parts = array_map(
                function($t) { return "(','+REPLACE(h.kategori,' ','')+',' LIKE '%,{$t},%' OR h.emlak_tipi = {$t})"; },
                $tipIds
            );
            $sql .= " AND (1=2 OR " . implode(' OR ', $parts) . ")";
        } else {
            foreach ($tipIds as $t) {
                $sql .= " AND (','+REPLACE(h.kategori,' ','')+',' LIKE '%,{$t},%' OR h.emlak_tipi = {$t})";
            }
        }
    }
}

if ($bolge !== '' && $bolge !== '0') {
    $bolgeIds = array_filter(array_map('intval', explode(',', $bolge)), function($v) { return $v > 0; });
    if ($bolgeIds) {
        $parts = array_map(function($b) { return "({$b} IN (d2.id, d1.id, d0.id))"; }, $bolgeIds);
        $sql  .= " AND (" . implode(' OR ', $parts) . ")";
    }
}

if ($ozellik !== '') {
    $ozellikIds = array_filter(array_map('intval', explode(',', $ozellik)), function($v) { return $v > 0; });
    foreach ($ozellikIds as $oid) {
        $sql .= " AND '#'+REPLACE(h.ozellikler,' ','')+'#' LIKE '%#{$oid}#%'";
    }
}

if ($villaadi !== '') {
    $params[':villaadi'] = "%{$villaadi}%";
    $sql .= " AND h.baslik LIKE :villaadi";
}

// ─────────────────────────────────────────────────────────────────────────────
// ORDER BY + Sayfalama
// ─────────────────────────────────────────────────────────────────────────────
$isFilterSelected = (
    $hasDate ||
    pInt('kisi') > 0 ||
    p('tip') !== '' ||
    p('bolge') !== '' ||
    p('ozellik') !== '' ||
    p('villaadi') !== ''
);

if ($isFilterSelected) {
    $gelismisOB = "
        (CASE WHEN h.sadece_bizde = 1 AND bosluklar.girisbosluk = 0 AND bosluklar.cikisbosluk = 0 THEN 1 ELSE 0 END) DESC,
        (CASE WHEN h.sadece_bizde = 1 AND ((bosluklar.girisbosluk = 0 AND mm.val <= bosluklar.cikisbosluk) OR (bosluklar.cikisbosluk = 0 AND mm.val <= bosluklar.girisbosluk)) THEN 1 ELSE 0 END) DESC";
    $gelismisOB_prefix = $gelismisOB . ", ";
} else {
    $gelismisOB = "";
    $gelismisOB_prefix = "";
}

switch ($orderBy) {
    case 1:
        $orderClause = $orderByEk . $gelismisOB_prefix . " h.id ASC";
        break;
    case 2:
        $orderClause = $orderByEk . $gelismisOB_prefix . " h.id DESC";
        break;
    case 3:
        $orderClause = $orderByEk . $gelismisOB_prefix . " fiyatlar.sqfiyat * rate.rate ASC";
        break;
    case 4:
        $orderClause = $orderByEk . $gelismisOB_prefix . " fiyatlar.sqfiyat * rate.rate DESC";
        break;
    case 5:
        $orderClause = $gelismisOB_prefix . " h.kisi ASC, h.siralama ASC";
        break;
    case 6:
        $orderClause = $gelismisOB_prefix . " h.kisi DESC, h.siralama ASC";
        break;
    case 0:
    default:
        $orderClause = " h.kisi ASC, " . $gelismisOB_prefix . " h.siralama ASC, h.id ASC";
        break;
}

if (!preg_match('/h\.id (ASC|DESC)\s*$/i', $orderClause)) {
    $orderClause .= ", h.id ASC";
}

$sql .= "\nORDER BY {$orderClause}";
$sql .= "\nOFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

// ─────────────────────────────────────────────────────────────────────────────
// Çalıştır & Yanıt Ver
// ─────────────────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $total = !empty($rows) ? (int)$rows[0]['totalCount'] : 0;
    foreach ($rows as &$row) {
        unset($row['totalCount']);

        if (isset($row['fiyat'])) {
            $row['fiyat'] = (int)round((float)$row['fiyat']);
        }

        if (isset($row['girisbosluk']) && (int)$row['girisbosluk'] === 999) $row['girisbosluk'] = null;
        if (isset($row['cikisbosluk']) && (int)$row['cikisbosluk'] === 999) $row['cikisbosluk'] = null;

        $resimStr = isset($row['resim']) ? $row['resim'] : '';
        // "Cdn" sabiti config.php içerisinden geliyor ve backend-api-config.php
        // config.php'yi içeri aktardığı için sorunsuz çalışacaktır.
        $cdnBase  = Cdn . '/uploads/small/';

        if (!empty($resimStr)) {
            $hamListe = array_values(array_filter(array_map('trim', explode(',', $resimStr))));
            $resimListesi = array_map(function($resim) use ($cdnBase) { return $cdnBase . $resim; }, $hamListe);
            $row['resim_liste'] = $resimListesi;
            $row['kapak_resmi'] = !empty($resimListesi) ? $resimListesi[0] : null;
        } else {
            $row['resim_liste'] = array();
            $row['kapak_resmi'] = null;
        }

        unset($row['resim']);
    }
    unset($row);

    respond(array(
        'success'          => true,
        'total'            => $total,
        'page'             => $page,
        'per_page'         => $perPage,
        'total_pages'      => (int)ceil($total / $perPage),
        'date_from'        => $tarih1 ? dISO($tarih1) : null,
        'date_to'          => $tarih2 ? dISO($tarih2) : null,
        'total_days'       => $gun,
        'results'          => $rows,
    ));
} catch (PDOException $e) {
    respond(array('error' => 'Sorgu başarısız.', 'detail' => $e->getMessage()), 500);
}