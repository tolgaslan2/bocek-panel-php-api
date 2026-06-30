<?php
// Olası yol/dizin hatalarını sayfada görebilmek için hata gösterimini açıyoruz
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Config dosyanızı çağırıyoruz (Domain sabiti ve DB ayarları buradan gelecek)
require_once '../api/config.php';

function create_jwt($siteDomain) {
    $secretKey = '9f2d8a7c4b6e1d0f3a5c8e7b2d9f6a1c4e8b0d3f7a9c2e5d6b1a4f8c0e3d7a9b';

    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);

    // Token her istekte farklı (rastgele) üretilecek
    $payload = json_encode([
        'SiteDomain'  => $siteDomain,
        'jti'         => bin2hex(random_bytes(16)), // Eşsiz kod
        'iat'         => time()                     // Oluşturulduğu saniye
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secretKey, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

// 1. Yeni ve rastgele Token'ı Oluştur
$myToken = create_jwt(Domain);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Token'ı Veritabanına Kaydet (Varsa GÜNCELLE, Yoksa EKLE)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'sqlsrv:server=' . $config['db']['host'] . ';database=' . $config['db']['name'],
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $ipAddress  = '127.0.0.1'; // Veya $_SERVER['REMOTE_ADDR']
    $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $expireDate = date('Y-m-d H:i:s', strtotime('+1 year'));

    // Eğer UserId = 0 olan kayıt varsa Token'ı güncelle, yoksa yeni kayıt oluştur
    $sqlUpsert = "
        IF EXISTS (SELECT 1 FROM AuthToken WHERE UserId = 0)
        BEGIN
            UPDATE AuthToken 
            SET Token = :Token1, 
                ExpireDate = :ExpireDate1, 
                IpAdress = :IpAdress1, 
                UserAgent = :UserAgent1,
                CreatedOn = GETDATE()
            WHERE UserId = 0
        END
        ELSE
        BEGIN
            INSERT INTO AuthToken (
                Token, UserId, ExpireDate, IpAdress, UserAgent, 
                CurrentUsername, CurrentPassword, CurrentEmail
            ) 
            VALUES (
                :Token2, 0, :ExpireDate2, :IpAdress2, :UserAgent2, 
                NULL, '', NULL
            )
        END
    ";

    $stmt = $pdo->prepare($sqlUpsert);

    // SQL Server PDO hatasını önlemek için parametreleri 1 ve 2 olarak çoğalttık
    $stmt->execute([
        // UPDATE kısmı için veriler
        ':Token1'      => $myToken,
        ':ExpireDate1' => $expireDate,
        ':IpAdress1'   => substr($ipAddress, 0, 50),
        ':UserAgent1'  => substr($userAgent, 0, 300),

        // INSERT kısmı için veriler
        ':Token2'      => $myToken,
        ':ExpireDate2' => $expireDate,
        ':IpAdress2'   => substr($ipAddress, 0, 50),
        ':UserAgent2'  => substr($userAgent, 0, 300)
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'DB Hatası: ' . $e->getMessage()]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Başarılı Sonucu Çıktı Ver
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'token'  => $myToken
]);
?>