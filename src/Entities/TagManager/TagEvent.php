<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates the event object for Send an Aggregate event tags.
 */
final class TagEvent
{
    public const EVENT_FIELDS = ['eventName', 'customData', 'goalEvent', 'pagePath'];
    public const TRACKER_FIELDS = [
        'websiteToken', 'referrerChannel', 'deviceClass', 'viewportBucket',
        'consentState', 'visitorId', 'sessionId', 'screenWidth', 'org_internal_traffic',
    ];
    private const EVENT_NAME = '/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D';
    public const MAX_PROPERTY_BYTES = 500;
    public const MAX_ENTRIES = 32;

    /**
     * @param array<string, mixed> $variables
     * @return array{eventName: string|array, customData?: array<string, mixed>,
     *               goalEvent?: string|array|null, pagePath?: string|array}
     */
    public static function validate(
        mixed $event,
        array $variables,
        CustomDataProperties $properties,
        string $label = 'The event',
    ): array {
        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new \InvalidArgumentException(
                $label.' must be an object such as {"eventName": "login", '
                .'"customData": {"method": "password"}}.',
            );
        }
        $unknown = array_diff(array_map('strval', array_keys($event)), self::EVENT_FIELDS);
        if ($unknown !== []) {
            $field = reset($unknown);
            throw new \InvalidArgumentException(
                $label.' has '.$field.', but it takes only eventName, customData, '
                .'goalEvent and pagePath. '
                .(in_array($field, self::TRACKER_FIELDS, true)
                    ? 'The tracker sets '.$field.' itself.'
                    : 'Put the event\'s properties in customData.'),
            );
        }
        if (!array_key_exists('eventName', $event)) {
            throw new \InvalidArgumentException($label.' needs an eventName, such as "login".');
        }
        $validated = [
            'eventName' => self::eventValue(
                $event['eventName'],
                $variables,
                $label.': eventName',
                static fn (mixed $name): bool => is_string($name)
                    && preg_match(self::EVENT_NAME, $name) === 1,
                'must be a fixed name such as "login": a letter, then up to 99 '
                .'letters, digits, _ . : or -',
            ),
        ];

        if (array_key_exists('customData', $event)) {
            $customData = $event['customData'];
            if (!is_array($customData) || ($customData !== [] && array_is_list($customData))) {
                throw new \InvalidArgumentException(
                    $label.': customData must be an object of properties, '
                    .'such as {"method": "password"}.',
                );
            }
            if (count($customData) > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException(
                    $label.': customData may hold at most '.self::MAX_ENTRIES.' properties.',
                );
            }
            $validated['customData'] = [];
            foreach ($customData as $key => $value) {
                $key = (string) $key;
                if (!$properties->isValidKey($key)) {
                    throw new \InvalidArgumentException(
                        $label.': customData.'.mb_strimwidth($key, 0, 64, '…')
                        .' is not a property name. Use a letter, then up to '
                        .'63 letters, digits, _ . or -.',
                    );
                }
                if ($properties->isBuiltin($key)) {
                    throw new \InvalidArgumentException(
                        $label.': the tracker adds customData.'.$key.' itself; leave it out.',
                    );
                }
                $validated['customData'][$key] = self::eventValue(
                    $value,
                    $variables,
                    $label.': customData.'.$key,
                    static fn (mixed $v): bool => $v === null || is_bool($v)
                        || (is_string($v) && strlen($v) <= self::MAX_PROPERTY_BYTES
                            && preg_match('//u', $v) === 1)
                        || ((is_int($v) || is_float($v)) && is_finite((float) $v)
                            && !(floor((float) $v) === (float) $v
                                && abs($v) > TagValues::MAX_SAFE_INTEGER)),
                    'must be text of at most '.self::MAX_PROPERTY_BYTES
                    .' bytes, a number, true, false or null, not an object or a list',
                );
            }
            if ($validated['customData'] === []) {
                unset($validated['customData']);
            }
        }
        if (array_key_exists('goalEvent', $event)) {
            $validated['goalEvent'] = self::eventValue(
                $event['goalEvent'],
                $variables,
                $label.': goalEvent',
                static fn (mixed $goal): bool => $goal === null
                    || (is_string($goal) && $goal !== '' && trim($goal) === $goal
                        && strlen($goal) <= 100 && preg_match('/[\x00-\x1f\x7f]/', $goal) !== 1),
                'must name a goal from config/goals.yaml, or be null',
            );
        }
        if (array_key_exists('pagePath', $event)) {
            $validated['pagePath'] = self::eventValue(
                $event['pagePath'],
                $variables,
                $label.': pagePath',
                static fn (mixed $path): bool => is_string($path)
                    && strlen($path) <= 512
                    && preg_match('#^/(?!/)[^?\#\\\\\x00-\x1f\x7f]*$#D', $path) === 1,
                'must be a path on the site such as /checkout/step-2, '
                .'without a query or fragment',
            );
        }

        return $validated;
    }

    private static function eventValue(
        mixed $value,
        array $variables,
        string $label,
        \Closure $valid,
        string $rule,
    ): mixed {
        if (is_array($value) && !array_is_list($value) && array_key_exists('$var', $value)) {
            if (!TagValues::isReference($value)) {
                throw new \InvalidArgumentException(
                    $label.' uses {"$var": …}; an optional "from" field '
                    .'must be "recent" or "event".',
                );
            }
            $alias = $value['$var'];
            if (!is_string($alias) || !array_key_exists($alias, $variables)) {
                throw new \InvalidArgumentException(
                    $label.' uses {"$var": …} with a variable that is not defined. '
                    .'Add it under Variables.',
                );
            }

            return ($value['from'] ?? 'recent') === 'event'
                ? ['$var' => $alias, 'from' => 'event']
                : ['$var' => $alias];
        }
        if (!$valid($value)) {
            $suffix = is_array($value)
                ? '. To read a value from the page, use {"$var": "alias"}.'
                : '.';
            throw new \InvalidArgumentException($label.' '.$rule.$suffix);
        }

        return $value;
    }
}
