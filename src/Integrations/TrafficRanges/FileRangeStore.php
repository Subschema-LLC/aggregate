<?php

declare(strict_types=1);

namespace App\Integrations\TrafficRanges;

use App\Entities\TrafficClass\IpRanges;
use App\Entities\TrafficClass\PublishedRanges;
use App\Entities\TrafficClass\RangeSource;
use App\Entities\TrafficClass\RangeStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Saved range lists, one JSON file per source under var/traffic-ranges. They
 * are runtime data like the rest of var/: never committed or released. Each
 * instance reads the files once, so a request, command or worker sees one
 * fixed set of lists.
 */
final class FileRangeStore implements RangeStore, PublishedRanges
{
    /** @var array<string, IpRanges>|null */
    private ?array $loaded = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/traffic-ranges')]
        private readonly string $directory,
        private readonly PublishedSources $sources = new PublishedSources(),
        private readonly Filesystem $files = new Filesystem(),
    ) {
    }

    public function save(RangeSource $source, IpRanges $ranges, \DateTimeImmutable $fetchedAt): void
    {
        $this->files->dumpFile($this->path($source->id), json_encode([
            'source' => $source->id,
            'operator' => $source->operator,
            'fetched_at' => $fetchedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'ranges' => $ranges->toList(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->loaded = null;
    }

    public function saved(): array
    {
        $saved = [];
        foreach ($this->sources->all() as $source) {
            $data = $this->read($source->id);
            if ($data === null) {
                continue;
            }
            $saved[$source->id] = [
                'count' => count($data['ranges']),
                'fetched_at' => $data['fetched_at'],
            ];
        }

        return $saved;
    }

    public function sourcesContaining(string $ip): array
    {
        if ($this->loaded === null) {
            $this->loaded = [];
            foreach ($this->sources->all() as $source) {
                $data = $this->read($source->id);
                if ($data !== null) {
                    $this->loaded[$source->id] = IpRanges::fromList($data['ranges']);
                }
            }
        }
        $matches = [];
        foreach ($this->sources->all() as $source) {
            if (isset($this->loaded[$source->id]) && $this->loaded[$source->id]->contains($ip)) {
                $matches[] = $source;
            }
        }

        return $matches;
    }

    /** @return array{ranges: list<string>, fetched_at: \DateTimeImmutable}|null */
    private function read(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['ranges'] ?? null)
                || !array_is_list($data['ranges']) || !is_string($data['fetched_at'] ?? null)) {
                return null;
            }
            $ranges = array_values(array_filter($data['ranges'], 'is_string'));

            return [
                'ranges' => $ranges,
                'fetched_at' => new \DateTimeImmutable($data['fetched_at']),
            ];
        } catch (\JsonException|\Exception) {
            // An unreadable file counts as no list; the next refresh replaces it.
            return null;
        }
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/').'/'.$id.'.json';
    }
}
