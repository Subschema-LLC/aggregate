<?php

declare(strict_types=1);

namespace App\Delivery\Alerts;

use App\Service\Alerts\AlertRuleValidator;
use Symfony\Component\Console\Attribute\Option;

/** The options of app:alerts:rule, and the rule they describe. */
final class RuleOptions
{
    #[Option('Rule ID slug (e.g. geektown-weekly)')]
    public ?string $id = null;

    #[Option('Covered target: all, group:<id>, or website:<token>')]
    public ?string $covers = null;

    #[Option('Covered website token')]
    public ?string $website = null;

    #[Option('Covered website group ID')]
    public ?string $group = null;

    #[Option('Cover all registered websites')]
    public bool $allWebsites = false;

    #[Option('Rule source: events or tracking_failures')]
    public ?string $source = null;

    #[Option('Filter privacy mode (events only): all, enhanced or anonymous')]
    public ?string $privacyMode = null;

    #[Option('Filter event name (events only, e.g. page_view)')]
    public ?string $eventName = null;

    #[Option('Condition: no_events, fewer_than or more_than')]
    public ?string $condition = null;

    #[Option('Threshold integer N', shortcut: 'N')]
    public ?string $threshold = null;

    #[Option('Sliding window: hour, day or week')]
    public ?string $window = null;

    /** @var list<string> */
    #[Option('Destination ID (repeatable)')]
    public array $destination = [];

    #[Option('Whether the rule is enabled (1 or 0)')]
    public ?string $enabled = null;

    /**
     * The given options over $base, the saved rule when editing.
     *
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    public function candidate(array $base): array
    {
        $candidate = $base;
        if ($this->id !== null) {
            $candidate['id'] = $this->id;
        }
        $covers = $this->coversValue();
        if ($covers !== null) {
            $candidate['covers'] = $covers;
        }
        if ($this->source !== null) {
            $candidate['source'] = $this->source;
            if (strtolower(trim($this->source)) === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
                $candidate['privacy_mode'] = AlertRuleValidator::PRIVACY_ALL;
                $candidate['event_name'] = '';
            }
        }
        $given = [
            'privacy_mode' => $this->privacyMode,
            'event_name' => $this->eventName,
            'condition' => $this->condition,
            'threshold' => $this->threshold,
            'window' => $this->window,
        ];
        foreach ($given as $key => $value) {
            if ($value !== null) {
                $candidate[$key] = $value;
            }
        }
        if ($this->destination !== []) {
            $candidate['destinations'] = $this->destination;
        }
        if ($this->enabled !== null) {
            $candidate['enabled'] = $this->enabled;
        }

        return $candidate;
    }

    private function coversValue(): ?string
    {
        return match (true) {
            $this->allWebsites => 'all',
            $this->group !== null => 'group:'.trim($this->group),
            $this->website !== null => 'website:'.trim($this->website),
            default => $this->covers,
        };
    }
}
