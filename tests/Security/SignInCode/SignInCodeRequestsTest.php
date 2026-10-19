<?php

declare(strict_types=1);

namespace App\Tests\Security\SignInCode;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\SignInCode\SignInCodeRequests;
use App\Security\SignInCode\SignInCodes;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SignInCodeRequestsTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private Connection $connection;
    private SignInCodes $codes;
    private InMemoryStorage $limits;
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['EMAIL_POSTMARK_SERVER_TOKEN'], $_SERVER['EMAIL_POSTMARK_SERVER_TOKEN']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-sign-in-codes-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['brand_name' => 'Example', 'email_enabled' => true, 'email_provider' => 'postmark', 'email_from_address' => 'no-reply@example.com', 'sign_in_codes_enabled' => true]));
        (new EmailSecrets($this->projectDir))->set((new PostmarkProvider())->credential(), 'token');
        $this->connection = OperationsDatabase::connection();
        $this->codes = new SignInCodes('test-secret');
        $this->limits = new InMemoryStorage();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAKnownAccountGetsItsCodeAfterTheResponse(): void
    {
        $requests = $this->requests();
        $request = $this->request();

        self::assertTrue($requests->request($request, 'scott'));
        self::assertSame([], $this->sent, 'Nothing is sent before the response');
        self::assertSame('scott', $this->codes->pendingUsername($request->getSession()));
        $requests->deliver();

        self::assertCount(1, $this->sent);
        self::assertSame('scott@example.com', $this->sent[0]['To']);
        self::assertSame('Your Example sign-in code', $this->sent[0]['Subject']);
        self::assertSame('sign_in_code', $this->sent[0]['Tag']);
        self::assertSame(1, preg_match('/\b([0-9]{6})\b/', $this->sent[0]['TextBody'], $match));
        self::assertStringNotContainsString($match[1], $this->sent[0]['Subject'], 'The code stays out of the subject, which lock screens show');
        self::assertSame('scott', $this->codes->verify($request->getSession(), $match[1]));

        $task = $this->connection->fetchAssociative('SELECT subject, status, triggered_by, requested_by FROM processing_tasks');
        self::assertSame(['subject' => 'sign_in_code', 'status' => 'succeeded', 'triggered_by' => 'request', 'requested_by' => 'scott'], $task);
        $audit = $this->connection->fetchAllAssociative("SELECT category, operation, outcome, actor, details FROM audit_trail WHERE category = 'sign_ins'");
        self::assertSame([['category' => 'sign_ins', 'operation' => 'sign_in_code_requested', 'outcome' => 'succeeded', 'actor' => 'scott', 'details' => 'A sign-in code was requested.']], $audit);
        $stored = (string) json_encode([$this->connection->fetchAllAssociative('SELECT * FROM processing_tasks'), $this->connection->fetchAllAssociative('SELECT * FROM audit_trail')]);
        self::assertStringNotContainsString($match[1], $stored, 'The code is never recorded');
        self::assertStringNotContainsString('scott@example.com', $stored);
    }

    public function testAnAddressFindsTheOneAccountThatHasItIgnoringCase(): void
    {
        $requests = $this->requests();

        self::assertTrue($requests->request($this->request(), 'Scott@Example.com'));
        $requests->deliver();
        self::assertSame('scott@example.com', $this->sent[0]['To']);

        self::assertTrue($requests->request($this->request(), 'shared@example.com'));
        $requests->deliver();
        self::assertCount(1, $this->sent, 'An address several accounts share matches none');
    }

    public function testNoAccountLeavesADecoyAndSendsNothing(): void
    {
        $requests = $this->requests();
        foreach (['nobody', 'no-email', 'nobody@example.com'] as $identifier) {
            $request = $this->request();
            self::assertTrue($requests->request($request, $identifier));
            self::assertNotNull($this->codes->pending($request->getSession()), 'The page behaves as if a code was sent');
            self::assertNull($this->codes->pendingUsername($request->getSession()));
        }
        $requests->deliver();

        self::assertSame([], $this->sent);
        $actors = $this->connection->fetchFirstColumn("SELECT actor FROM audit_trail WHERE operation = 'sign_in_code_requested' AND outcome = 'failed'");
        self::assertSame([null, null, null], $actors, 'What was typed is never recorded');
    }

    public function testAnAccountGetsAtMostThreeCodesInFifteenMinutes(): void
    {
        $requests = $this->requests();
        $session = $this->request()->getSession();
        for ($i = 0; $i < 4; ++$i) {
            $request = Request::create('/login/code', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.'.$i]);
            $request->setSession($session);
            self::assertTrue($requests->request($request, 'scott'));
        }
        $requests->deliver();

        self::assertCount(3, $this->sent);
        self::assertSame('scott', $this->codes->pendingUsername($session), 'The browser keeps the code it already has');
        self::assertSame('Too many codes requested for this account; none was sent.', $this->connection->fetchOne("SELECT details FROM audit_trail WHERE outcome = 'failed'"));
    }

    public function testOneAddressMayAskTenTimesInFifteenMinutes(): void
    {
        $requests = $this->requests();
        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($requests->request($this->request(), 'nobody-'.$i));
        }

        self::assertFalse($requests->request($this->request(), 'scott'));
        $requests->deliver();
        self::assertSame([], $this->sent);
    }

    private function request(): Request
    {
        $request = Request::create('/login/code', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function requests(): SignInCodeRequests
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = json_decode((string) $options['body'], true);

            return new MockResponse('{"MessageID":"pm-1"}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $audit = new AuditTrail($this->connection);
        $mailer = new TransactionalMailer(new EmailSettings($config, EmailProviders::builtIn()), new EmailSecrets($this->projectDir), new ProcessingTasks($this->connection, $audit), new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'name']), new AppBranding($config, $this->projectDir), $config, new NullLogger(), EmailTestKit::transports($client));

        return new SignInCodeRequests(
            $this->codes,
            $this->users(),
            $mailer,
            new AdminAudit($audit, new NullLogger()),
            new NullLogger(),
            new RateLimiterFactory(['id' => 'sign_in_code_request', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '15 minutes'], $this->limits),
            new RateLimiterFactory(['id' => 'sign_in_code_request_address', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'], $this->limits),
        );
    }

    private function users(): UserRepository
    {
        $user = static function (string $username, ?string $email): User {
            $user = (new User())->setUsername($username);
            $user->setEmail($email);

            return $user;
        };
        $accounts = ['scott' => $user('scott', 'scott@example.com'), 'no-email' => $user('no-email', null)];
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $accounts[$criteria['username'] ?? ''] ?? null);
        $users->method('findOnlyByEmail')->willReturnCallback(static fn (string $email): ?User => strtolower($email) === 'scott@example.com' ? $accounts['scott'] : null);

        return $users;
    }
}
