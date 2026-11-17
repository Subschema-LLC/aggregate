<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * The addresses an email destination sends to: one to fifty valid addresses,
 * duplicates removed without regard to case, in the order given.
 */
final class Recipients
{
    public const MAX_ADDRESS = 254;
    public const MAX_RECIPIENTS = 50;

    /** @return list<string> */
    public static function check(mixed $rawRecipients): array
    {
        if (is_string($rawRecipients)) {
            $rawRecipients = preg_split('/[\r\n,;]+/', $rawRecipients) ?: [];
        }
        if (!is_array($rawRecipients) || !array_is_list($rawRecipients)) {
            throw new \InvalidArgumentException(
                'Email destination recipients must be a list of email addresses.',
            );
        }
        $recipients = [];
        foreach ($rawRecipients as $address) {
            if (!is_string($address)) {
                throw new \InvalidArgumentException('Each recipient must be an email address.');
            }
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (strlen($address) > self::MAX_ADDRESS
                || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException(
                    'Each recipient must be a valid email address, such as ops@example.com.',
                );
            }
            if (!in_array(strtolower($address), array_map('strtolower', $recipients), true)) {
                $recipients[] = $address;
            }
        }
        if ($recipients === []) {
            throw new \InvalidArgumentException(
                'Enter at least one recipient email address for an email destination.',
            );
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException(sprintf(
                'An email destination can list at most %d recipients.',
                self::MAX_RECIPIENTS,
            ));
        }

        return $recipients;
    }
}
