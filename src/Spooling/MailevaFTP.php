<?php
/**
 * Appel pour dépose des fichiers sur le FTP de Maileva
 *
 * Created by PhpStorm.
 * User: vincent
 * Date: 26/09/17
 * Time: 15:59
 * PHP version 7.1
 *
 * @category Spooling
 * @package  Resiliation
 * @author   Vincent <VinC_12@icloud.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */

namespace maileva\Spooling;

use App\Models\Errors;
use App\Models\Spool;
use App\WebServices\MailevaServiceFTP;
use Illuminate\Support\Facades\Storage;

/**
 * Class MailevaFTP
 *
 * @category Spooling
 * @package  App\Models\Spooling
 * @author   Vincent <VinC_12@icloud.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */
class MailevaFTP extends MailevaServiceFTP
{
    /**
     * Dossier retour notification maileva
     */
    private const FOLDER_RETOUR_MAILEVA = 'retour_www1.basse';

    /**
     * Courrier accepté
     */
    private const ACCEPT = 'ACCEPT';

    /**
     * Pris en charge par la poste
     */
    private const OK = 'OK';

    /**
     * Constant chemin pour le xml
     */
    private const STORAGE_RETOUR_MAILEVA = 'spools/retour-maileva/';

    /**
     *  Enregistre les xml de retour envoyer par maileva dans Storage/app/spools/retour_maileva.
     *  Enregistre en bdd le DipositId de maileva afin de pouvoir procédé au suivi d'un spool.
     *
     */
    public function prepareDocument()
    {
        // Connexion au sevreur ftp.
        $ftp = $this->connectorFtp();
        $files = $ftp->all(self::FOLDER_RETOUR_MAILEVA);
        if (!$files) {
            return "Il n'y a pas de fichiers sur le ftp";
        }
        //Sauvegarde le fichier xml dans Storage/app/spools/retour_maileva
        foreach ($files as $file) {
            //Récupère le nom du fichier xml
            $explodeFile = explode('/', $file);
            $explodeName = explode('.', $explodeFile[1]);
            //Récupère le nom du fichier sans l'extension
            $fileName = $explodeName[0];
            if ($explodeName[1] === 'xml') {
                $content = $ftp->get(self::FOLDER_RETOUR_MAILEVA . '/' . $fileName . '.xml');
                // Supprime les tbsb: du xml pour le parser
                Storage::put(self::STORAGE_RETOUR_MAILEVA . $fileName . '.xml', str_replace('tnsb:', '', $content));
                // Parse le xml et sauvegarde en bdd le DepositId de maileva
                $this->updateSpool($fileName);
            }
        }
    }

    /**
     * Parse le xml et sauvegarde en bdd le DepositId de maileva
     * @param $fileName
     */
    private function updateSpool($fileName): void
    {
        $xml = simplexml_load_string(file_get_contents(Storage::path(self::STORAGE_RETOUR_MAILEVA . $fileName . '.xml')));
        $request = $xml->Request;

        if ($request->Status == self::ACCEPT || $request->Status == self::OK) {
            //Rename le xml
            $this->moveFtpFile($fileName,$request->TrackId);
            $spool = Spool::where('num_unique', $request->TrackId )->firstOrFail();
            $spool->update(['spool_maileva' => $request->DepositId]);
        } else {
            //Rename le xml
            $this->moveFtpFile($fileName,$request->TrackId);
            $error = Errors::firstOrNew(['ref' => $request->TrackId,
            'code_error' => $request->ErrorCode,
            'message' => $request->ErrorLabel]);
            $error->save();
        }

    }

    /**
     * Rename le xml pour une meillieur suivi.
     * @param $fileName
     * @param $trackId
     */
    private function moveFtpFile($fileName,$trackId): void
    {
        if (Storage::exists(self::STORAGE_RETOUR_MAILEVA . $trackId . '.xml')) {
            Storage::delete(self::STORAGE_RETOUR_MAILEVA . $trackId . '.xml');
        }
        Storage::move(self::STORAGE_RETOUR_MAILEVA . $fileName . '.xml', self::STORAGE_RETOUR_MAILEVA . $trackId . '.xml');
    }
}