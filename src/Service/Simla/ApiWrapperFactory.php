<?php

namespace App\Service\Simla;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use RetailCrm\Api\Factory\ClientFactory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class ApiWrapperFactory
{
    /** @var ClientInterface $httpClient */
    private $httpClient;

    /** @var ContainerBagInterface $params */
    private $params;

    /** @var LoggerInterface $logger */
    private $logger;

    public function __construct(
        ClientInterface $httpClient,
        ContainerBagInterface $params,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->params = $params;
        $this->logger = $logger;
    }

    public function create(string $apiUrl, string $apiKey): ApiWrapper
    {
        $factory = new ClientFactory();
        $factory->setHttpClient($this->httpClient);
        $client = $factory->createClient($apiUrl, $apiKey);

        $cachedDataPath = $this->params->get('kernel.project_dir') . $this->params->get('cached_data_path');

        return new ApiWrapper($client, $cachedDataPath, $this->logger);
    }
}
