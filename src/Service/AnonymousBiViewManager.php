<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

class AnonymousBiViewManager
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function regenerate(int $minimumCellCount, int $geoMinimumCellCount): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $sql = new AnonymousBiViewSqlBuilder($platform);
        $views = [
            ['bi_anonymous_events_v1', $sql->eventViewSql($minimumCellCount)],
            ['bi_anonymous_goals_v1', $sql->goalViewSql($minimumCellCount)],
            ['bi_anonymous_geo_events_v1', $sql->geoViewSql($geoMinimumCellCount)],
        ];

        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ($views as [$name, $createSql]) {
                $this->replaceView($sql, $name, $createSql);
            }

            return;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($views as [$name, $createSql]) {
                $this->replaceView($sql, $name, $createSql);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function replaceView(AnonymousBiViewSqlBuilder $sql, string $name, string $createSql): void
    {
        $this->connection->executeStatement($sql->dropViewIfExistsSql($name));
        $this->connection->executeStatement($createSql);
    }
}
