<?php

declare(strict_types=1);

namespace App\Command;

use App\Pegasus\PegasusOrderApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pegasus:create-dummy-order',
    description: 'Creates a single dummy order via Pegasus API and prints the result.',
)]
final class CreateDummyOrderCommand extends Command
{
    public function __construct(private readonly PegasusOrderApiClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Item SKU (random if omitted)')
            ->addOption('ean', null, InputOption::VALUE_REQUIRED, 'Item EAN (falls back to env/default)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'API user (falls back to env)')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'API secret (falls back to env)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sku = (string) ($input->getOption('sku') ?? '');
        if ($sku === '') {
            $sku = $this->client->getDefaultItemSku();
        }
        if ($sku === '') {
            $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
        }

        $ean = (string) ($input->getOption('ean') ?? $this->client->getDefaultItemEan());

        $user = (string) ($input->getOption('user') ?? $this->client->getDefaultApiUser());
        $secret = (string) ($input->getOption('secret') ?? $this->client->getDefaultApiSecret());

        $io->title('Pegasus Dummy Order');
        $io->writeln("SKU:  $sku");
        $io->writeln("EAN:  " . ($ean !== '' ? $ean : '(default)'));
        $io->writeln("User: $user");

        $result = $this->client->createDummyOrder($sku, $ean, $user, $secret);

        $io->section('Result');
        $io->writeln("HTTP status:         {$result->statusCode}");
        $io->writeln("External order ID:   {$result->externalOrderId}");
        $io->writeln("External order #:    {$result->externalOrderNumber}");
        $io->writeln("Message:             {$result->message}");

        if ($result->responseBody !== '') {
            $io->section('Response body');
            $io->writeln($result->responseBody);
        }

        $decoded = json_decode($result->responseBody, true);
        $pegasusSuccess = isset($decoded['payload']['success']) && $decoded['payload']['success'] === true;

        if ($result->success && $pegasusSuccess) {
            $io->success('payload.success = true — order accepted.');
            return Command::SUCCESS;
        }

        if ($result->success && !$pegasusSuccess) {
            $io->warning('HTTP 2xx but payload.success is not true (empty body or unexpected format).');
            return Command::FAILURE;
        }

        $io->error("Order failed (HTTP {$result->statusCode}).");
        return Command::FAILURE;
    }
}
