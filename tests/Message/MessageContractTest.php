<?php

declare(strict_types=1);

namespace App\Tests\Message;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Queued messages outlive a release: during a cluster update, a collection
 * server on one release writes messages a worker on another release reads.
 * So the fields of every queued message class only grow, at the end, with a
 * default; and a message an older release wrote unserializes with every
 * field set. tests/Fixtures/message-contracts.json records the fields.
 */
final class MessageContractTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/message-contracts.json';

    public function testEveryQueuedMessageClassHasAContract(): void
    {
        $routing = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/messenger.yaml')['framework']['messenger']['routing'] ?? [];

        self::assertSame(array_keys($routing), array_keys($this->contracts()), 'Record each queued message class in '.basename(self::FIXTURE).'.');
    }

    public function testFieldsAreOnlyAddedAtTheEndWithADefault(): void
    {
        foreach ($this->contracts() as $class => $recorded) {
            $current = self::fields($class);
            foreach ($recorded as $index => $field) {
                $now = $current[$index] ?? null;
                self::assertNotNull($now, sprintf('%s lost its field %s. Queued messages from the previous release still carry it: keep it for a release, then remove it from the class and the contract together.', $class, $field['name']));
                self::assertSame([$field['name'], $field['type']], [$now['name'], $now['type']], sprintf(
                    '%s changed field %d from %s %s to %s %s. A worker on one release reads messages another release wrote, so fields are never renamed, retyped or reordered: add a new field at the end instead.',
                    $class, $index + 1, $field['type'], $field['name'], $now['type'], $now['name'],
                ));
            }
            foreach (array_slice($current, count($recorded)) as $field) {
                self::assertTrue($field['default'], sprintf('%s\'s new field %s needs a default, which a message from the previous release gets.', $class, $field['name']));
            }
            self::assertSame($recorded, $current, sprintf(
                "%s gained fields, which is allowed. Record them in %s:\n%s",
                $class,
                basename(self::FIXTURE),
                json_encode([$class => $current], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            ));
        }
    }

    public function testAMessageAnOlderReleaseWroteGetsTheNewFieldsDefaults(): void
    {
        foreach (array_keys($this->contracts()) as $class) {
            $reflection = new \ReflectionClass($class);
            $old = $reflection->newInstanceWithoutConstructor();
            // The oldest message this class can read: only the fields without a default.
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if (!$parameter->isDefaultValueAvailable()) {
                    $reflection->getProperty($parameter->getName())->setValue($old, self::sample((string) $parameter->getType()));
                }
            }

            $read = unserialize(serialize($old));

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $property = $reflection->getProperty($parameter->getName());
                self::assertTrue($property->isInitialized($read), sprintf('%s::$%s is not set on a message from an older release; give the class a __wakeup() that sets defaults.', $class, $parameter->getName()));
                if ($parameter->isDefaultValueAvailable()) {
                    self::assertSame($parameter->getDefaultValue(), $property->getValue($read));
                }
            }
        }
    }

    /** @return array<class-string, list<array{name: string, type: string, default: bool}>> */
    private function contracts(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return list<array{name: string, type: string, default: bool}> */
    private static function fields(string $class): array
    {
        return array_map(static fn (\ReflectionParameter $parameter): array => [
            'name' => $parameter->getName(),
            'type' => (string) $parameter->getType(),
            'default' => $parameter->isDefaultValueAvailable(),
        ], (new \ReflectionMethod($class, '__construct'))->getParameters());
    }

    private static function sample(string $type): mixed
    {
        return match (ltrim($type, '?')) {
            'string' => 'x',
            'int' => 1,
            'bool' => true,
            'array' => [],
            'DateTimeImmutable' => new \DateTimeImmutable('2026-11-11 10:00:00'),
            default => null,
        };
    }
}
