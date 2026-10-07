<?php

declare(strict_types=1);

// A one-connection loopback SMTP sink, used only by MailSmtpTest.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, 'Cannot start the test SMTP sink.');
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 10);
if ($client === false) {
    fclose($server);
    exit(2);
}
stream_set_timeout($client, 5);
fwrite($client, "220 localhost test sink\r\n");
$commands = [];
$body = '';
$data = false;
while (($line = fgets($client)) !== false) {
    if ($data) {
        if ($line === ".\r\n") {
            $data = false;
            fwrite($client, "250 Ok queued as test-1\r\n");
        } else {
            $body .= $line;
        }
        continue;
    }
    $command = rtrim($line, "\r\n");
    $commands[] = $command;
    if (str_starts_with($command, 'EHLO')) {
        fwrite($client, "250-localhost\r\n250 8BITMIME\r\n");
    } elseif ($command === 'DATA') {
        fwrite($client, "354 Send message\r\n");
        $data = true;
    } elseif ($command === 'QUIT') {
        fwrite($client, "221 Bye\r\n");
        break;
    } else {
        fwrite($client, "250 Ok\r\n");
    }
}
fclose($client);
fclose($server);
fwrite(STDOUT, base64_encode(json_encode(['commands' => $commands, 'body' => $body], JSON_THROW_ON_ERROR)) . "\n");
