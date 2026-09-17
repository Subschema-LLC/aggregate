<?php

declare(strict_types=1);

namespace App\Service;

/** Builds shareable request examples from policy alone, without observing events. */
final class EventExampleGenerator
{
    public function __construct(private readonly CustomDataSettings $settings)
    {
    }

    public function generate(string $example = 'model'): array
    {
        if (!in_array($example, ['model', 'ecommerce'], true)) {
            throw new \InvalidArgumentException('Choose model or ecommerce for the example source.');
        }
        // Use the same validation and scalar/consent filtering as ingestion.
        // Descriptions and other operator configuration are never sample values.
        $model = $this->settings->toArray();
        $ecommerce = $example === 'ecommerce';
        if ($ecommerce) {
            $model = $this->settings->validate($this->ecommerceModel());
        }
        $ecommerceValues = [
            'currency' => 'USD', 'total_minor' => 4999, 'tax_minor' => 400,
            'shipping_minor' => 500, 'item_count' => 2, 'discount_rate' => 0.1,
            'product_category' => 'accessories', 'checkout_step' => 'complete',
        ];
        $candidates = [];
        foreach ($model[CustomDataSettings::PROPERTIES_KEY] as $key => $definition) {
            $candidates[$key] = $ecommerce ? $ecommerceValues[$key] : match ($definition['type'] ?? 'scalar') {
                'integer' => 2,
                'float', 'double' => 12.5,
                'boolean' => true,
                default => match ($key) {
                    'utm_medium' => 'email',
                    'utm_source' => 'example-newsletter',
                    'utm_campaign' => 'example-campaign',
                    'utm_term' => 'example-term',
                    'utm_content' => 'example-banner',
                    'utm_id' => 'example-campaign-id',
                    default => 'example',
                },
            };
        }
        $anonymous = ($ecommerce ? $this->settings->filterEventDataForModel($candidates, false, $model)
            : $this->settings->filterEventData($candidates, false)) ?? [];
        $enhanced = ($ecommerce ? $this->settings->filterEventDataForModel($candidates, true, $model)
            : $this->settings->filterEventData($candidates, true)) ?? [];
        $properties = [];
        foreach ($model[CustomDataSettings::PROPERTIES_KEY] as $key => $definition) {
            $properties[] = [
                'key' => $key,
                'type' => $definition['type'] ?? 'scalar',
                'consent_required' => $definition['consent_required'],
                'collection' => isset($anonymous[$key]) ? 'anonymous_and_enhanced'
                    : (isset($enhanced[$key]) ? 'enhanced_only' : 'not_submittable'),
                'query_parameters' => array_keys($model[CustomDataSettings::MAPPINGS_KEY], $key, true),
            ];
        }

        $common = [
            'websiteToken' => 'REPLACE_WITH_PUBLIC_WEBSITE_TOKEN',
            'eventName' => $ecommerce ? 'purchase' : 'view',
            'pagePath' => $ecommerce ? '/checkout/complete' : '/example',
            'referrerChannel' => 'direct',
            'deviceClass' => 'desktop',
            'viewportBucket' => 'large',
            'internalTraffic' => false,
        ];

        $bundle = [
            'schema_version' => 1,
            'synthetic' => true,
            'source' => $ecommerce ? 'ecommerce_recommendation' : 'saved_model',
            'endpoint' => ['method' => 'POST', 'path' => '/api/receive'],
            'notes' => [
                $ecommerce
                    ? 'This ecommerce recommendation is an unsaved proposed model with fixed synthetic values. Review and merge its YAML into the active model before using its property or numeric reporting rules. No settings are saved and no events are read or sent.'
                    : 'These examples use the saved deployment model and fixed synthetic values. No events are read or sent.',
                'Send only an example payload as the JSON request body. Replace the public website token placeholder and use an Origin allowed for that website. Never substitute an administrator or sharing token.',
                'Declared types determine the synthetic values. Properties without a type use illustrative strings. Types do not define a permitted-value vocabulary; review actual bounded scalar values before collection.',
                'Integer properties use whole numbers within the JavaScript safe-integer range. Float and double accept finite JSON numbers and project approximate double precision; numeric strings are not automatically converted.',
                'Anonymous examples contain only modeled properties explicitly allowed without consent. Unlisted ordinary properties require enhanced consent and are not included in these examples.',
                'The enhanced example requires an actual explicit analytics consent choice. consentState: granted illustrates that choice; copying it does not obtain consent. Visitor and session identifiers are synthetic placeholders.',
                'Rejecting or withdrawing enhanced consent removes SDK identifiers and stops future enhanced detail; permitted coarse anonymous collection may continue. Withdrawal does not erase stored history.',
                'For anonymous attribution, prefer broad utm_medium values. Detailed UTM properties and aliases remain permitted when explicitly allowed, but even an allowed medium can contain identifying text.',
                'Properties marked not_submittable are reserved organization-marker or legacy reporting-only keys. The tracker supplies internalTraffic separately as a boolean; marker values and sharing tokens are never event properties.',
                'The collection kill switch, sensitive-path exclusions, website validation, and server privacy rules still apply. The server sets timestamps and optional coarse geography; those fields are not supplied by the client.',
                'Goals are omitted because they have a separate allowlist and anonymous-consent policy in config/goals.yaml.',
            ],
            'properties' => $properties,
            'examples' => [
                'anonymous' => [
                    'consent_required' => false,
                    'payload' => $common + ['consentState' => 'denied', 'eventData' => (object) $anonymous],
                ],
                'enhanced' => [
                    'consent_required' => true,
                    'payload' => $common + [
                        'consentState' => 'granted',
                        'visitorId' => 'synthetic-visitor',
                        'sessionId' => 'synthetic-session',
                        'screenWidth' => 1440,
                        'eventData' => (object) $enhanced,
                    ],
                ],
            ],
        ];
        if ($ecommerce) {
            $bundle['recommended_model'] = $model;
            $bundle['notes'][] = 'Use integer minor-unit amounts with an explicit currency for money. The example total_minor of 4999 means USD 49.99; tax_minor and shipping_minor describe parts of that total, not amounts to add again. discount_rate is an approximate ratio from 0 to 1. Keep purchase JSON flat; omit order, cart, customer and payment identifiers, personal text, and item arrays.';
            $bundle['notes'][] = 'Minor units depend on the currency: do not assume every currency has two decimal places. Group calculations by currency and use its minor-unit scale. Requests have no deduplication key; retries can record duplicate purchases, so totals describe collected events rather than an accounting ledger.';
        }

        return $bundle;
    }

    /** Export the same metadata for either or both request examples. */
    public function exportJson(string $mode = 'all', string $example = 'model'): string
    {
        if (!in_array($mode, ['all', 'anonymous', 'enhanced'], true)) {
            throw new \InvalidArgumentException('Choose all, anonymous, or enhanced for the example mode.');
        }
        $bundle = $this->generate($example);
        if ($mode !== 'all') {
            $bundle['examples'] = [$mode => $bundle['examples'][$mode]];
        }

        return json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function ecommerceModel(): array
    {
        $properties = [];
        foreach (['currency' => 'string', 'total_minor' => 'integer', 'tax_minor' => 'integer', 'shipping_minor' => 'integer', 'item_count' => 'integer', 'discount_rate' => 'double', 'product_category' => 'string', 'checkout_step' => 'string'] as $key => $type) {
            $properties[$key] = ['type' => $type, 'consent_required' => true];
            if (in_array($type, ['integer', 'double'], true)) {
                $properties[$key]['numeric_column'] = $key.'_number';
            } else {
                $properties[$key]['column'] = $key;
            }
        }

        return [CustomDataSettings::PROPERTIES_KEY => $properties, CustomDataSettings::MAPPINGS_KEY => []];
    }
}
