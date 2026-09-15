#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-only
use App\Service\UpdateSettings;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    // Preserve mapping/sequence identity: {} can use defaults, [] is not config.
    $config = Yaml::parseFile(dirname(__DIR__).'/config/release.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
    if (!$config instanceof stdClass || array_diff(array_keys(get_object_vars($config)), ['branch']) !== []) {
        throw new RuntimeException('Release configuration must be a YAML mapping containing only the optional branch key.');
    }
    $values = get_object_vars($config);
    $branch = UpdateSettings::validateBranch(array_key_exists('branch', $values) ? $values['branch'] : UpdateSettings::DEFAULT_BRANCH);
    $process = new Process(['git', 'check-ref-format', '--branch', $branch]);
    $process->mustRun();
    if (trim($process->getOutput()) !== $branch || str_starts_with($branch, '-')) {
        throw new RuntimeException('Publishing branch must be a literal branch name.');
    }
    echo $branch."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Cannot read publishing branch: ".$exception->getMessage()."\n");
    exit(1);
}
