<?php

namespace AppBundle\Controller\Services;

use Symfony\Component\Config\Definition\Exception\Exception;

class OriginStampService
{
    const BASE_URL = 'http://www.originstamp.org/';
    const SERVICE_URL = 'api/stamps';
    const API_KEY = "5ecc8e4025e3b93e6aa11a1ebd7190aa";

    /**
     * OriginStampService constructor.
     */
    public function __construct()
    {
    }

    /**
     * Register hash at OriginStamp
     *
     * @param $hash : Hashed data (sha256)
     * @throws \HttpException
     */
    public function createTimeStamp($hash)
    {
        $algorithm = "hash_sha256";
        $data = json_encode(array($algorithm => $hash));

        $url = self::BASE_URL . self::SERVICE_URL;
        $page = self::SERVICE_URL;
        $headers = array(
            'POST ' . $page . ' HTTP/1.0',
            'Content-length: ' . strlen($data),
            'Content-type: application/json',
            'Authorization: Token token="' . self::API_KEY . '"'
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close ($ch);

        if (!$response) {
            throw new \HttpException;
        }

//        $decoded = json_decode($response, true);
//
//        if (!isset($decoded['hash_sha256'])) {
//            throw new Exception;
//        }
    }
}