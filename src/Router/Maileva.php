<?php
/**
 * Gestion de l'envoi des documents et du tracking via le système de routage de
 * Maileva.
 *
 * User: vivien
 * Date: 23/10/17
 * Time: 09:30
 *
 * PHP version 7.1
 */

namespace maileva\Router;

use App\Events\Accept;
use App\Events\Poste;
use App\Models\Router\Router;
use App\Models\Spool;
use maileva\Spooling\MailevaFTP;
use App\Models\Tracking;
use maileva\WebServices\MailevaServiceFTP;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Class maileva
 *
 * @package App\Models\Router
 *
 */
class Maileva extends Router
{
    /**
     * Constant chemin pour le pdf
     */
    private const STORAGE_PDF = 'spools/pdf/';

    /**
     * Constant chemin pour le xml
     */
    private const STORAGE_MAILEVA = 'spools/maileva/';

    /**
     * Constant chemin pour le xml
     */
    private const STORAGE_RETOUR_MAILEVA = 'spools/retour-maileva/';

    /**
     * Génère et envoie les fichiers des courriers avec Maileva
     *
     * @param Spool $spool
     * @throws \Exception
     */
    public function sendDocument(Spool $spool): void
    {
        // Préparation des document avec règle maileva
        $explodeName = explode('.', $spool->link_xml);
        $fileName = $explodeName[0];
        rename(Storage::path(self::STORAGE_PDF . $fileName . '.pdf'), Storage::path(self::STORAGE_PDF . $fileName . '.001'));
        rename(Storage::path(self::STORAGE_MAILEVA . $fileName . '.xml'), Storage::path(self::STORAGE_MAILEVA . $fileName . '.002'));
        $pdfPath = Storage::path(self::STORAGE_PDF . $fileName . '.001');
        $xmlPath = Storage::path(self::STORAGE_MAILEVA . $fileName . '.002');
        // Zip des documents + envoie des documents sur le ftp (Courrier zcou)
        $zip = $this->makeZip($fileName, $pdfPath, $xmlPath);
        //On referme le zip pour s'assurer que le zip a bien été créé
        $zip->close();
        try {
            if (Storage::exists('zip/' . $fileName . '.tmp')) {
                $content = file_get_contents(Storage::path('zip/' . $fileName . '.tmp'));
                $result = Storage::disk('ftp')->put($fileName . '.tmp', $content);
                if ($result === true) {
                    // Connexion au serveur ftp.
                    $maileva = new MailevaServiceFTP();
                    $ftp = $maileva->connectorFtp();
                    $ftp->rename($fileName . '.tmp', $fileName . '.zcou');
                }
            }
        } catch (\Exception $e) {
            Log::alert($e->__toString());
        }

    }

    /**
     * Update le suvi pour un envoi effectué par Maileva
     */
    public function updateTracking(): void
    {
        $tracking = new MailevaFTP();
        $tracking->prepareDocument();
        $spools = Spool::where('completed', false)->where('created_at', '>', new Carbon('last month'))->get();
        $spools->map(function (Spool $spool) {
            if ($spool->spool_maileva !== null) {
                try {
                    if (Storage::exists(self::STORAGE_RETOUR_MAILEVA . $spool->num_unique . '.xml')) {
                        $track = $this->tracking($spool);
                        //Update le tracking et envoie des mails pour chaque étapes
                        $this->event($track);
                    }
                } catch (ModelNotFoundException $e) {
                    Log::error($e->getMessage() . '- fichier' . __FILE__ . '- line' . __LINE__);
                } catch (\InvalidArgumentException $e) {
                    Log::error($e->getMessage() . '- fichier' . __FILE__ . '- line' . __LINE__);
                }
            }
        });
        $this->trackingLaPoste();
    }

    /**
     * Création du fichier xml de configuration de routage maileva.
     *
     * @param int $count nombre de pdf du spool
     * @param Spool $spool
     * @return string renvoi render()
     * @throws \Throwable
     */
    public function createXml(int $count, Spool $spool): string
    {
        $spooling = $spool->letters[0];
        return view(Spool::TEMPLATE_FILE_MAILEVA, ['spool' => $spooling, 'count' => $count])->render();
    }

    /**
     * Sauvegarde des .xml dans le répertoire storage/app/spools/maileva.
     *
     * @param Spool $spool
     * @param string $content
     * @return bool
     */
    public function saveXml(Spool $spool, string $content): bool
    {
        try {
            $filename = $spool->getFilenameXml();
            return Storage::put(Spool::STORAGE_DIR_MAILEVA . $filename, $content);
        } catch (\Exception $e) {
            Log::critical("Can't save ini file: " . $e->getMessage());
            die;
        }
    }

    /**
     * Génération du fichier zip pour dépôt sur le serveu Maileva
     * Des exceptions de type \Exception peuvent également être lancée par le
     * système en cas d'échec
     *
     * @param string $fileName
     * @param string $pdfFileName
     * @param string $xmlFileName
     * @return \ZipArchive
     * @throws \RuntimeException
     */
    protected function makeZip(string $fileName, string $pdfFileName, string $xmlFileName): \ZipArchive
    {
        $zip = new \ZipArchive();
        if (!$zip->open(storage::path('zip/') . $fileName . '.tmp', \ZipArchive::CREATE)) {
            throw new \RuntimeException("Can't create zip file", 1000);
        }
        $add = $zip->addFile($pdfFileName, $fileName . '.001');
        $add &= $zip->addFile($xmlFileName, $fileName . '.002');
        if (!$add && !$zip->close()) {
            throw new \RuntimeException("Can't generate zip file", 1001);
        }
        return $zip;
    }

    /**
     * Suivi du courrier chez maileva.
     *
     * @param Spool $spool
     * @return array
     */
    private function tracking(Spool $spool): array
    {
        $xml = simplexml_load_string(file_get_contents(Storage::path(self::STORAGE_RETOUR_MAILEVA . $spool->num_unique . '.xml')));
        $request = $xml->Request;

        if ($request->Status == Tracking::ACCEPT || $request->Status == Tracking::OK) {
            return array(
                'spool' => $request->TrackId,
                'spool_maileva' => $request->DepositId,
                'status' => $request->Status,
                'date' => $request->ReceptionDate,
                'num' => $request->ErlNumbers
            );
        }
        return array(
            'spool' => $request->TrackId,
            'spool_maileva' => $request->DepositId,
            'status' => $request->Status,
            'date' => $request->ReceptionDate,
        );
    }

    /**
     * Lance les évènements détectés lors du suivi
     *
     * @param array $spool
     */
    private function event(array $spool): void
    {
        switch ($spool['status']) {
            case Tracking::ACCEPT:
                event(new Accept($spool));
                break;
            case Tracking::POSTE:
                event(new Poste($spool));
                break;
        }
    }

}