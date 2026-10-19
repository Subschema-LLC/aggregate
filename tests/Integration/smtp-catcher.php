<?php

declare(strict_types=1);

/*
 * A minimal SMTP server for the fresh-install test, so the email steps need
 * no mail server: it accepts every message on 127.0.0.1:<port> and writes each
 * one to <directory>/<n>.eml. Plain SMTP, one client at a time, no TLS or
 * authentication. Never use it outside tests.
 *
 *     php tests/Integration/smtp-catcher.php 2525 /tmp/messages
 */

[, $port, $directory] = $argv + [null, null, null];
if (!is_string($port) || !ctype_digit($port) || !is_string($directory) || !is_dir($directory)) {
    fwrite(STDERR, "Usage: php smtp-catcher.php <port> <directory>\n");
    exit(2);
}
$server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on 127.0.0.1:{$port}: {$errorMessage}\n");
    exit(1);
}
echo "listening\n";

$count = 0;
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 60);
    $reply = static function (string $line) use ($client): void {
        fwrite($client, $line."\r\n");
    };
    $reply('220 catcher ESMTP');
    $message = null;
    while (($line = fgets($client)) !== false) {
        if ($message !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents($directory.'/'.(++$count).'.eml', $message);
                $message = null;
                $reply('250 2.0.0 Ok: queued as catcher-'.$count);
            } else {
                $message .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            continue;
        }
        $command = strtoupper(substr(trim($line), 0, 4));
        if ($command === 'EHLO') {
            $reply('250-catcher');
            $reply('250 8BITMIME');
        } elseif ($command === 'DATA') {
            $message = '';
            $reply('354 End data with <CR><LF>.<CR><LF>');
        } elseif ($command === 'QUIT') {
            $reply('221 2.0.0 Bye');
            break;
        } else {
            // HELO, MAIL, RCPT, RSET and NOOP all succeed.
            $reply('250 2.0.0 Ok');
        }
    }
    fclose($client);
}
