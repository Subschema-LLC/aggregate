<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * Turns a request's signals into one class, in this order:
 *
 *  1. an address in an operator's published bot list: verified;
 *  2. a User-Agent naming a documented AI or search agent: declared;
 *  3. a User-Agent the bot list or the operator's phrases mark: declared;
 *  4. weighted signals reaching AGENT_THRESHOLD: an AI agent, inferred;
 *  5. otherwise human, inferred.
 *
 * A cloud network alone stays below the threshold: VPNs and corporate
 * proxies share those networks with automated browsers.
 */
final class Classifier
{
    public const AUTOMATION_WEIGHT = 2;
    public const CLOUD_WEIGHT = 1;
    public const AGENT_THRESHOLD = 2;

    public static function classify(Signals $signals): Classification
    {
        $agent = $signals->knownAgent;
        $botRange = $signals->botRange();
        if ($botRange !== null) {
            $class = $botRange->class
                ?? ($agent !== null && $agent['operator'] === $botRange->operator
                    ? $agent['class'] : TrafficClass::Automation);

            return new Classification($class, Confidence::Verified, $botRange->operator, [
                sprintf('The address is in the published "%s" list.', $botRange->id),
            ]);
        }
        if ($agent !== null) {
            return new Classification($agent['class'], Confidence::Declared, $agent['operator'], [
                sprintf('The User-Agent names %s; the address is not verified.', $agent['token']),
            ]);
        }
        if ($signals->botUserAgent) {
            return new Classification(TrafficClass::Automation, Confidence::Declared, null, [
                'The User-Agent belongs to a crawler, monitor or automation tool.',
            ]);
        }

        $score = 0;
        $reasons = [];
        if ($signals->automationReported) {
            $score += self::AUTOMATION_WEIGHT;
            $reasons[] = 'The browser reports that it is automated.';
        }
        $cloud = $signals->cloudRange();
        if ($cloud !== null) {
            $score += self::CLOUD_WEIGHT;
            $reasons[] = sprintf('The address is in the "%s" cloud network list.', $cloud->id);
        }
        if ($score >= self::AGENT_THRESHOLD) {
            return new Classification(TrafficClass::AiAgent, Confidence::Inferred, null, $reasons);
        }
        $reasons[] = 'Nothing marked the request as automated.';

        return new Classification(TrafficClass::Human, Confidence::Inferred, null, $reasons);
    }
}
