<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

/**
 * Everything that tells one view sync from the other: its task type, command
 * and log file, and the words its settings, runner, scheduler job and admin
 * page use in messages. Each sync's settings class defines it once
 * (ViewSyncSettings::definition()); the other classes read it from there.
 */
final readonly class ViewSyncDefinition
{
    /**
     * @param string $taskType     the processing task type of each view sync
     * @param string $label        the scheduler's name for the job, as in "BigQuery sync"
     * @param string $subject      begins "… is turned off." and "… failed (…)"
     * @param string $settingsName as in "the BigQuery settings" and "Failed to save BigQuery views."
     * @param string $destination  as in "may be copied to BigQuery"
     * @param string $selectedFor  as in "… is not selected for BigQuery sync."
     * @param string $copiedTo     as in "… is not a reporting view that can be synced to BigQuery."
     * @param string $noneChosen   the message when the sync is on without views
     * @param string $viewsSaved   the admin page's message after saving the views
     * @param string $command      the console command that runs the sync
     * @param string $logFile      where a sync started from the admin page writes its output
     */
    public function __construct(
        public string $taskType,
        public string $label,
        public string $subject,
        public string $settingsName,
        public string $destination,
        public string $selectedFor,
        public string $copiedTo,
        public string $noneChosen,
        public string $viewsSaved,
        public string $command,
        public string $logFile,
    ) {
    }
}
