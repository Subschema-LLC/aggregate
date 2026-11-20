<?php

declare(strict_types=1);

namespace App\Delivery\Cli\TrafficClassification;

use App\Features\TrafficClassification\ClassifyRequest\ClassifyRequest;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:traffic:classify',
    description: 'Show how a User-Agent and address would be classified (human, AI agent, '
        .'AI live fetcher, crawler or other automation); nothing is stored',
)]
final class ClassifyCommand
{
    public function __construct(private readonly ClassifyRequest $classify)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument('The User-Agent header to classify')]
        string $userAgent,
        #[Option('The client IP address, checked against the saved range lists')]
        ?string $ip = null,
        #[Option('Treat the request as reporting an automated browser (navigator.webdriver)')]
        bool $automation = false,
        #[Option('Print the result as JSON')]
        bool $json = false,
    ): int {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $io->error('--ip must be an IPv4 or IPv6 address.');

            return Command::INVALID;
        }
        $result = $this->classify->classify($userAgent, $ip, $automation);
        if ($json) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }
        $io->definitionList(
            ['Class' => sprintf('%s (%s)', $result['label'], $result['class'])],
            ['Confidence' => $result['confidence']],
            ['Operator' => $result['operator'] ?? '-'],
        );
        $io->listing($result['reasons']);

        return Command::SUCCESS;
    }
}
