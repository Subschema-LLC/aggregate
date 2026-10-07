<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

/** A parsed Google service account key. The private key never leaves this object except to sign. */
final class ServiceAccountKey
{
    public const MAX_BYTES = 65536;
    private const TOKEN_URIS = [
        'https://oauth2.googleapis.com/token',
        'https://www.googleapis.com/oauth2/v4/token',
        'https://accounts.google.com/o/oauth2/token',
    ];

    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKeyId,
        public readonly string $projectId,
        public readonly string $tokenUri,
        #[\SensitiveParameter] private readonly string $privateKey,
    ) {
    }

    /** @throws \InvalidArgumentException with a message safe to show an administrator */
    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The key file must be a Google service account JSON key of at most 64 KB.');
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The key file is not valid JSON. Download the key again from the Google Cloud console (IAM → Service accounts → Keys → Add key → JSON).');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('The key file must contain one JSON object.');
        }
        $type = $data['type'] ?? null;
        if ($type === 'external_account' || $type === 'authorized_user' || $type === 'impersonated_service_account') {
            throw new \InvalidArgumentException('This is a '.$type.' credential file, not a service account key. Use a service account JSON key, “Automatic on Google Cloud” or “Sign in with Google”.');
        }
        if ($type !== 'service_account') {
            throw new \InvalidArgumentException('The key file is not a Google service account key (its "type" must be "service_account").');
        }
        $email = $data['client_email'] ?? null;
        if (!is_string($email) || strlen($email) > 254 || preg_match('/^[^@\s]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D', $email) !== 1) {
            throw new \InvalidArgumentException('The key file has no valid client_email.');
        }
        $privateKey = $data['private_key'] ?? null;
        if (!is_string($privateKey) || !str_contains($privateKey, 'PRIVATE KEY')) {
            throw new \InvalidArgumentException('The key file has no private_key.');
        }
        if (!function_exists('openssl_pkey_get_private')) {
            throw new \InvalidArgumentException('PHP’s OpenSSL extension is required to sign in with a service account key.');
        }
        $resource = @openssl_pkey_get_private($privateKey);
        if ($resource === false || (openssl_pkey_get_details($resource)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new \InvalidArgumentException('The key file’s private_key is not a readable RSA key.');
        }
        $keyId = $data['private_key_id'] ?? '';
        if (!is_string($keyId) || preg_match('/^[A-Za-z0-9]{0,128}$/D', $keyId) !== 1) {
            throw new \InvalidArgumentException('The key file has an invalid private_key_id.');
        }
        $project = $data['project_id'] ?? '';
        if (!is_string($project) || ($project !== '' && preg_match('/^(?:[a-z0-9.-]{1,63}:)?[a-z][a-z0-9-]{4,28}[a-z0-9]$/D', $project) !== 1)) {
            $project = '';
        }
        $tokenUri = $data['token_uri'] ?? self::TOKEN_URIS[0];
        if (!is_string($tokenUri) || !in_array($tokenUri, self::TOKEN_URIS, true)) {
            throw new \InvalidArgumentException('The key file’s token_uri is not a Google token endpoint.');
        }

        return new self($email, $keyId, $project, $tokenUri, $privateKey);
    }

    /** A signed JWT assertion that exchanges for an access token (RFC 7523). */
    public function assertion(string $scope, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($this->privateKeyId !== '') {
            $header['kid'] = $this->privateKeyId;
        }
        $claims = [
            'iss' => $this->clientEmail,
            'scope' => $scope,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $input = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            .'.'.self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($input, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The service account key could not sign a token request.');
        }

        return $input.'.'.self::base64Url($signature);
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function __debugInfo(): array
    {
        return ['clientEmail' => $this->clientEmail, 'projectId' => $this->projectId];
    }
}
