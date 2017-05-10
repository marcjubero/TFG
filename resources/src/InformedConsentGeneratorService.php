<?php

namespace AppBundle\Controller\Services;

use AppBundle\Entity\Analyst;
use AppBundle\Entity\Application;
use AppBundle\Entity\Healthprof;
use AppBundle\Entity\User;
use Knp\Bundle\SnappyBundle\Snappy\LoggableGenerator;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InformedConsentGeneratorService
{
    const CA_PATH = '../src/AppBundle/Resources/informed_consent/';

    private $twig;
    private $pdf;
    private $fs;
    private $hashGeneratorService;
    private $originStampService;
    private $timeStampAuthorityService;

    /**
     * ConsentAgreementGeneratorService constructor.
     *
     * @param TwigEngine $twig
     * @param LoggableGenerator $pdf
     * @param Filesystem $filesystem
     * @param HashGeneratorService $hashGeneratorService
     * @param OriginStampService $originStampService
     * @param TimeStampAuthorityService $timeStampAuthorityService
     */
    public function __construct(TwigEngine $twig, LoggableGenerator $pdf, Filesystem $filesystem, HashGeneratorService $hashGeneratorService,
                                OriginStampService $originStampService, TimeStampAuthorityService $timeStampAuthorityService)
    {
        $this->twig = $twig;
        $this->pdf = $pdf;
        $this->fs = $filesystem;
        $this->hashGeneratorService = $hashGeneratorService;
        $this->originStampService = $originStampService;
        $this->timeStampAuthorityService = $timeStampAuthorityService;
    }

    /**
     * Create a customized pdf file with User and Application data from the DB
     *
     * @param $user User data from DB
     * @param $application Application data from DB
     * @return Informed Consent information, including file name, path to the file, hash of the file and base64 encoded file content
     * @throws \Twig_Error
     */
    public function generateInformedConsent(User $user, Application $application, Healthprof $healthProf, Analyst $analyst, $referenceGenome)
    {
        if(!$this->fs->exists(self::CA_PATH)) {
            try {
                $this->fs->mkdir(self::CA_PATH);
            } catch (IOException $e) {
                throw new IOException;
            }
        }

        // TODO: CSS http://stackoverflow.com/questions/25154897/symfony2-knp-snappy-to-generate-pdf-doesnt-import-css
        $customerName = $user->getName() . " " . $user->getSurname1() . " " . $user->getSurname2();
        $analystName = $analyst->getUseruser()->getName() . " " . $analyst->getUseruser()->getSurname1() . " " . $analyst->getUseruser()->getSurname2();
        $applicationName = $application->getName();
        $applicationInfo = $application->getJustify();
        $healthProfName = $healthProf->getUseruser()->getName() . " " . $healthProf->getUseruser()->getSurname1() . " " . $healthProf->getUseruser()->getSurname2();

        try {
            $html = $this->twig->render(
                'Reports/informedconsent.html.twig',
                array(
                    'customerName' => $customerName,
                    'analystName' => $analystName,
                    'markers' => $referenceGenome,
                    'applicationName' => $applicationName,
                    'applicationInfo' => $applicationInfo,
                    'healthProfName' => $healthProfName
                ));

            // http://stackoverflow.com/questions/30303218/bad-caracters-when-generating-pdf-file-with-knp-snappy
            $content = $this->pdf->getOutputFromHtml(
                $html,
                array(
                    'lowquality' => false,
                    'encoding' => 'utf-8',
                ));

            $fileName = 'IC_' . time() . '.pdf';
            $filePath = self::CA_PATH . $fileName;

            file_put_contents($filePath, $content);

            $fs = new FileSystem();
            if (!$fs->exists($filePath)) {
                throw new NotFoundHttpException;
            }

            $hash = $this->hashGeneratorService->hash($filePath, 256);

            $this->originStampService->createTimeStamp($hash);
            $this->timeStampAuthorityService->createTimeStamp($hash);

            $response = array(
                "fileName" => basename($filePath),
                "fileHash" => $hash,
                "fileContent" => $content
            );

            return $response;

        } catch (\Twig_Error $e) {
            throw new Exception($e->getMessage());
        }
    }
}