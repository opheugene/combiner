<?php

namespace App\Command;

use App\Service\Simla\ApiWrapper;
use App\Service\Simla\ApiWrapperFactory;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Model\Entity\Customers\Customer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

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

    public function __construct(ParameterBagInterface $params, ApiWrapperFactory $factory)
    {
        $this->params = $params;
        $this->factory = $factory;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('by', InputArgument::REQUIRED, 'By which field to search for duplicates')
            ->addArgument('criteria', InputArgument::IS_ARRAY, 'Customer comparison criteria')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Fields to show in report, comma separated')
            ->addOption('all-sites', null, InputOption::VALUE_NONE, 'Look for duplicates in all sites')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Get data without cache')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Save report to CSV file')
            ->addOption('combine', null, InputOption::VALUE_NONE, 'Do combine duplicates of clients')
            ->addOption('merge-managers', null, InputOption::VALUE_NONE, 'Merge duplicates managers to client')
            ->addOption('merge-phones', null, InputOption::VALUE_REQUIRED, 'Merge numbers to number with country code')

            ->addOption('phoneExactLength', null, InputOption::VALUE_REQUIRED, 'Number of digits for phoneExactLength criteria')
            ->addOption('sourcePriority', null, InputOption::VALUE_REQUIRED, 'Priority of sources for sourcePriority criteria')

            ->addOption('crmUrl', null, InputOption::VALUE_REQUIRED, 'Simla.com URL')
            ->addOption('apiKey', null, InputOption::VALUE_REQUIRED, 'Simla.com API Key')
        ;
    }

    // sort clients by priority
    protected function sort($list)
    {
        uasort($list, function($one, $two) {

            foreach ($this->criteria as $criteria) {
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
                        break;

                    case 'moreData':
                        if ($this->countFilledFields($one) != $this->countFilledFields($two)) {
                            return $this->countFilledFields($two) <=> $this->countFilledFields($one);
                        }
                        break;
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
            list ($source, $priority) = array_pad(explode('=', $value), 2, null);
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

        // get customers by sites
        $noCache = $this->input->getOption('no-cache');
        $allSites = $this->input->getOption('all-sites');
        $customersBySites = $this->api->getCachedCustomersBySites($noCache);

        // prepare lists of duplicates
        $duplicates = [];
        foreach ($customersBySites as $site => $customers) {
            $site = $allSites ? 'all_sites' : $site;
            foreach ($customers as $id => $customer) {
                if ('email' === $by && $customer->email && preg_match(self::EMAIL_REGEXP, $customer->email)) {
                    $duplicates[$site][$customer->email][$id] = $customer;
                } elseif ('phone' === $by) {
                    foreach ($customer->phones as $phone) {
                        if ($clearedPhone = $this->clearPhone($phone)) {
                            $duplicates[$site][$clearedPhone][$id] = $customer;
                        }
                    }
                }
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

        // analyse
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
            $array[] = array_reduce(
                explode('.', $path),
                function ($o, $p) {
                    return !is_null($o) ? (is_array($o->$p) ? json_encode($o->$p) : $o->$p) : null;
//                    if (!is_null($o)) {
//                        if (is_array($o->$p) || is_object($o->$p)) {
//                            return json_encode($o->$p);
//                        } else {
//                            return $o->$p;
//                        }
//                    }
//                    return null;
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

    protected function getCrmName($crmUrl)
    {
        return str_replace('.', '_', str_replace(['/', 'http:', 'https:'], '', $crmUrl));
    }
}
