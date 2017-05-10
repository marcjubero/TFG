<?php

namespace AppBundle\Controller\Services;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class TimeStampAuthorityService
{
    const TSA_URL = "https://freetsa.org/tsr";

    public function __construct()
    {
    }

    public function createTimeStamp($hash)
    {
        // Just accept SHA256 hash
        if (strlen($hash) !== 64)
            throw new Exception("Invalid Hash.");

        $tempFilePath = self::createRequestFile($hash);
        $signResponse = self::signRequestFile($tempFilePath);

        if (!self::verifyTSARegistry($hash, $signResponse))
            throw new Exception('Hash not published');

        return array('message' => $hash . ' has been succesfully published');
    }

    private function createRequestFile($hash)
    {
        // openssl ts -query -sha256 -digest {SHA256} -cert -out fileSha4.tsq 
        $outfilepath = self::createTempFile();
        $cmd = "openssl ts -query -sha256 -digest " . escapeshellarg($hash) . " -cert -out " . escapeshellarg($outfilepath);

        $retarray = array();
        exec($cmd . " 2>&1", $retarray, $retcode);

        if ($retcode !== 0)
            throw new Exception("OpenSSL does not seem to be installed: " . implode(", ", $retarray));

        if (sizeof($retarray))
            if (stripos($retarray[0], "openssl:Error") !== false)
                throw new Exception("There was an error with OpenSSL. Is version >= 0.99 installed?: " . implode(", ", $retarray));

        return $outfilepath;
    }

    private function createTempFile($str = "")
    {
        $tempFileName = tempnam(sys_get_temp_dir(), rand());

        if (!file_exists($tempFileName))
            throw new Exception("Tempfile could not be created");

        if (!empty($str) && !file_put_contents($tempFileName, $str))
            throw new Exception("Could not write to tempfile");

        return $tempFileName;
    }

    private function signRequestFile($requestFilePath)
    {
        // curl -H "Content-Type: application/timestamp-query" --data-binary '@fileSha4.tsq' https://freetsa.org/tsr > file.tsr
        if (!file_exists($requestFilePath))
            throw new Exception('File not found');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::TSA_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($requestFilePath));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/timestamp-query'));
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");

        try {
            $binaryResponse = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        } catch (Exception $e) {
            throw new Exception('Error while posting hash on FreeTSA Service');
        } finally {
            curl_close($ch);
        }

        if ($status != 200 || !strlen($binaryResponse))
            throw new Exception("The request failed");

        return self::createTempFile($binaryResponse);
    }

    private function verifyTSARegistry($hash, $signResponse)
    {
        $filesDirPath = __DIR__ . '/../../Resources/files/';
        $pemFile = $filesDirPath . 'cacert.pem';
        $crtFile = $filesDirPath . 'tsa.crt';

        // openssl ts -verify -digest hash -in file.tsr -CAfile cacert.pem -untrusted tsa.crt
        $cmd = 'openssl ts -verify -digest ' . $hash . ' -in ' . $signResponse . ' -CAfile ' . $pemFile . ' -untrusted ' . $crtFile;
        $process = new Process($cmd);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            return false;
        }

        if(!$process->isSuccessful()) {
            return false;
        }

        return (trim(preg_replace("/\r|\n/", '', explode(':', $process->getOutput())[1])) === 'OK');
    }
}