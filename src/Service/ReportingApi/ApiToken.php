<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\ViewSync\ViewCatalog;

/**
 * One reporting API token as ApiTokenStore keeps it: its public ID, name,
 * scope and dates, and the SHA-256 hash of the secret token. The token itself
 * is never stored.
 *
 * Scope: $views null means every approved view, including approved views a
 * later release adds; a list means only those approved views. Private views
 * are granted only through $privateViews, one by one, and only while the
 * deployment allows private views (ReportingApiSettings).
 */
final readonly class ApiToken
{
    /**
     * @param list<string>|null $views        approved views, in catalog order, or null for every approved view
     * @param list<string>      $privateViews private views, in catalog order
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $hash,
        public ?array $views,
        public array $privateViews,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /** May this token read the view, given whether the deployment allows private views? */
    public function mayRead(string $view, bool $privateViewsAllowed): bool
    {
        if (ViewCatalog::isApproved($view)) {
            return $this->views === null || in_array($view, $this->views, true);
        }
        if (ViewCatalog::isPrivate($view)) {
            return $privateViewsAllowed && in_array($view, $this->privateViews, true);
        }

        return false;
    }

    /**
     * The views this token may read: approved views in catalog order, then private ones.
     *
     * @return list<string>
     */
    public function readableViews(bool $privateViewsAllowed): array
    {
        return array_values(array_filter(
            [...array_keys(ViewCatalog::APPROVED), ...array_keys(ViewCatalog::PRIVATE)],
            fn (string $view): bool => $this->mayRead($view, $privateViewsAllowed),
        ));
    }
}
