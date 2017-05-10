<?php

namespace AppBundle\Controller\Services;


use AppBundle\Entity\Informedconsent;
use AppBundle\Entity\Otp;
use AppBundle\Entity\Servicerequest;
use AppBundle\Entity\User;
use AppBundle\Service\DocumentsService;
use AppBundle\Util\MailHelper;
use Clickatell\Api\ClickatellHttp;
use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Exception;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Exception\InvalidArgumentException;


/**
 * Class OTPService
 * @package AppBundle\Controller\Services
 *
 * Generation and validation of One Time Password codes based on current timestamp and a secret key.
 * Code based on the following:
 *  - https://tools.ietf.org/html/rfc6238
 *  - https://tools.ietf.org/html/rfc6287
 *  - https://www.ietf.org/rfc/rfc4226.txt
 *
 * Extra:
 *  - https://auth2.com/blog/2012/09/implementing-two-factor-authentication-based-on-rfc6238/
 *
 * TODO: SECRET_SEED must be stored in the database and must be retrieved each time.
 * TODO: each user must have it's own SECRET_SEED
 *
 * TODO: UNIT TEST CLASS
 */

class OTPService
{
    const TIME_WINDOW = 60;
    const TIME_RANGE = 3;
    const OTP_DIGITS = 6;
    const OTP_ALGORITHM = 'sha1';
    const SECRET_SEED = 'MzEzMjMzMzQzNTM2MzczODM5MzA='; // base64 encoded

    private $em;
    private $clickatell;
    private $mailHelper;
    private $documentsService;
    private $translator;
    private $geographicDataService;
    private $isTest;
    private $isDemo;

    public function __construct(EntityManager $entityManager, ClickatellHttp $clickatell, MailHelper $mailHelper, DocumentsService $docsService,
                                Translator $translator, GeograficdataService $geograficdataService, $isTest, $isDemo)
    {
        $this->em = $entityManager;
        $this->clickatell = $clickatell;
        $this->mailHelper = $mailHelper;
        $this->documentsService = $docsService;
        $this->translator = $translator;
        $this->geographicDataService = $geograficdataService;
        $this->isTest = $isTest;
        $this->isDemo = $isDemo;

    }

    /**
     * @param $params
     * @return string
     * @throws EntityNotFoundException
     * @throws \Exception
     */
    public function createOTP($params)
    {
        //$phone = $params['phone'];
        //$informedConsentId = $params['informedConsentId'];
        $serviceRequestId = $params['serviceRequestId'];

        $serviceRequest = $this->em->getRepository('AppBundle:Servicerequest')
            ->findOneBy(array('idservicerequest' => $serviceRequestId));
        if (!$serviceRequest) {
            throw new EntityNotFoundException;
        }

        $informedConsent = $serviceRequest->getInformedconsentinformedconsent();
        $user = $this->em->getRepository('AppBundle:CustomerApplication')
            ->findOneBy(array('idCustomerApplication' => $serviceRequest->getIdCustomerApplication()))
            ->getCustomercustomer()
            ->getUseruser();

        $usedOtp = $this->em->getRepository('AppBundle:Otp')
            ->findOneBy(array(
                'informedconsentinformedconsent' => $informedConsent,
                'used' => true));

        if (isset($usedOtp)) {
            throw new AlreadyActivatedException('This code has been already used');
        }

        $otp = $this->getTokenCode(self::SECRET_SEED);
        $this->sendOTP($user, $informedConsent, $otp, null, $serviceRequest);

        return $otp;
    }

    /**
     * @param $secretKey
     * @return {string} base64 encoded OTP
     */
    private function getTokenCode($secretKey)
    {
        $result = '';
        $key = base64_decode($secretKey);

        $unixtimestamp = time() / self::TIME_WINDOW;
        $checktime = (int)($unixtimestamp);
        $thiskey = $thiskey = self::oath_hotp($key, $checktime);
        $result = $result . self::oath_truncate($thiskey, self::OTP_DIGITS);

        return $result;
    }

    /**
     * Sent SMS with the code using the Clickatell API
     *
     * @param {string} $phone number where to send the SMS
     * @param {Informedconsent} $informedConsent  linked to the OTP
     * @param {string|null} $otp to verify the user
     * @param {string|null} $message to include in the SMS
     * @return string
     * @throws ClickatellException
     * @throws Exception
     */
    public function sendOTP(User $user, Informedconsent $informedConsent, $otp = null, $message = null)
    {
        $lang = $user->getLanguagelanguage()->getCharset();

        if (!isset($message)) {
            $message = $this->translator->trans('etOtpMessage', array(), 'messages', $lang);
        }

        if (!isset($otp)) {
            $otp = $this->getTokenCode(self::SECRET_SEED);
        }
        
        if(!$this->isTest) {
            $phonePrefix = $this->geographicDataService->getPrefixPhone($user->getCountry());
            $extra = array('from' => 'MadeOfGenes');
            $response = $this->clickatell->sendMessage($phonePrefix . $user->getPhone(), $message . base64_decode($otp),$extra);
            if ($response[0]->errorCode && $response[0]->error) {
                throw new ClickatellException(
                    $response[0]->error,
                    $response[0]->errorCode);
            }
        } else {
            $title = $this->translator->trans('etOtpTitle', array(), 'messages', $lang);
            $content = array(
                'title1' => $title,
                'text1' => $message,
                'otpCode' => base64_decode($otp),
                'textfooterup' => $this->translator->trans('etMailBoxNotReceivingMessages', array(), 'messages', $lang)
            );

            $this->mailHelper->sendMail("otp", array($user->getEmail(), "marc.jubero@madeofgenes.com"), $content, $title, null);
        }

        $this->saveNewOTP($otp, $informedConsent);
        
        return base64_decode($otp);
    }

    /**
     * Save otp codes
     *
     * @param {string} $code
     * @param Informedconsent $informedConsent
     * @throws Exception
     */
    private function saveNewOTP($code, Informedconsent $informedConsent)
    {
        try {
            $otpPerInformedConsent = $this->em->getRepository('AppBundle:Otp')->findBy(array('informedconsentinformedconsent' => $informedConsent));

            $now = new \DateTime("now");

            if (count($otpPerInformedConsent) > 0) {
                foreach ($otpPerInformedConsent as $otp) {
                    $otp->setActive(false);
                    $otp->setUpdatedAt($now);

                    $this->em->persist($otp);
                }
            }

            $newOtpEntity = new Otp();
            $newOtpEntity->setCode($code);
            $newOtpEntity->setActive(true);
            $newOtpEntity->setUsed(false);
            $newOtpEntity->setCreatedAt($now);
            $newOtpEntity->setUpdatedAt($now);
            $newOtpEntity->setInformedconsentinformedconsent($informedConsent);

            $this->em->persist($newOtpEntity);

            $this->em->flush();

        } catch (ORMInvalidArgumentException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function validateOTP($params)
    {
        return $this->isValid($params);
    }

    /**
     * Check if the provided OTP is valid
     *
     * @param ParameterBag $params
     * @return JsonResponse
     * @throws EntityNotFoundException
     * @throws ORMException
     */
    private function isValid($params)
    {
        $otp = $params['otpCode'];
        $userId = $params['userId'];
        if (!isset($otp))
            throw new BadRequestHttpException;

        $timestampVerification = $this->timestampVerification(self::SECRET_SEED, base64_decode($otp));
        if (!$timestampVerification) {
            throw new BadRequestHttpException();
        }

        //$encodedOtp = base64_encode($otp);
        $dbOTP = $this->em->getRepository('AppBundle:Otp')->findOneBy(array('code' => $otp, 'active' => true));
        if (!$dbOTP) {
            throw new EntityNotFoundException();
        }

        $informedConsent = $dbOTP->getInformedconsentinformedconsent();
        $serviceRequest = $this->em->getRepository('AppBundle:Servicerequest')->findOneBy(array('informedconsentinformedconsent' => $informedConsent));
        if (!isset($serviceRequest)) {
              throw new EntityNotFoundException();
        }

        try {
            $updatedAt = new \DateTime("now");
            $dbOTP->setUpdatedAt($updatedAt);
            $dbOTP->setActive(false);
            $dbOTP->setUsed(true);

            $serviceRequest->setConsentaccepted(true);
            $serviceRequest->setUpdated($updatedAt);
            $serviceRequest->setStatus($serviceRequest->getHealthprofaccept() ? 1 : 0);

            $this->em->persist($serviceRequest);
            $this->em->persist($dbOTP);
            $this->em->flush();

        } catch (ORMInvalidArgumentException $e) {
            throw new ORMException($e->getMessage());
        } catch (OptimisticLockException $e) {
            throw new ORMException($e->getMessage());
        }

        try {
            // TODO: call pdf api and get signature receipt
            $parameters = array("customer" => $userId, "request" => $serviceRequest->getIdservicerequest(), "lang" => 'es');
            $this->documentsService->getDocument(new ParameterBag($parameters), 'signaturereceipt');
        } catch(Exception $e) {

        }

        return $serviceRequest;
    }

    /**
     * Verify the provided otp code using the current timestamp
     *
     * @param $secretkey
     * @param $code
     * @return {bool} TRUE if OTP is valid, else return FALSE
     */
    public function timestampVerification($secretkey, $code)
    {
        $key = base64_decode($secretkey);
        $unixtimestamp = time() / self::TIME_WINDOW;

        for ($i = -(self::TIME_RANGE); $i <= self::TIME_RANGE; $i++) {
            $checktime = (int)($unixtimestamp + $i);
            $thiskey = self::oath_hotp($key, $checktime);
            $truncated = self::oath_truncate($thiskey, self::OTP_DIGITS);

            if ((int)$code == $truncated) {
                return true;
            }
        }

        return false;
    }

    private function oath_hotp($key, $counter)
    {
        $cur_counter = array(0, 0, 0, 0, 0, 0, 0, 0);
        for ($i = 7; $i >= 0; $i--) {
            // C for unsigned char, * for  repeating to the end of the input data
            $cur_counter[$i] = pack('C*', $counter);
            $counter = $counter >> 8;
        }

        $binary = implode($cur_counter);

        // Pad to 8 characters
        str_pad($binary, 8, chr(0), STR_PAD_LEFT);

        $result = hash_hmac(self::OTP_ALGORITHM, $binary, $key);

        return $result;
    }

    private function oath_truncate($hash, $length = 6)
    {
        // Convert to dec
        $hashcharacters = str_split($hash, 2);
        for ($j = 0; $j < count($hashcharacters); $j++) {
            $hmac_result[] = hexdec($hashcharacters[$j]);
        }

        $offset = $hmac_result[19] & 0xf;
        $result = (
                (($hmac_result[$offset + 0] & 0x7f) << 24) |
                (($hmac_result[$offset + 1] & 0xff) << 16) |
                (($hmac_result[$offset + 2] & 0xff) << 8) |
                ($hmac_result[$offset + 3] & 0xff)
            ) % pow(10, $length);

        return $result;
    }
}

class ClickatellException extends Exception
{
}

class AlreadyActivatedException extends Exception
{
}