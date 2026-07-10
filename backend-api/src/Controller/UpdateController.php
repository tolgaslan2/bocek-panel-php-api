<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Updater;

/*
 * Kendi kendini güncelleme kaynağı.
 *   POST /backend-api/update          -> repodaki EN SON git tag'ini indirir, kurar
 *                                         (body: {"version":"1.4.0"} verilirse O tag kurulur)
 *   GET  /backend-api/update/status   -> şu an kurulu sürüm/commit'i gösterir (indirmez)
 *
 * Sürümlendirme git tag'lerine dayanır: "git tag 1.4.0 && git push --tags" ile
 * gönderdiğin her tag, o an kurulabilecek bir sürümdür. Repoda hiç tag yoksa
 * (henüz hiç etiketlenmediyse) branch'in son commit'i kurulur.
 *
 * DİKKAT: Bu uç, müşteri Bearer token'ından (AuthToken) BAĞIMSIZ, kendi
 * "X-Deploy-Secret" header'ıyla korunur (config/app.local.php -> deploy_secret).
 * 400 site aynı şekilde çalışır; her sitenin kendi app.local.php'si vardır ve
 * güncelleme bu dosyaya asla dokunmaz.
 */
final class UpdateController extends Controller
{
    /**
     * @Post
     * @body version string Belirli bir git tag kur (örn. "1.4.0"). Boşsa: her zaman EN SON tag.
     * @body force bool SHA aynı olsa bile yeniden indirip kur (varsayılan: hayır)
     */
    public function deploy(): void
    {
        $this->assertSecret();

        $force = (string) $this->request->query('force', $this->request->input('force', '')) === '1';

        $version = (string) $this->request->query('version', $this->request->input('version', ''));
        $version = $version !== '' ? $version : null;

        $updater = new Updater(dirname(__DIR__, 2), $this->app);
        $result = $updater->run($force, $version);

        $this->response->success($result);
    }

    /**
     * @Get("status")
     */
    public function status(): void
    {
        $this->assertSecret();

        $updater = new Updater(dirname(__DIR__, 2), $this->app);

        $this->response->success($updater->readState());
    }

    private function assertSecret(): void
    {
        $expected = (string) ($this->app['deploy_secret'] ?? '');
        $isPlaceholder = $expected === '' || $expected === 'BURAYA_GUCLU_RASTGELE_BIR_DEGER_YAZ';

        if ($isPlaceholder) {
            throw new HttpException(
                'deploy_secret tanımlı değil. config/app.local.php oluşturup ayarla.',
                'UPDATE_NOT_CONFIGURED',
                500
            );
        }

        $given = (string) $this->request->header('X-Deploy-Secret');

        if ($given === '' || !hash_equals($expected, $given)) {
            throw new HttpException('Geçersiz deploy secret.', 'UPDATE_FORBIDDEN', 403);
        }
    }
}
