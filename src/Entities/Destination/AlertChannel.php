<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * Sends one alert to one destination: a signed webhook, or an email to each
 * listed recipient. It never throws for a delivery problem; the reason says
 * what happened, without secrets, URLs or addresses.
 *
 * @phpstan-import-type DestinationArray from Destination
 *
 * @phpstan-type AlertPayload array{
 *     schema_version: '1',
 *     id: string,
 *     incident: string,
 *     type: 'firing'|'recovered'|'test',
 *     rule: string,
 *     website: string,
 *     host: string,
 *     installation: string,
 *     alert_condition: string,
 *     window_end: string
 * }
 */
interface AlertChannel
{
    /**
     * @param DestinationArray $destination
     * @param AlertPayload     $payload
     *
     * @return array{sent: bool, reason: string}
     */
    public function send(array $destination, array $payload, string $conditionDescription): array;

    /**
     * Sends to each listed recipient on its own and returns every result, for
     * test alerts; a webhook gives one result.
     *
     * @param DestinationArray $destination
     * @param AlertPayload     $payload
     *
     * @return list<array{sent: bool, reason: string}>
     */
    public function sendEach(
        array $destination,
        array $payload,
        string $conditionDescription,
    ): array;
}
