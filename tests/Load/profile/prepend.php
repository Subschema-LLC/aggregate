<?php

/*
 * Load-test profiling only: prepended to every PHP-FPM request by
 * tests/Load/server/configure-php.sh --profile. Records an xhprof call graph
 * (wall time and call counts per caller/callee pair) for each POST to
 * /api/receive and writes it as JSON to $LOAD_TEST_PROFILE_DIR. The profile
 * holds function names and timings only, never request data.
 */

if (
    function_exists('xhprof_enable')
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/receive')
    && is_string($directory = getenv('LOAD_TEST_PROFILE_DIR'))
    && $directory !== ''
) {
    xhprof_enable();
    register_shutdown_function(static function () use ($directory): void {
        $profile = xhprof_disable();
        @file_put_contents(
            $directory.'/'.str_replace('.', '', uniqid('', true)).'.json',
            json_encode($profile),
        );
    });
}
