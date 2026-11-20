<?php

declare(strict_types=1);

namespace App\Integrations\TrafficRanges;

use App\Entities\TrafficClass\BotUserAgents;
use App\Service\BotDetection\BotDetector;
use App\Service\BotTrafficSettings;

/** The collection endpoint's bot list, with the operator's bot_user_agents phrases. */
final class BotDetectorUserAgents implements BotUserAgents
{
    public function __construct(private readonly BotTrafficSettings $settings)
    {
    }

    public function isBot(string $userAgent): bool
    {
        return BotDetector::isBot($userAgent, $this->settings->userAgents());
    }
}
