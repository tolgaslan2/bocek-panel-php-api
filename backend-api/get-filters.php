<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require '../api/config.php';

try {
    // 1. Veritabanı Bağlantısı (config.php'den gelen ayarlarla tek seferde kuruluyor)
    $pdo = new PDO(
        'sqlsrv:server=' . $config['db']['host'] . ';database=' . $config['db']['name'],
        $config['db']['user'],
        $config['db']['pass']
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $response = [];
    $langSuffix = ''; // İleride dil desteği gerekirse diye saklandı

    // 2. Villa Tiplerini Çekme
    $stmtTypes = $pdo->query("SELECT id, baslik{$langSuffix} as baslik FROM tip WHERE aktif=1 AND search{$langSuffix}=1 AND cat != 0 ORDER BY siralama ASC");
    $response['types'] = $stmtTypes->fetchAll();

    // 3. Özellikleri Çekme
    $stmtFeatures = $pdo->query("SELECT id, baslik{$langSuffix} as baslik FROM ozellikler WHERE aktif{$langSuffix}=1 ORDER BY siralama ASC");
    $response['features'] = $stmtFeatures->fetchAll();

    // 4. Bölgeleri Çekme (Hiyerarşik Ağaç Yapısı)
    $stmtDests = $pdo->query("SELECT id, baslik{$langSuffix} as baslik, cat FROM destinations WHERE aktif=1 ORDER BY siralama ASC");
    $allDestinations = $stmtDests->fetchAll();

    $destMap = [];
    $regions = [];

    // Referans haritasını hazırlama
    foreach ($allDestinations as $dest) {
        $dest['sub_regions'] = [];
        $destMap[$dest['id']] = $dest;
    }

    // Alt-Üst ilişkisini kurma
    foreach ($allDestinations as $dest) {
        if ($dest['cat'] == 0) {
            $regions[] = &$destMap[$dest['id']]; // Doğrudan index'siz ekleyerek array_values ihtiyacını kaldırıyoruz
        } else {
            if (isset($destMap[$dest['cat']])) {
                $destMap[$dest['cat']]['sub_regions'][] = &$destMap[$dest['id']];
            }
        }
    }

    $response['regions'] = $regions;

    // 5. Statik Veriler (Kişi Sayısı, Para Birimi, Sıralama vs.)
    $response['static_filters'] = [
        'currencies' => [
            ['id' => 'tl', 'label' => 'TL'],
            ['id' => 'dolar', 'label' => 'USD'],
            ['id' => 'euro', 'label' => 'EUR'],
            ['id' => 'pound', 'label' => 'GBP']
        ],
        'capacities' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        'order_by' => [
            ['id' => 0, 'label' => 'Gelişmiş Sıralama'],
            ['id' => 1, 'label' => 'Tarihe Göre (Önce En Eski)'],
            ['id' => 2, 'label' => 'Tarihe Göre (Önce En Yeni)'],
            ['id' => 3, 'label' => 'Fiyata Göre (Önce En Düşük)'],
            ['id' => 4, 'label' => 'Fiyata Göre (Önce En Yüksek)'],
            ['id' => 5, 'label' => 'Kişiye Göre (Önce En Az)'],
            ['id' => 6, 'label' => 'Kişiye Göre (Önce En Çok)']
        ],
        'gavel_rules' => [
            ['id' => 1, 'label' => '7464 Satışa Açık Süreli Belgeli Emlaklar'],
            ['id' => 2, 'label' => '7464 Satışa Açık Süresiz Belgeli Emlaklar'],
            ['id' => 3, 'label' => '7464 Belgesiz Emlaklar'],
            ['id' => 0, 'label' => 'Tümü']
        ],
        'calendar_rules' => [
            ['id' => 0, 'label' => 'Tümü'],
            ['id' => 1, 'label' => 'Takvim Kuralına Göre']
        ]

    ];

    // Temiz çıktı için JSON_UNESCAPED_UNICODE ekledik
    echo json_encode(['status' => 'success', 'data' => $response], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Veritabanı hatası oluştu.',
        'debug' => $e->getMessage() // Canlı yayına alırken burayı gizlemeyi unutmayın
    ], JSON_UNESCAPED_UNICODE);
}
?>