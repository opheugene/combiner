<?php

namespace App\Command;

use App\Service\Simla\ApiWrapper;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Interfaces\ClientExceptionInterface;
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

    protected static $defaultName = 'duplicates:by';
    protected static $defaultDescription = 'Show and combine duplicates in CRM by email or phone';

    /** @var ParameterBagInterface $params */
    private $params;

    /** @var ApiWrapper $api */
    private $api;

    /** @var SymfonyStyle $io */
    private $io;

    /** @var array $fields */
    private $fields;

    /** @var Table $table */
    private $table;

    public function __construct(ParameterBagInterface $params, ApiWrapper $api)
    {
        $this->params = $params;
        $this->api = $api;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('by', InputArgument::REQUIRED, 'By which field to search for duplicates')
            ->addArgument('fields', InputArgument::IS_ARRAY, 'Fields to show in report')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Get data without cache')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Save report to CSV file')
            ->addOption('combine', null, InputOption::VALUE_NONE, 'Do combine duplicates of clients')
            ->addOption('silent', null, InputOption::VALUE_NONE, 'Do not output debug information')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $by = $input->getArgument('by');
        $this->fields = $input->getArgument('fields');
        //$io->note(sprintf('You passed an argument: %s', $by));

        // get customers by sites
        $noCache = $input->getOption('no-cache');
        $customersBySites = $this->api->getCachedCustomersBySites($noCache);

        // prepare lists of duplicates
        $duplicates = [];
        foreach ($customersBySites as $site => $customers) {
            foreach ($customers as $id => $customer) {

                if ('email' === $by && $customer->email && preg_match(self::EMAIL_REGEXP, $customer->email)) {
                    $duplicates[$site][$customer->email][$id] = $customer;
                } elseif ('phone' === $by) {
                    foreach ($customer->phones as $phone) {
                        $duplicates[$site][$this->clearPhone($phone)][$id] = $customer;
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
                //dump($list);
                $customers[$field] = $this->sort($list);
            }
            if (!count($customers)) {
                unset($duplicates[$site]);
            }
        }
        unset($customers);

        // analyse
        if (!$input->getOption('silent') && $this->fields) {
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
        if ($input->getOption('csv')) {
            $csvPath = $this->params->get('kernel.project_dir') . $this->params->get('report_path');
            foreach ($duplicates as $site => $customers) {
                $csvFullPath = $csvPath . '/report_by_' . $by . '_' . $site . '.csv';
                $csv = fopen($csvFullPath, 'w');
                fputcsv($csv, $this->fields);
                foreach ($customers as $field => $list) {
                    fputcsv($csv, ['Duplicates by ' . $by . ' ' . $field . ': ' . count($list)]);
                    foreach ($list as $customer) {
                        fputcsv($csv, $this->toArray($customer));
                    }
                }
                if (!$input->getOption('silent')) {
                    $this->io->success('CSV file saved to ' . $csvFullPath);
                }
            }
        }

        // combine
        if ($input->getOption('combine')) {
            $combined = 0;
            foreach ($duplicates as $customers) {
                foreach ($customers as $list) {

                    reset($list);
                    $resultCustomerId = key($list);
                    $combineIds = [];
                    foreach ($list as $id => $customer) {
                        if ($id == $resultCustomerId || $this->isExclude($customer)) {
                            continue;
                        }
                        $combineIds[] = $id;
                    }
                    if ($this->combine($resultCustomerId, $combineIds)) {
                        $combined += count($combineIds);
                    }
                }
            }
            if (!$input->getOption('silent') && $combined) {
                $this->io->success('Combined customers: ' . $combined);
            }
        }

        return Command::SUCCESS;
    }

    // sort clients by priority
    function sort($list)
    {
        uasort($list, function($one, $two) {

            // externalId
            if (empty($two->externalId) && !empty($one->externalId)) {
                return -1;
            }
            if (empty($one->externalId) && !empty($two->externalId)) {
                return 1;
            }

            // ordersCount
            if ($two->ordersCount != $one->ordersCount) {
                return $two->ordersCount <=> $one->ordersCount;
            }

            // createdAt
            if (empty($two->createdAt) || empty($two->createdAt->date) || empty($two->createdAt->timezone)) {
                return -1;
            }
            if (empty($one->createdAt) || empty($one->createdAt->date) || empty($one->createdAt->timezone)) {
                return 1;
            }
            $oneCreatedAt = new \DateTimeImmutable($one->createdAt->date, new \DateTimeZone($one->createdAt->timezone));
            $twoCreatedAt = new \DateTimeImmutable($two->createdAt->date, new \DateTimeZone($two->createdAt->timezone));

            if ($oneCreatedAt->getTimestamp() != $twoCreatedAt->getTimestamp()) {
                return $oneCreatedAt->getTimestamp() <=> $twoCreatedAt->getTimestamp();
            }

            // source priority
            //return $this->sourcePriority($two) <=> $this->sourcePriority($one);

            return 0;
        });

        return $list;
    }

    function sourcePriority($customer)
    {
        if (empty($customer->source->source)) {
            return 0;
        }
        if ($customer->source->source == 'one') {
            return 10;
        } elseif ($customer->source->source == 'WordPress') {
            return 9;
        } elseif ($customer->source->source == 'Excel') {
            return 8;
        } elseif ($customer->source->source == 'excel') {
            return 7;
        } elseif ($customer->source->source == 'Instagram') {
            return 6;
        } elseif ($customer->source->source == 'fb') {
            return 5;
        }
    }

    // need to exclude the customer from combining duplicates?
    function isExclude($customer)
    {
        // externalId
        //return !empty($customer->externalId);

        return false;
    }

    function combine($resultCustomerId, $combineCustomersIds)
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

    function addLineToTable($line)
    {
        $this->table->addRow(array_pad([$line], count($this->fields), null));
    }

    function addRowsToTable($customers)
    {
        foreach ($customers as $customer) {
            $this->table->addRow($this->toArray($customer));
        }
    }

    function toArray($customer)
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

    function clearPhone($phone)
    {
        $ph = null;
        if ($phone && !empty($phone->number)) {
            $ph = $phone->number;
            $ph = preg_replace('/[^0-9]/', '', $ph);
            $ph = substr($ph, -10);
        }

        return $ph;
    }
}
