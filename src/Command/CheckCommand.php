<?php

namespace App\Command;

use App\Service\Simla\ApiWrapper;
use App\Service\Simla\ApiWrapperFactory;
use Psr\Log\LoggerInterface;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Model\Entity\Customers\Customer;
use RetailCrm\Api\Model\Entity\Customers\MGCustomer;
use RetailCrm\Api\Model\Entity\Customers\Subscription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Yaml\Yaml;

class CheckCommand extends Command
{
    const EMAIL_REGEXP = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/i";
    const COUNT_FIELDS = [
        'firstName',
        'lastName',
        'email',
        'phones',
        'birthday',
    ];

    protected static $defaultName = 'duplicates:by';
    protected static $defaultDescription = 'Show and combine duplicates in CRM by email or phone';

    /** @var ParameterBagInterface $params */
    private $params;

    /** @var ApiWrapperFactory $factory */
    private $factory;

    /** @var ApiWrapper $api */
    private $api;

    /** @var SymfonyStyle $io */
    private $io;

    /** @var InputInterface $input */
    private $input;

    /** @var array $criteria */
    private $criteria;

    /** @var array $fields */
    private $fields;

    /** @var Table $table */
    private $table;

    /** @var LoggerInterface $combinerLogger */
    private $combinerLogger;

    public function __construct(ParameterBagInterface $params, ApiWrapperFactory $factory, LoggerInterface $combinerLogger)
    {
        $this->params = $params;
        $this->factory = $factory;
        $this->combinerLogger = $combinerLogger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('by', InputArgument::REQUIRED, 'By which field to search for duplicates')
            ->addArgument('criteria', InputArgument::IS_ARRAY, 'Customer comparison criteria')
            ->addOption('config', '-c', InputOption::VALUE_REQUIRED, 'File with all command options')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Fields to show in report, comma separated')
            ->addOption('all-sites', null, InputOption::VALUE_NONE, 'Look for duplicates in all sites')
            ->addOption('filter-sites', null, InputOption::VALUE_REQUIRED, 'Look for duplicates in specific sites')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Get data without cache')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Save report to CSV file')
            ->addOption('combine', null, InputOption::VALUE_NONE, 'Do combine duplicates of clients')
            ->addOption('merge-managers', null, InputOption::VALUE_NONE, 'Merge duplicates managers to client')
            ->addOption('merge-phones', null, InputOption::VALUE_REQUIRED, 'Merge numbers to number with country code')
            ->addOption('merge-subscriptions', null, InputOption::VALUE_NONE, 'Merge customer subscriptions status')
            ->addOption('collectEmails', null, InputOption::VALUE_REQUIRED, 'Collect all emails in resulting customer custom field')
            ->addOption('mergeFields', null, InputOption::VALUE_REQUIRED, 'Other fields, that needs to be merged')
            ->addOption('consider-orders', null, InputOption::VALUE_NONE, 'Consider order parameters')

            ->addOption('phoneExactLength', null, InputOption::VALUE_REQUIRED, 'Number of digits for phoneExactLength criteria')
            ->addOption('sourcePriority', null, InputOption::VALUE_REQUIRED, 'Priority of sources for sourcePriority criteria')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Lines that should not be combined')

            ->addOption('crmUrl', null, InputOption::VALUE_REQUIRED, 'Simla.com URL')
            ->addOption('apiKey', null, InputOption::VALUE_REQUIRED, 'Simla.com API Key')
        ;
    }

    // sort clients by priority
    protected function sort($list)
    {
        uasort($list, function($one, $two) {

            foreach ($this->criteria as $criteria) {
                if (str_contains($criteria, '.')) { // todo mb allow more recursive depth
                    [$prefix, $code] = explode('.', $criteria, 2);

                    $oneField = $twoField = null;

                    if (property_exists($one, $prefix)) {
                        if (is_array($one->$prefix)) {
                            if (array_key_exists($code, $one->$prefix)) {
                                $oneField = $one->$prefix->$code;
                            }
                        } elseif (is_object($one->$prefix)) {
                            if (property_exists($one->$prefix, $code)) {
                                $oneField = $one->$prefix->$code;
                            }
                        }
                    }

                    if (property_exists($two, $prefix)) {
                        if (is_array($two->$prefix)) {
                            if (array_key_exists($code, $two->$prefix)) {
                                $twoField = $two->$prefix->$code;
                            }
                        } elseif (is_object($two->$prefix)) {
                            if (property_exists($two->$prefix, $code)) {
                                $twoField = $two->$prefix->$code;
                            }
                        }
                    }

                    if (empty($oneField) != empty($twoField)) {
                        return empty($oneField) <=> empty($twoField);
                    }
                }

                if (str_starts_with($criteria, 'site-')) {
                    $criteriaSite = substr($criteria, 5);
                    $oneSite = $one->site === $criteriaSite;
                    $twoSite = $two->site === $criteriaSite;

                    return empty($oneSite) <=> empty($twoSite);
                }

                switch ($criteria) {

                    case 'externalId':
                        if (empty($one->externalId) != empty($two->externalId)) {
                            return empty($one->externalId) <=> empty($two->externalId);
                        }
                        break;

                    case 'ordersCount':
                        if ($one->ordersCount != $two->ordersCount) {
                            return $two->ordersCount <=> $one->ordersCount;
                        }
                        break;

                    case 'totalSumm':
                        if ($one->totalSumm != $two->totalSumm) {
                            return $two->totalSumm <=> $one->totalSumm;
                        }
                        break;

                    case 'customFieldsCount':
                        $oneCount = count((array) ($one->customFields));
                        $twoCount = count((array) ($two->customFields));

                        if ($oneCount != $twoCount) {
                            return $oneCount <=> $twoCount;
                        }
                        break;

                    case 'email':
                        if (empty($one->email) != empty($two->email)) {
                            return empty($one->email) <=> empty($two->email);
                        }
                        break;

                    case 'phone':
                        if (empty($one->phones) != empty($two->phones)) {
                            return empty($one->phones) <=> empty($two->phones);
                        }
                        break;

                    case 'phoneExactLength':
                        $phoneLength = $this->input->getOption('phoneExactLength') ?: 12;
                        $oneIntPhone = array_reduce($one->phones, function ($carry, $phone) use ($phoneLength) {
                            $ph = $this->clearPhone($phone, false);
                            return $ph && mb_strlen($ph) >= $phoneLength ? $ph : $carry;
                        });
                        $twoIntPhone = array_reduce($two->phones, function ($carry, $phone) use ($phoneLength) {
                            $ph = $this->clearPhone($phone, false);
                            return $ph && mb_strlen($ph) >= $phoneLength ? $ph : $carry;
                        });
                        if ($oneIntPhone != $twoIntPhone) {
                            return $twoIntPhone <=> $oneIntPhone;
                        }
                        break;

                    case 'sourcePriority':
                        if ($this->sourcePriority($one) <=> $this->sourcePriority($two)) {
                            return $this->sourcePriority($two) <=> $this->sourcePriority($one);
                        }
                        break;

                    case 'createdAt':
                        $result = $this->compareCreatedAt($one, $two);
                        if ($result !== null) {
                            return $result;
                        }
                        break;

                    case 'moreData':
                        if ($this->countFilledFields($one) != $this->countFilledFields($two)) {
                            return $this->countFilledFields($two) <=> $this->countFilledFields($one);
                        }
                        break;

                    case 'hasChat':
                        if (empty($one->mgCustomers) != empty($two->mgCustomers)) {
                            return empty($one->mgCustomers) <=> empty($two->mgCustomers);
                        } elseif (!empty($one->mgCustomers) && !empty($two->mgCustomers)) {
                            return $this->hasActiveChannel($two->mgCustomers) <=> $this->hasActiveChannel($one->mgCustomers);
                        }
                        break;

                    default:
                        if (empty($one->$criteria) != empty($two->$criteria)) {
                            return empty($one->$criteria) <=> empty($two->$criteria);
                        }
                        break;    
                }
            }

            return 0;
        });

        return $list;
    }

    protected function compareCreatedAt($one, $two): ?int
    {
        if (empty($one->createdAt) != empty($two->createdAt)) {
            return empty($two->createdAt) <=> empty($one->createdAt);
        }
        if (!empty($one->createdAt) && !empty($one->createdAt->date) && !empty($one->createdAt->timezone)
            && !empty($two->createdAt) && !empty($two->createdAt->date) && !empty($two->createdAt->timezone)
        ) {
            $oneCreatedAt = new \DateTimeImmutable($one->createdAt->date, new \DateTimeZone($one->createdAt->timezone));
            $twoCreatedAt = new \DateTimeImmutable($two->createdAt->date, new \DateTimeZone($two->createdAt->timezone));
            if ($oneCreatedAt->getTimestamp() != $twoCreatedAt->getTimestamp()) {
                return $oneCreatedAt->getTimestamp() <=> $twoCreatedAt->getTimestamp();
            }
        }

        return null;
    }

    // sort clients consider their orders
    protected function sortConsiderOrders($list, $orders)
    {
        $orderParameters = $this->input->getOption('consider-orders');

        uasort($list, function($one, $two) use ($orderParameters, $orders) {
            foreach ($orderParameters as $criteria => $value) {
                switch (true) {
                    case $criteria === 'createdAt':
                        $onesOrder = current($orders[$one->id] ?? []);
                        $twosOrder = current($orders[$two->id] ?? []);

                        $result = $this->compareCreatedAt($onesOrder, $twosOrder);
                        if ($result !== null) {
                            return $result;
                        }
                        break;

                    case is_array($value):
                        $onesOrders = $orders[$one->id] ?? [];
                        $twosOrders = $orders[$two->id] ?? [];

                        $onesSortedOrders = [];
                        $twosSortedOrders = [];
                        foreach ($value as $val) {
                            $sortedByVal = array_filter($onesOrders, static function($order) use ($criteria, $val) {
                                return $order->$criteria == $val;
                            });

                            if (!empty($sortedByVal)) {
                                $onesSortedOrders[] = $sortedByVal;
                            }

                            $sortedByVal = array_filter($twosOrders, static function($order) use ($criteria, $val) {
                                return $order->$criteria == $val;
                            });

                            if (!empty($sortedByVal)) {
                                $twosSortedOrders[] = $sortedByVal;
                            }
                        }

                        if (empty($onesSortedOrders) && empty($twosSortedOrders)) {
                            return 0;
                        }

                        if (empty($onesSortedOrders) != empty($twosSortedOrders)) {
                            return empty($onesSortedOrders) <=> empty($twosSortedOrders);
                        }

                        $onesMostImportantOrder = current(current($onesSortedOrders));
                        $twosMostImportantOrder = current(current($twosSortedOrders));

                        return array_keys($value, $onesMostImportantOrder->$criteria) <=> array_keys($value, $twosMostImportantOrder->$criteria);
                }
            }

            return 0;
        });


        return $list;
    }

    protected function countFilledFields($customer)
    {
        $counter = 0;

        foreach (self::COUNT_FIELDS as $field) {
            if (!empty($customer->$field)) {
                $counter++;
            }
        }

        foreach ((array) $customer->address as $field) {
            if (!empty($field)) {
                $counter++;
            }
        }

        return $counter;
    }

    protected function sourcePriority($customer)
    {
        if (empty($customer) || empty($customer->source) || empty($customer->source->source)) {
            return 0;
        }
        $sourcePriority = [];
        $sources = explode(',', $this->input->getOption('sourcePriority')) ?? [];
        foreach ($sources as $value) {
            [$source, $priority] = array_pad(explode('=', $value), 2, null);
            if ($source && $priority) {
                $sourcePriority[$source] = $priority;
            }
        }
        if (array_key_exists($customer->source->source, $sourcePriority)) {
            return $sourcePriority[$customer->source->source];
        }
        return 1;
    }

    // need to exclude the customer from combining duplicates?
    protected function isExclude($customer)
    {
        // externalId
        //return !empty($customer->externalId);

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;

        if ($input->getOption('config')) {
            $this->readConfig($input->getOption('config'));
        }

        $this->io = new SymfonyStyle($input, $output);

        $by = $this->input->getArgument('by');

        if (!$this->initApi()) {
            return Command::FAILURE;
        }

        $fields = $this->input->getOption('fields');
        $this->fields = $fields ? explode(',', $fields) : [];
        $this->criteria = $this->input->getArgument('criteria');

        if (empty($this->criteria)) {
            $this->io->note('You have not set any criteria for comparing customers');
        }

        $this->combinerLogger->info(sprintf('Start combining for %s', $this->input->getOption('crmUrl')));

        // get customers by sites
        $noCache = $this->input->getOption('no-cache');
        $allSites = $this->input->getOption('all-sites');
        $filterSitesOption = $this->input->getOption('filter-sites');
        $filterSites = $filterSitesOption ? explode(',', $filterSitesOption) : [];
        $customersBySites = $this->api->getCachedCustomersBySites($noCache);

        // prepare lists of duplicates
        $duplicates = [];
        $duplicatesByNameRef = [];

        foreach ($customersBySites as $site => $customers) {
            if (!empty($filterSites)) {
                if (!in_array($site, $filterSites)) {
                    continue;
                }

                $site = 'filter_sites';
            }

            $site = $allSites ? 'all_sites' : $site;

            foreach ($customers as $id => $customer) {
                if ('email' === $by && $customer->email && preg_match(self::EMAIL_REGEXP, $customer->email)) {
                    if ($this->input->getOption('exclude') && $customer->email === $this->input->getOption('exclude')) {
                        continue;
                    }

                    $duplicates[$site][$customer->email][$id] = $customer;
                } elseif ('phone' === $by) {
                    foreach ($customer->phones as $phone) {
                        $clearedPhone = $this->clearPhone($phone);

                        if ($this->input->getOption('exclude') && $clearedPhone === $this->input->getOption('exclude')) {
                            continue;
                        }

                        if ($clearedPhone) {
                            $duplicates[$site][$clearedPhone][$id] = $customer;
                        }
                    }
                } elseif ('name' === $by) {
                    $name = $this->getCustomerFullName($customer);

                    if (count(explode(' ', $name)) < 2) {
                        continue;
                    }

                    $duplicates[$site][$name][$id] = $customer;
                } elseif (strpos($by, 'name-') === 0) {
                    $number = substr($by, strlen('name-'));
                    if (!is_numeric($number)) {
                        continue;
                    }

                    $name = $this->getCustomerFullName($customer);

                    foreach ($this->generateNameTokens($name, intval($number)) as $namePair) {
                        $duplicatesByNameRef[$site][$id][] = $namePair;
                        $duplicates[$site][$namePair][$id] = $customer;
                    }
                } elseif (str_contains($by, '.')) { // todo mb allow more recursive depth
                    [$prefix, $code] = explode('.', $by, 2);

                    if (property_exists($customer, $prefix)) {
                        if (is_array($customer->$prefix)) {
                            if (array_key_exists($code, $customer->$prefix)) {
                                $duplicates[$site][preg_replace('/[^\d]/', '', $customer->$prefix[$code])][$id] =
                                    $customer;
                            }
                        } elseif (is_object($customer->$prefix)) {
                            if (property_exists($customer->$prefix, $code)) {
                                $duplicates[$site][preg_replace('/[^\d]/', '', $customer->$prefix->$code)][$id] =
                                    $customer;
                            }
                        }
                    }
                }
            }
        }

        foreach ($duplicatesByNameRef as $site => $customerNames) {
            foreach ($customerNames as $names) {
                $duplicatesByName = [];

                foreach ($names as $name) {
                    if (isset($duplicates[$site][$name])) {
                        foreach ($duplicates[$site][$name] as $id => $duplicate) {
                            $isAnotherCustomer = false;

                            foreach ($duplicatesByName as $item) {
                                if($item->ordersCount > 0 && $duplicate->ordersCount > 0) {
                                    $isAnotherCustomer = true;
                                    break;
                                }

                                if($item->email && $duplicate->email && $item->email !== $duplicate-> email) {
                                    $isAnotherCustomer = true;
                                    break;
                                }
                                if($item->phones && $duplicate->phones) {
                                    $isAnotherCustomer = true;
                                    foreach ($item->phones as $phone) {
                                        foreach ($duplicate->phones as $duplicatePhone) {
                                            if ($this->clearPhone($phone) === $this->clearPhone($duplicatePhone)) {
                                                $isAnotherCustomer = false;
                                                break 3;
                                            }
                                        }
                                    }
                                    break;
                                }
                            }

                            // todo mb save those clients and check for their duplicates too
                            if (!$isAnotherCustomer) {
                                $duplicatesByName[$id] = $duplicate;
                            }
                        }
                        unset($duplicates[$site][$name]);
                    }
                }

                if (count($duplicatesByName) === 0) {
                    continue;
                }

                $mainCustomer = reset($duplicatesByName);
                $name = $this->getCustomerFullName($mainCustomer);

                $duplicates[$site][$name] = $duplicatesByName;
            }
        }

        // sort
        foreach ($duplicates as $site => &$customers) {
            foreach ($customers as $field => $list) {
                if (count($list) == 1) {
                    unset($customers[$field]);
                    continue;
                }
                $customers[$field] = $this->sort($list);
            }
            if (!count($customers)) {
                unset($duplicates[$site]);
            }
        }
        unset($customers);

        // sort consider customer orders
        if ($this->input->getOption('consider-orders')) {
            $orders = $this->api->getCachedOrdersBySite($noCache);
            foreach ($duplicates as $site => &$customers) {
                foreach ($customers as $field => $list) {
                    $customers[$field] = $this->sortConsiderOrders($list, $orders[$site]);
                }
            }
            unset($customers);
        }


        $editCustomer = [];
        // merge managers
        if ($this->input->getOption('merge-managers')) {
            foreach ($duplicates as $site => &$customers) {
                foreach ($customers as $field => $list) {
                    $manager = null;
                    foreach ($list as $item) {
                        if (!is_null($item->managerId)) {
                            $manager = $item->managerId;
                            break;
                        }
                    }

                    if (!is_null($manager) && reset($list)->managerId !== $manager) {
                        reset($list)->managerId = $manager;
                        $customer = new Customer();
                        $customer->id = reset($list)->id;
                        $customer->site = reset($list)->site;
                        $customer->managerId = reset($list)->managerId;
                        $editCustomer[reset($list)->id] = $customer;
                    }
                }
            }
            unset($customers);
        }

        // merge phones
        $substr = $this->input->getOption('merge-phones') ?: 10;
        if ($this->input->getOption('merge-phones')) {
            foreach ($duplicates as &$customers) {
                foreach ($customers as $list) {
                    $phones = array();
                    foreach ($list as $item) {
                        foreach ($item->phones as $phone) {
                            $cleanNumber = preg_replace('/[^+0-9]/', '', $phone->number);
                            $phoneIndex = substr($cleanNumber, -$substr);
                            if (!isset($phones[$phoneIndex])) {
                                $phones[$phoneIndex] = $phone;
                            } elseif (strlen(preg_replace('/[^+0-9]/',
                                    '',
                                    $phones[$phoneIndex]->number)) < strlen($cleanNumber)) {
                                $phones[$phoneIndex] = $phone;
                            }
                        }
                    }
                    $phones = array_values($phones);
                    usort($phones, function($one, $two){
                        return strlen($two->number) <=> strlen($one->number);
                    });
                    reset($list)->phones = $phones;

                    if (isset($editCustomer[reset($list)->id])) {
                        $editCustomer[reset($list)->id]->phones = $phones;
                    } else {
                        $customer = new Customer();
                        $customer->id = reset($list)->id;
                        $customer->site = reset($list)->site;
                        $customer->phones = $phones;
                        $editCustomer[reset($list)->id] = $customer;
                    }
                }
            }
            unset($customers);
        }

        // collect emails
        if ($this->input->getOption('collectEmails')) {
            $customField = $this->input->getOption('collectEmails');
            foreach ($duplicates as &$customers) {
                foreach ($customers as $list) {
                    $emails = array();
                    foreach ($list as $item) {
                        $emails[$item->email] = $item->email;
                        if (isset($item->customFields[$customField])) {
                            foreach (explode('; ', $item->customFields[$customField]) as $secondEmail) {
                                $emails[$secondEmail] = $secondEmail;
                            }
                        }
                    }

                    unset($emails[current($list)->email]);
                    reset($list)->customFields[$customField] = implode('; ', $emails);

                    if (isset($editCustomer[reset($list)->id])) {
                        $editCustomer[reset($list)->id]->customFields[$customField] = implode('; ', $emails);
                    } else {
                        $customer = new Customer();
                        $customer->id = reset($list)->id;
                        $customer->site = reset($list)->site;
                        $customer->customFields[$customField] = implode('; ', $emails);
                        $editCustomer[reset($list)->id] = $customer;
                    }
                }
            }
            unset($customers);
        }

        // merge subscriptions
        $subscribeCustomer = [];
        if ($this->input->getOption('merge-subscriptions')) {
            foreach ($duplicates as $customers) {
                foreach ($customers as $list) {
                    $subscriptions = [];
                    foreach (reset($list)->customerSubscriptions as $customerSubscription) {
                        $serializedSubscription = new Subscription();
                        $serializedSubscription->channel = $customerSubscription->subscription->channel;
                        $serializedSubscription->active = $customerSubscription->subscribed;

                        $subscriptions[$serializedSubscription->channel] = $serializedSubscription;
                    }

                    foreach ($list as $item) {
                        foreach ($item->customerSubscriptions as $customerSubscription) {
                            $channel = $customerSubscription->subscription->channel;
                            $subscriptions[$channel]->active = $subscriptions[$channel]->active && $customerSubscription->subscribed;
                        }
                    }

                    $customerId = reset($list)->id;
                    if (!isset($subscribeCustomer[$customerId])) {
                        $customer = new Customer();
                        $customer->id = $customerId;
                        $customer->site = reset($list)->site;
                        $subscribeCustomer[$customerId]['customer'] = $customer;
                    }
                    $subscribeCustomer[$customerId]['subscriptions'] = $subscriptions;
                }
            }
        }

        // other fields to merge
        if ($this->input->getOption('mergeFields')) {
            $mergeFields = $this->input->getOption('mergeFields') ? explode(',', $this->input->getOption('mergeFields')) : [];
            foreach ($duplicates as $customers) {
                foreach ($customers as $list) {
                    $customerMergeFields = array();

                    foreach ($list as $item) {
                        foreach ($mergeFields as $mergeField) {
                            $field = str_replace('customField.', '', $mergeField);

                            if (!isset($customerMergeFields[$mergeField])) {
                                if (isset($item->customFields[$field]) && str_contains($mergeField, 'customField.')) {
                                    $customerMergeFields[$mergeField] = $item->customFields[$field];
                                } elseif (isset($item->$field)) {
                                    $customerMergeFields[$mergeField] = $item->$field;
                                }
                            }
                        }
                    }

                    if (isset($editCustomer[reset($list)->id])) {
                        foreach ($customerMergeFields as $key => $field) {
                            if (str_contains($key, 'customField.')) {
                                $key = str_replace('customField.', '', $key);
                                $editCustomer[reset($list)->id]->customFields[$key] = $field;
                            } else {
                                $editCustomer[reset($list)->id]->$key = $field;
                            }
                        }
                    } else {
                        $customer = new Customer();
                        $customer->id = reset($list)->id;
                        $customer->site = reset($list)->site;
                        foreach ($customerMergeFields as $key => $field) {
                            if (str_contains($key, 'customField.')) {
                                $key = str_replace('customField.', '', $key);
                                $customer->customFields[$key] = $field;
                            } else {
                                $customer->$key = $field;
                            }
                        }
                        $editCustomer[reset($list)->id] = $customer;
                    }
                }
            }
        }

        // analyse
        $this->combinerLogger->info(sprintf('List of duplicates by %s', $by));
        if ($this->fields) {
            foreach ($duplicates as $site => $customers) {
                $this->table = $this->io->createTable();
                $this->table->setStyle('default');
                $this->table->setHeaderTitle('Site: ' . $site . ' - ' . count($customers));
                $this->table->setHeaders($this->fields);
                foreach ($customers as $field => $list) {
                    $this->addLineToTable('Duplicates by ' . $by . ' ' . $field . ': ' . count($list));
                    $this->addRowsToTable($list);
                    $this->table->addRow(new TableSeparator());

                    $this->combinerLogger->debug(
                        sprintf('%s - Customer: %d, duplicates: %s',
                            $field,
                            reset($list)->id,
                            json_encode($list)));
                }
                $this->table->render();
                $this->io->writeln('');
            }
        }

        // save report
        if ($this->input->getOption('csv') && $this->fields) {
            foreach ($duplicates as $site => $customers) {
                $csvFullPath = sprintf(
                    '%s/%s_by_%s_%s.csv',
                    $this->params->get('kernel.project_dir') . $this->params->get('report_path'),
                    $this->getCrmName($this->input->getOption('crmUrl') ?? 'crm'),
                    $by,
                    $site
                );
                $csv = fopen($csvFullPath, 'w');
                fputcsv($csv, $this->fields);
                foreach ($customers as $field => $list) {
                    fputcsv($csv, ['Duplicates by ' . $by . ' ' . $field . ': ' . count($list)]);
                    foreach ($list as $customer) {
                        fputcsv($csv, $this->toArray($customer));
                    }
                }
                $this->io->success('CSV file saved to ' . $csvFullPath);
            }
        }

        // combine
        if ($this->input->getOption('combine')) {
            if (empty($this->criteria)) {
                $this->io->error('Specify comparison criteria');
                return Command::INVALID;
            }
            $combined = 0;
            foreach ($duplicates as $customers) {
                foreach ($customers as $list) {

                    reset($list);
                    $resultCustomerId = key($list);
                    $combineIds = [];
                    $combineCustomers = [];
                    foreach ($list as $id => $customer) {
                        if ($id == $resultCustomerId || $this->isExclude($customer)) {
                            continue;
                        }
                        $combineIds[] = $id;
                        $combineCustomers[] = $customer;
                    }
                    // null phones
                    if ($this->input->getOption('merge-phones')) {
                        $this->api->nullDublicatePhones($combineCustomers, $combineIds);
                    }

                    if ($this->combine($resultCustomerId, $combineIds)) {
                        $combined += count($combineIds);

                    }
                }
            }
            if ($combined) {
                $this->io->success('Combined customers: ' . $combined);
                // wait some time, then edit customers
                sleep(5);
                foreach ($duplicates as $customers) {
                    foreach ($customers as $list) {
                        reset($list);
                        $resultCustomerId = key($list);
                        if (isset($editCustomer[$resultCustomerId])) {
                            $this->api->customerEdit($editCustomer[$resultCustomerId], ByIdentifier::ID);
                        }
                        if (isset($subscribeCustomer[$resultCustomerId])) {
                            $this->api->customerSubscribe(
                                $subscribeCustomer[$resultCustomerId]['customer'],
                                $subscribeCustomer[$resultCustomerId]['subscriptions'],
                                ByIdentifier::ID
                            );
                        }
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    protected function initApi()
    {
        $crmUrl = $this->input->getOption('crmUrl');
        $apiKey = $this->input->getOption('apiKey');
        if (empty($crmUrl) || empty($apiKey)) {
            $this->io->error('You have to specify CRM API credentials');
            return false;
        }

        try {
            $this->api = $this->factory->create($crmUrl, $apiKey);
            return true;
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());
        }

        return false;
    }

    protected function combine($resultCustomerId, $combineCustomersIds)
    {
        try {
            if ($resultCustomerId && count($combineCustomersIds)) {
                $response = $this->api->customersCombine($resultCustomerId, $combineCustomersIds);
                return $response->success;
            }
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());
        }
    }

    protected function addLineToTable($line)
    {
        $this->table->addRow(array_pad([$line], count($this->fields), null));
    }

    protected function addRowsToTable($customers)
    {
        foreach ($customers as $customer) {
            $this->table->addRow($this->toArray($customer));
        }
    }

    protected function toArray($customer)
    {
        $array = [];

        foreach ($this->fields as $path) {
            if($path === 'fullName') {
                $array[] = $this->getCustomerFullName($customer);
                continue;
            }

            $array[] = array_reduce(
                explode('.', $path),
                function ($o, $p) {
                    if (!is_null($o)) {
                        if (is_object($o) && property_exists($o, $p)) {
                            return (is_array($o->$p) ? json_encode($o->$p) : $o->$p);
                        } elseif (is_array($o) && array_key_exists($p, $o)) {
                            return (is_array($o[$p]) ? json_encode($o[$p]) : $o[$p]);
                        }
                    }

                    return null;
                },
                $customer
            );
        }

        return $array;
    }

    protected function clearPhone($phone, $substr = 10)
    {
        $ph = null;
        if ($phone && !empty($phone->number)) {
            $ph = $phone->number;
            $ph = preg_replace('/[^0-9]/', '', $ph);
            if ($substr) {
                $ph = substr($ph, -$substr);
            }
        }

        return $ph;
    }

    protected function generateNameTokens(string $name, int $minCount = 2): array
    {
        $words = explode(' ', $name);
        $count = count($words);

        if ($count < $minCount) {
            return [];
        }

        sort($words);

        $tokens = [];
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // todo refactor
                if ($minCount == 2) {
                    $tokens[]  = implode(' ', [$words[$i], $words[$j]]);
                } else {
                    for ($k = $j + 1; $k < $count; $k++) {
                        $tokens[]  = implode(' ', [$words[$i], $words[$j], $words[$k]]);
                    }
                }
            }
        }

        return $tokens;
    }

    protected function getCustomerFullName($customer): string
    {
        return
            mb_strtolower(
                preg_replace(
                    '/\s+/u', ' ',
                    trim(
                        implode(' ', [
                            $customer->firstName,
                            $customer->lastName,
                            $customer->patronymic,
                        ])
                    )
                )
            );
    }

    protected function getCrmName($crmUrl)
    {
        return str_replace('.', '_', str_replace(['/', 'http:', 'https:'], '', $crmUrl));
    }

    protected function readConfig(string $configFilePath): void
    {
        $config = Yaml::parseFile($configFilePath);

        foreach ($config['arguments'] as $key => $argument) {
            if (!$this->input->getArgument($key)) {
                $this->input->setArgument($key, $argument);
            }
        }
        foreach ($config['options'] as $key => $option) {
            if (!$this->input->getOption($key)) {
                $this->input->setOption($key, $option);
            }
        }
    }

    /**
     * @param MGCustomer[] $customers
     * @return bool
     */
    protected function hasActiveChannel(array $customers): bool
    {
        foreach ($customers as $customer) {
            if ($customer->mgChannel->active) {
                return true;
            }
        }

        return false;
    }
}
