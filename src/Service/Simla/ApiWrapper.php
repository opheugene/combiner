<?php

namespace App\Service\Simla;

use Psr\Log\LoggerInterface;
use RetailCrm\Api\Client;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Model\Entity\Customers\SerializedCustomerReference;
use RetailCrm\Api\Model\Request\Customers\CustomersCombineRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersEditRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersRequest;

class ApiWrapper implements ApiWrapperInterface
{
    /** @var Client $client */
    private $client;

    /** @var string $cachedDataPath */
    private $cachedDataPath;

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var string $apiUrl */
    private $apiUrl;

    public function __construct(
        Client $client,
        string $cachedDataPath,
        LoggerInterface $logger,
        string $apiUrl
    ) {
        $this->client = $client;
        $this->cachedDataPath = $cachedDataPath;
        $this->logger = $logger;
        $this->apiUrl = $apiUrl;
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
        $customersFile = $this->cachedDataPath . '/' . str_replace(['/', ':', 'https'], '', $this->apiUrl) . '_customers.json';
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
