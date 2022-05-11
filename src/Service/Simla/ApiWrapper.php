<?php

namespace App\Service\Simla;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use RetailCrm\Api\Client;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Factory\ClientFactory;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Model\Entity\Customers\SerializedCustomerReference;
use RetailCrm\Api\Model\Request\BySiteRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersCombineRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersEditRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersRequest;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class ApiWrapper implements ApiWrapperInterface
{
    /** @var Client $client */
    private $client;

    /** @var string $cachedDataPath */
    private $cachedDataPath;

    /** @var LoggerInterface $logger */
    private $logger;

    public function __construct(
        ClientInterface $httpClient,
        ContainerBagInterface $params,
        LoggerInterface $logger
    ) {
        $this->cachedDataPath = $params->get('kernel.project_dir') . $params->get('cached_data_path');
        $this->logger = $logger;

        $apiUrl = $params->get('crm.api_url');
        $apiKey = $params->get('crm.api_key');

        $factory = new ClientFactory();
        $factory->setHttpClient($httpClient);
        $this->client = $factory->createClient($apiUrl, $apiKey);
    }

    public function check()
    {
        try {
            $response = $this->client->api->credentials();
            dump($response);
        } catch (ApiExceptionInterface $exception) {
            $this->logger->error(sprintf(
                'Error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            ));

            return null;
        }

        return;
    }

    public function getCachedCustomersBySites(bool $noCache = false)
    {
        $customersFile = $this->cachedDataPath . '/customers.json';
        if (false === $noCache && file_exists($customersFile)) {
            $customers = json_decode(file_get_contents($customersFile));

        } else {
            try {

                $page = 1;
                $customers = [];

                while (true) {
                    $request = new CustomersRequest();
                    $request->page = $page;
                    $request->limit = 100;

                    $response = $this->client->customers->list($request);
                    foreach ($response->customers as $customer) {
                        $customers[$customer->site ?? '_'][$customer->id] = $customer;
                    }

                    ++$page;
                    if ($page > $response->pagination->totalPageCount) {
                        break;
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error from Simla API (status code: %d): %s',
                    $e->getStatusCode(),
                    $e->getMessage()
                ));

                return [];
            }

            file_put_contents($customersFile, json_encode($customers));
        }

        return $customers;
    }

    public function customerEdit($customer)
    {
        $this->logger->debug('Customer to edit: ' . print_r($customer, true));

        $request           = new CustomersEditRequest();
        $request->by       = ByIdentifier::EXTERNAL_ID;
        $request->customer = $customer;
        $request->site     = $customer->site;

        try {
            $this->client->customers->edit($customer->externalId, $request);
        } catch (ApiExceptionInterface $exception) {
            $this->logger->error(sprintf(
                'Error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            ));

            return null;
        }

        $this->logger->info('Customer edited: externalId#' . $customer->externalId);
    }

    /**
     * @throws \RetailCrm\Api\Exception\Api\ApiErrorException
     * @throws \RetailCrm\Api\Interfaces\ClientExceptionInterface
     * @throws \RetailCrm\Api\Exception\Client\HandlerException
     * @throws \RetailCrm\Api\Exception\Api\MissingCredentialsException
     * @throws \RetailCrm\Api\Exception\Api\AccountDoesNotExistException
     * @throws ApiExceptionInterface
     * @throws \RetailCrm\Api\Exception\Client\HttpClientException
     * @throws \RetailCrm\Api\Exception\Api\MissingParameterException
     * @throws \RetailCrm\Api\Exception\Api\ValidationException
     */
    public function customersCombine(int $resultCustomerId, array $customersIds)
    {
        $resultCustomer = new SerializedCustomerReference($resultCustomerId);
        $customers = [];
        foreach ($customersIds as $id) {
            $customers[] = new SerializedCustomerReference($id);
        }

        $request = new CustomersCombineRequest();
        $request->resultCustomer = $resultCustomer;
        $request->customers = $customers;

        return $this->client->customers->combine($request);
    }
}
