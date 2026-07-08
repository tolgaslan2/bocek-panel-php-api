<?php

declare(strict_types=1);

namespace App\Core;

/*
 * GitHub'daki repodan doğrudan .zip arşivi indirip mevcut kurulumun üzerine
 * yazan, git binary'sine / shell_exec'e ihtiyaç duymayan güncelleyici.
 * Paylaşımlı hosting'de (git yok, exec kapalı) çalışacak şekilde tasarlandı.
 *
 * Akış: branch -> son commit SHA (GitHub API) -> .zip indir -> aç ->
 *       yedekle -> backend-api/ üzerine kopyala (asla silme, sadece üstüne yaz) ->
 *       durumu kaydet.
 *
 * NOT: config/app.local.php arşivde asla bulunmaz (git'e girmediği için),
 * bu yüzden kopyalama sırasında kendiliğinden dokunulmadan kalır.
 */
final class Updater
{
    /** @var string backend-api kök dizini */
    private $root;

    /** @var array app config (github_owner, github_repo, github_branch, github_token) */
    private $config;

    public function __construct(string $backendRoot, array $config)
    {
        $this->root = rtrim($backendRoot, '/');
        $this->config = $config;
    }

    /**
     * Güncellemeyi çalıştırır. Sonuç: ['updated' => bool, 'from' => ?string, 'to' => string, 'backup' => ?string]
     *
     * @param bool $force true ise SHA aynı olsa bile indirip yeniden kurar (400 sitede
     *                    varsayılan false: gereksiz indirme/yedekleme yapılmaz).
     */
    public function run(bool $force = false): array
    {
        $owner  = $this->config['github_owner'] ?? '';
        $repo   = $this->config['github_repo'] ?? '';
        $branch = $this->config['github_branch'] ?? 'main';
        $token  = $this->config['github_token'] ?? '';

        if ($owner === '' || $repo === '') {
            throw new HttpException('github_owner / github_repo tanımlı değil.', 'UPDATE_CONFIG', 500);
        }

        // İndirme/GitHub isteğinden ÖNCE kontrol et: backend-api/ klasörünün
        // kendisine yazılamıyorsa (IIS AppPool izni yoksa) devam etmenin anlamı yok.
        $this->assertRootWritable();

        $sha = $this->resolveSha($owner, $repo, $branch, $token);
        $previousSha = $this->deployedSha();

        if (!$force && $sha === $previousSha) {
            return ['updated' => false, 'from' => $previousSha, 'to' => $sha, 'backup' => null];
        }

        $tmpZip = $this->downloadZip($owner, $repo, $sha, $token);
        $extractDir = $this->extractZip($tmpZip);
        @unlink($tmpZip);

        try {
            $sourceRoot = $this->findBackendApiRoot($extractDir, $repo, $sha);
            $backupPath = $this->backupCurrent();
            $this->copyOverlay($sourceRoot, $this->root);
            $this->writeState($sha, $previousSha);
            $this->log("OK  {$previousSha} -> {$sha}");
        } finally {
            $this->removeDir($extractDir);
        }

        return [
            'updated' => $previousSha !== $sha,
            'from'    => $previousSha,
            'to'      => $sha,
            'backup'  => $backupPath ?? null,
        ];
    }

    /**
     * backend-api/ klasörünün gerçekten yazılabilir olduğunu, bir dosya
     * yazıp silerek doğrular (is_writable() IIS/impersonation altında
     * yanıltıcı olabilir; gerçek bir yazma denemesi daha güvenilir).
     */
    private function assertRootWritable(): void
    {
        $probe = $this->root . '/.write-test-' . bin2hex(random_bytes(4));

        error_clear_last();
        $ok = @file_put_contents($probe, 'ok') !== false;
        if ($ok) {
            @unlink($probe);

            return;
        }

        $lastError = error_get_last();
        throw new HttpException(
            'backend-api klasörüne yazma izni yok: ' . $this->root
                . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')'
                . ' — IIS Application Pool kimliğine bu klasörde "Modify" izni ver.',
            'UPDATE_NOT_WRITABLE',
            500
        );
    }

    /**
     * Şu an sunucuda kayıtlı olan (son başarılı güncellemenin) commit SHA'sı.
     *
     * @return string|null
     */
    public function deployedSha()
    {
        $state = $this->readState();

        return $state['sha'] ?? null;
    }

    /**
     * @return array{sha:?string, deployed_at:?string, previous_sha:?string}
     */
    public function readState(): array
    {
        $path = $this->stateFile();
        if (!is_file($path)) {
            return ['sha' => null, 'deployed_at' => null, 'previous_sha' => null];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : ['sha' => null, 'deployed_at' => null, 'previous_sha' => null];
    }

    private function resolveSha(string $owner, string $repo, string $branch, string $token): string
    {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/commits/{$branch}";
        [$status, $body] = $this->httpGet($url, $this->githubHeaders($token, 'application/vnd.github+json'));

        if ($status !== 200) {
            throw new HttpException(
                "GitHub'dan son commit alınamadı (HTTP {$status}).",
                'UPDATE_GITHUB',
                502
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['sha'])) {
            throw new HttpException('GitHub yanıtında SHA bulunamadı.', 'UPDATE_GITHUB', 502);
        }

        return (string) $data['sha'];
    }

    private function downloadZip(string $owner, string $repo, string $sha, string $token): string
    {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$sha}";
        [$status, $body] = $this->httpGet($url, $this->githubHeaders($token, 'application/vnd.github+json'), true);

        if ($status !== 200 || $body === '') {
            throw new HttpException(
                "GitHub arşivi indirilemedi (HTTP {$status}).",
                'UPDATE_DOWNLOAD',
                502
            );
        }

        $tmpPath = $this->tmpDir() . '/ghzip_' . bin2hex(random_bytes(6)) . '.zip';

        error_clear_last();
        $written = @file_put_contents($tmpPath, $body);

        if ($written === false) {
            $lastError = error_get_last();
            throw new HttpException(
                'Geçici zip dosyası yazılamadı: ' . $tmpPath
                    . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')'
                    . ' — PHP\'nin backend-api klasörüne yazma izni olduğundan emin ol.',
                'UPDATE_TMP',
                500
            );
        }

        return $tmpPath;
    }

    private function extractZip(string $zipPath): string
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpException('PHP zip eklentisi (ZipArchive) kurulu değil.', 'UPDATE_ZIP_EXT', 500);
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new HttpException(
                "Zip arşivi açılamadı (kod: {$openResult}): {$zipPath}",
                'UPDATE_ZIP_OPEN',
                500
            );
        }

        $extractDir = $this->tmpDir() . '/ghextract_' . bin2hex(random_bytes(6));
        if (!@mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $lastError = error_get_last();
            throw new HttpException(
                'Geçici çıkarma klasörü oluşturulamadı: ' . $extractDir
                    . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')',
                'UPDATE_TMP',
                500
            );
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new HttpException('Zip arşivi açılamadı (extractTo başarısız).', 'UPDATE_ZIP_EXTRACT', 500);
        }
        $zip->close();

        return $extractDir;
    }

    /**
     * Geçici dosyalar için çalışma klasörü. sys_get_temp_dir() yerine site
     * yapısına göre config'ten gelen (git_temp_dir) YAZILABİLİR bir klasör
     * kullanılır — genelde uploads/ altı, çünkü site zaten oraya yazıp CDN'e
     * servis ediyor. Her sitede yol farklı olabileceği için değer
     * config/app.php'den (varsayılan) veya config/app.local.php'den
     * (site-özel override) gelir; IIS/paylaşımlı hosting'deki izin
     * belirsizliklerinden (sistem temp veya backend-api'nin kendi izinleri)
     * bu sayede etkilenmez.
     */
    private function tmpDir(): string
    {
        $dir = $this->config['git_temp_dir'] ?? (dirname($this->root) . '/uploads/git-temp');

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $lastError = error_get_last();
            throw new HttpException(
                'Geçici klasör oluşturulamadı: ' . $dir
                    . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')'
                    . ' — PHP\'nin uploads/ klasörüne yazma izni olduğundan emin ol.',
                'UPDATE_TMP',
                500
            );
        }

        return $dir;
    }

    /**
     * GitHub arşivi "{repo}-{kısa-sha}/" adlı tek bir kök klasörle gelir.
     * Deploy edeceğimiz gerçek içerik onun altındaki backend-api/ klasörüdür.
     */
    private function findBackendApiRoot(string $extractDir, string $repo, string $sha): string
    {
        $entries = array_values(array_diff((array) scandir($extractDir), ['.', '..']));
        $rootFolder = null;

        foreach ($entries as $entry) {
            if (is_dir($extractDir . '/' . $entry)) {
                $rootFolder = $entry;
                break;
            }
        }

        if ($rootFolder === null) {
            throw new HttpException('Arşiv içeriği beklenen formatta değil.', 'UPDATE_ZIP_LAYOUT', 500);
        }

        $backendApiPath = $extractDir . '/' . $rootFolder . '/backend-api';
        if (!is_dir($backendApiPath)) {
            throw new HttpException('Arşivde backend-api klasörü bulunamadı.', 'UPDATE_ZIP_LAYOUT', 500);
        }

        return $backendApiPath;
    }

    /**
     * Mevcut backend-api klasörünü .backups/{timestamp}.zip olarak yedekler,
     * en son 3 yedeği tutar.
     *
     * @return string|null
     */
    private function backupCurrent()
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $backupDir = $this->root . '/.backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupPath = $backupDir . '/' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($backupPath, \ZipArchive::CREATE) !== true) {
            $this->log('UYARI: yedek alınamadı (' . $backupPath . '), güncelleme yedeksiz devam etti.');

            return null;
        }

        $this->addDirToZip($zip, $this->root, '');
        $zip->close();

        $this->pruneBackups($backupDir, 3);

        return $backupPath;
    }

    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.backups') {
                continue;
            }

            $fullPath = $dir . '/' . $entry;
            $zipPath = $prefix === '' ? $entry : $prefix . '/' . $entry;

            if (is_dir($fullPath)) {
                $this->addDirToZip($zip, $fullPath, $zipPath);
            } else {
                $zip->addFile($fullPath, $zipPath);
            }
        }
    }

    private function pruneBackups(string $backupDir, int $keep): void
    {
        $files = glob($backupDir . '/*.zip') ?: [];
        sort($files);
        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * Kaynaktaki her dosyayı hedefin üzerine kopyalar. HİÇBİR ŞEY SİLMEZ —
     * arşivde olmayan yerel dosyalar (config/app.local.php, .backups, .update.log,
     * .deploy-state.json) olduğu gibi kalır.
     */
    private function copyOverlay(string $source, string $dest): void
    {
        foreach ((array) scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . '/' . $entry;
            $to = $dest . '/' . $entry;

            if (is_dir($from)) {
                if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
                    $lastError = error_get_last();
                    throw new HttpException(
                        'Klasör oluşturulamadı: ' . $to
                            . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')',
                        'UPDATE_COPY',
                        500
                    );
                }
                $this->copyOverlay($from, $to);
            } else {
                error_clear_last();
                if (!@copy($from, $to)) {
                    $lastError = error_get_last();
                    throw new HttpException(
                        'Dosya kopyalanamadı: ' . $to
                            . ' (' . ($lastError['message'] ?? 'bilinmeyen hata') . ')',
                        'UPDATE_COPY',
                        500
                    );
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeState(string $sha, $previousSha): void
    {
        $state = [
            'sha'          => $sha,
            'previous_sha' => $previousSha,
            'deployed_at'  => date('Y-m-d H:i:s'),
        ];
        file_put_contents($this->stateFile(), json_encode($state, JSON_PRETTY_PRINT));
    }

    private function stateFile(): string
    {
        return $this->root . '/.deploy-state.json';
    }

    private function log(string $line): void
    {
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
        file_put_contents($this->root . '/.update.log', $entry, FILE_APPEND);
    }

    /**
     * @return array<int,string>
     */
    private function githubHeaders(string $token, string $accept): array
    {
        $headers = [
            'User-Agent: bocek-panel-updater',
            'Accept: ' . $accept,
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @param array<int,string> $headers
     * @return array{0:int,1:string}
     */
    private function httpGet(string $url, array $headers, bool $binary = false): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [$status, $body === false ? '' : $body];
        }

        // curl yoksa: stream context ile dene (basit tek yönlendirme takibi).
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => 60,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [$status, $body === false ? '' : $body];
    }
}
