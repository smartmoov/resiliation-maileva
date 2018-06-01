<?php
/**
 * Created by PhpStorm.
 * User: stephanepau
 * Date: 28/05/2017
 * Time: 11:32
 * PHP Version 7.1
 *
 * @category MailevaServiceFTP
 * @package  Resiliation
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */

namespace maileva\WebServices;

use Illuminate\Support\Facades\Log;

/**
 * Class MailevaFTP
 */
class MailevaServiceFTP
{
    /**
     * Connexion FTP au service maileva
     *
     * @return \LaravelFtp\FTP
     */
    public function connectorFtp(){
        $ftp = ftp(env('MAILEVA_SERVER'),
            env('MAILEVA_PSEUDO'),
            env('MAILEVA_PASSWORD'));
        if (!$ftp) {
            Log::alert('Error while connecting to the FTP server');
        }
        return $ftp;
    }
}