<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class AnonymousBiViewManager
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function regenerate(int $minimumCellCount, int $geoMinimumCellCount): void
    {
        $sql = new AnonymousBiViewSqlBuilder($this->connection->getDatabasePlatform());
        $this->connection->executeStatement($sql->dropViewIfExistsSql('bi_anonymous_events_v1'));
        $this->connection->executeStatement($sql->dropViewIfExistsSql('bi_anonymous_goals_v1'));
        $this->connection->executeStatement($sql->dropViewIfExistsSql('bi_anonymous_geo_events_v1'));

        $this->connection->executeStatement($sql->eventViewSql($minimumCellCount));
        $this->connection->executeStatement($sql->goalViewSql($minimumCellCount));
        $this->connection->executeStatement($sql->geoViewSql($geoMinimumCellCount));
    }
}
