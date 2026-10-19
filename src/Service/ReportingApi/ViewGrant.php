<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

/**
 * A credential that may read reporting views: a reporting API token or an AI
 * assistant connection. Both follow ViewScope.
 */
interface ViewGrant
{
    /** May this credential read the view, given whether the deployment allows private views? */
    public function mayRead(string $view, bool $privateViewsAllowed): bool;

    /**
     * The views this credential may read: approved views in catalog order, then private ones.
     *
     * @return list<string>
     */
    public function readableViews(bool $privateViewsAllowed): array;
}
