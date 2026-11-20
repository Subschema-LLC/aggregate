<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Delivery\Cli\TrafficClassification\ClassifyCommand;
use App\Delivery\Cli\TrafficClassification\RangesCommand;
use App\Entities\TrafficClass\BotUserAgents;
use App\Features\TrafficClassification\ClassifyRequest\ClassifyRequest;
use App\Features\TrafficClassification\RefreshRanges\RefreshRanges;
use App\Features\TrafficClassification\ShowRanges\ShowRanges;
use App\Integrations\TrafficRanges\FileRangeStore;
use App\Integrations\TrafficRanges\HttpRangeDownloads;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TrafficClassificationCommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-ranges-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRefreshesTheListsThenClassifiesByAddress(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => match (true) {
            str_ends_with($url, '/chatgpt-user.json') => new MockResponse(
                '{"prefixes":[{"ipv4Prefix":"192.0.2.0/24"}]}',
            ),
            str_contains($url, 'amazonaws') => new MockResponse(
                '{"prefixes":[{"ip_prefix":"198.51.100.0/24"}],"ipv6_prefixes":[]}',
            ),
            default => new MockResponse('', ['http_code' => 404]),
        });

        $ranges = $this->rangesCommand($http);
        self::assertSame(Command::FAILURE, $ranges->execute(['--refresh' => true]));
        self::assertStringContainsString('1 ranges saved', $ranges->getDisplay());
        self::assertStringContainsString('kept previous: The list answered HTTP 404', $ranges->getDisplay());

        $classify = $this->classifyCommand();
        $classify->execute([
            'user-agent' => 'Mozilla/5.0 Chrome/131.0 Safari/537.36',
            '--ip' => '192.0.2.44',
            '--json' => true,
        ]);
        $result = json_decode($classify->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['ai_fetcher', 'verified', 'openai'], [
            $result['class'], $result['confidence'], $result['operator'],
        ]);

        $classify->execute(['user-agent' => 'Mozilla/5.0', '--ip' => '198.51.100.1', '--automation' => true]);
        self::assertStringContainsString('AI agent (interactive) (ai_agent)', $classify->getDisplay());
    }

    public function testShowsSourcesWithoutDownloading(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('No download expected.');
        });
        $ranges = $this->rangesCommand($http);

        self::assertSame(Command::SUCCESS, $ranges->execute(['--json' => true]));
        $rows = json_decode($ranges->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(0, $rows[0]['count']);
        self::assertNull($rows[0]['fetched_at']);
    }

    public function testRefusesAnInvalidAddress(): void
    {
        $classify = $this->classifyCommand();

        self::assertSame(Command::INVALID, $classify->execute(['user-agent' => 'x', '--ip' => '999.1.1.1']));
    }

    private function rangesCommand(MockHttpClient $http): CommandTester
    {
        $downloads = new HttpRangeDownloads($http);
        $store = new FileRangeStore($this->directory);

        return new CommandTester(new Command(null, new RangesCommand(
            new RefreshRanges($downloads, $store),
            new ShowRanges($downloads, $store),
        )));
    }

    private function classifyCommand(): CommandTester
    {
        $bots = new class implements BotUserAgents {
            public function isBot(string $userAgent): bool
            {
                return false;
            }
        };

        return new CommandTester(new Command(null, new ClassifyCommand(
            new ClassifyRequest(new FileRangeStore($this->directory), $bots),
        )));
    }
}
