<?php

declare(strict_types=1);

namespace App\Command;

use App\Middleware\MiddlewareProductRepository;
use App\Pegasus\PegasusOrderApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pegasus:create-dummy-orders-from-stock',
    description: 'Creates one dummy order per in-stock product fetched from the middleware DB.',
)]
final class CreateDummyOrdersFromStockCommand extends Command
{
    public function __construct(
        private readonly PegasusOrderApiClient $client,
        private readonly MiddlewareProductRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'API user email (falls back to PEGASUS_API_USER)')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'API secret (falls back to PEGASUS_API_SECRET)')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Filter products by SKU substring', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of products to process (0 = all)', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List products that would be ordered without calling the API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->productRepository->isConfigured()) {
            $io->error('DB not configured. Set PEGASUS_STAGING_DB_* in .env.local.');
            return Command::FAILURE;
        }

        $apiUser   = (string) ($input->getOption('user') ?? $this->client->getDefaultApiUser());
        $apiSecret = (string) ($input->getOption('secret') ?? $this->client->getDefaultApiSecret());
        $search    = (string) ($input->getOption('search') ?? '');
        $limit     = max(0, (int) ($input->getOption('limit') ?? 100));
        $dryRun    = (bool) $input->getOption('dry-run');

        if ($apiUser === '') {
            $io->error('No API user. Pass --user or set PEGASUS_API_USER in .env.local.');
            return Command::FAILURE;
        }

        $io->title('Pegasus — Dummy Orders from Stock');
        $io->writeln("API user:  $apiUser");
        $io->writeln("SKU filter: " . ($search !== '' ? $search : '(none)'));
        $io->writeln("Limit:     " . ($limit === 0 ? 'all' : $limit));

        $fetchLimit  = $limit === 0 ? 10000 : $limit;
        $total       = $this->productRepository->countInStockProducts($apiUser, $search);
        $fetchActual = min($fetchLimit, $total);

        $io->writeln("In-stock products found: $total" . ($fetchActual < $total ? " (processing first $fetchActual)" : ''));

        if ($total === 0) {
            $io->warning('No in-stock products found for this user / filter.');
            return Command::SUCCESS;
        }

        $products = $this->productRepository->findInStockProducts($apiUser, $search, $fetchActual, 0);

        if ($dryRun) {
            $io->section('Dry-run — products that would be ordered');
            $rows = array_map(
                static fn(array $p): array => [$p['sku'], $p['ean'] ?: '—', (string) $p['stock']],
                $products,
            );
            $io->table(['SKU', 'EAN', 'Lager'], $rows);
            $io->note(count($products) . ' orders would be created.');
            return Command::SUCCESS;
        }

        if (!$this->client->isConfigured()) {
            $io->error('Pegasus API not configured. Set PEGASUS_API_BASE in .env.local.');
            return Command::FAILURE;
        }

        $io->section('Creating orders…');

        $progressBar = new ProgressBar($output, count($products));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progressBar->setMessage('starting…');
        $progressBar->start();

        $okCount   = 0;
        $failCount = 0;
        $errors    = [];

        foreach ($products as $product) {
            $progressBar->setMessage($product['sku']);
            $result = $this->client->createDummyOrder($product['sku'], $product['ean'], $apiUser, $apiSecret);
            if ($result->success) {
                $okCount++;
            } else {
                $failCount++;
                $errors[] = sprintf(
                    'FAIL  HTTP %d  %-40s  %s',
                    $result->statusCode,
                    $product['sku'],
                    $result->message,
                );
            }
            $progressBar->advance();
        }

        $progressBar->setMessage('done');
        $progressBar->finish();
        $output->writeln('');

        if ($errors !== []) {
            $io->section('Failures');
            foreach ($errors as $err) {
                $io->writeln("<fg=red>$err</>");
            }
        }

        $io->writeln("Result: <fg=green>$okCount ok</> / <fg=red>$failCount failed</> of " . count($products));

        return $failCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
