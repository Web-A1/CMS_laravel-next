<?php

namespace Tests\Feature;

use App\Models\AdminSupportTicket;
use App\Models\SupportMessage;
use App\Services\Support\SupportImap;
use App\Services\Support\SupportSmtp;
use App\Services\Support\SupportSmtpFailure;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportMailAdaptersTest extends TestCase
{
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if (pcntl_waitpid($server['pid'], $status, WNOHANG) === 0) {
                posix_kill($server['pid'], SIGTERM);
                pcntl_waitpid($server['pid'], $status);
            }
            if (is_resource($server['control'])) {
                fclose($server['control']);
            }
        }
        parent::tearDown();
    }

    private function server(callable $handler): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            try {
                $connection = stream_socket_accept($socket, 10);
                if (! $connection) {
                    throw new \RuntimeException('Fixture connection timeout');
                }
                stream_set_timeout($connection, 10);
                $commands = $handler($connection);
                fclose($connection);
                fwrite($child, json_encode(['commands' => $commands])."\n");
            } catch (\Throwable $e) {
                fwrite($child, json_encode(['error' => $e::class])."\n");
            }
            fclose($socket);
            fclose($child);
            pcntl_exec('/usr/bin/true');
            exit(0);
        }
        $this->assertGreaterThan(0, $pid);
        fclose($child);
        fclose($socket);
        stream_set_timeout($parent, 15);
        $server = ['pid' => $pid, 'port' => $port, 'control' => $parent];
        $this->servers[] = $server;

        return $server;
    }

    private function serverResult(array $server): array
    {
        $line = fgets($server['control']);
        $this->assertNotFalse($line);
        pcntl_waitpid($server['pid'], $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $result);

        return $result['commands'];
    }

    public function test_actual_webklex_protocol_reads_uid_metadata_original_and_never_mutates_flags(): void
    {
        $raw = "From: guest@example.test\r\nSubject: Protocol fixture\r\n\r\nBody";
        $server = $this->server(function ($connection) use ($raw): array {
            $headers = strstr($raw, "\r\n\r\n", true)."\r\n\r\n";
            fwrite($connection, "* OK Fixture ready\r\n");
            $commands = [];
            while ($line = fgets($connection)) {
                [$tag, $command] = explode(' ', trim($line), 2);
                if (str_starts_with($command, 'LOGIN')) {
                    $commands[] = 'LOGIN';
                    fwrite($connection, "$tag OK authenticated\r\n");
                } elseif ($command === 'NOOP') {
                    $commands[] = 'NOOP';
                    fwrite($connection, "$tag OK noop\r\n");
                } elseif (str_starts_with($command, 'LIST')) {
                    $commands[] = 'LIST';
                    fwrite($connection, "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n$tag OK list\r\n");
                } elseif (str_starts_with($command, 'EXAMINE')) {
                    $commands[] = 'EXAMINE';
                    fwrite($connection, "* 1 EXISTS\r\n* OK [UIDVALIDITY 99] valid\r\n* OK [UIDNEXT 8] next\r\n$tag OK [READ-ONLY] examined\r\n");
                } elseif (str_contains($command, 'RFC822.SIZE')) {
                    $commands[] = $command;
                    fwrite($connection, '* 1 FETCH (UID 7 RFC822.SIZE '.strlen($raw).' INTERNALDATE "28-Oct-2026 12:00:00 +0000")'."\r\n$tag OK fetched\r\n");
                } elseif (str_contains($command, 'BODY.PEEK[HEADER]')) {
                    $commands[] = $command;
                    fwrite($connection, '* 1 FETCH (UID 7 BODY[HEADER] {'.strlen($headers)."}\r\n".$headers.")\r\n$tag OK fetched\r\n");
                } elseif (str_contains($command, 'BODY.PEEK[]')) {
                    $commands[] = $command;
                    fwrite($connection, '* 1 FETCH (UID 7 BODY[] {'.strlen($raw)."}\r\n".$raw.")\r\n$tag OK fetched\r\n");
                } elseif ($command === 'LOGOUT') {
                    $commands[] = 'LOGOUT';
                    fwrite($connection, "* BYE leaving\r\n$tag OK logout\r\n");
                    break;
                } else {
                    $commands[] = $command;
                    fwrite($connection, "$tag BAD unexpected\r\n");
                }
            }

            return $commands;
        });
        config(['support.imap.host' => '127.0.0.1', 'support.imap.port' => $server['port'], 'support.imap.encryption' => false, 'support.imap.username' => 'fixture', 'support.imap.password' => 'synthetic-fixture']);
        $imap = new SupportImap;
        try {
            $this->assertSame(['exists' => 1, 'uidvalidity' => 99, 'uidnext' => 8], $imap->connect());
            $metadata = $imap->metadata(7, 7);
            $this->assertSame(strlen($raw), $metadata[7]['bytes']);
            $this->assertSame('28-Oct-2026 12:00:00 +0000', $metadata[7]['received_at']);
            $this->assertSame(strstr($raw, "\r\n\r\n", true)."\r\n\r\n", $imap->headers(7));
            $this->assertSame($raw, $imap->raw(7));
        } finally {
            $imap->disconnect();
        }
        $commands = $this->serverResult($server);
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('BODY.PEEK[HEADER]', $joined);
        $this->assertStringContainsString('BODY.PEEK[]', $joined);
        $this->assertDoesNotMatchRegularExpression('/\b(?:SELECT|STORE|EXPUNGE|DELETE|MOVE|COPY)\b/i', $joined);
    }

    private function outgoing(): SupportMessage
    {
        $ticket = new AdminSupportTicket(['contact_email' => 'fixture@example.invalid', 'public_number' => 'SUP-FIXTURE', 'subject' => 'Fixture']);
        $message = new SupportMessage(['body' => 'Local synthetic fixture', 'message_id' => 'fixture@example.invalid', 'headers' => []]);
        $message->setRelation('ticket', $ticket);

        return $message;
    }

    public function test_real_smtp_definite_negative_is_retryable_and_disconnect_after_data_is_unknown(): void
    {
        foreach (['reject', 'permanent', 'unknown'] as $mode) {
            $server = $this->server(function ($connection) use ($mode): array {
                fwrite($connection, "220 fixture ESMTP\r\n");
                $commands = [];
                $data = false;
                while ($line = fgets($connection)) {
                    $line = trim($line);
                    if ($data) {
                        if ($line === '.') {
                            $commands[] = 'DATA_COMPLETE';
                            // Close after accepting the bytes, before sending SMTP 250.
                            break;
                        }

                        continue;
                    }
                    $verb = strtoupper(explode(' ', $line)[0]);
                    $commands[] = $verb;
                    if ($verb === 'EHLO' || $verb === 'HELO') {
                        fwrite($connection, "250 fixture\r\n");
                    } elseif ($verb === 'MAIL') {
                        fwrite($connection, "250 sender ok\r\n");
                    } elseif ($verb === 'RCPT') {
                        fwrite($connection, match ($mode) {
                            'reject' => "450 temporary refusal\r\n", 'permanent' => "550 permanent refusal\r\n", default => "250 recipient ok\r\n"
                        });
                    } elseif ($verb === 'DATA') {
                        fwrite($connection, "354 send data\r\n");
                        $data = true;
                    } elseif ($verb === 'QUIT') {
                        fwrite($connection, "221 bye\r\n");
                        break;
                    } else {
                        fwrite($connection, "250 ok\r\n");
                    }
                }

                return $commands;
            });
            config(['mail.mailers.support.host' => '127.0.0.1', 'mail.mailers.support.port' => $server['port'], 'mail.mailers.support.scheme' => 'smtp', 'mail.mailers.support.username' => null, 'mail.mailers.support.password' => null]);
            Mail::purge('support');
            try {
                app(SupportSmtp::class)->send($this->outgoing());
                $this->fail('Fixture SMTP must fail.');
            } catch (SupportSmtpFailure $failure) {
                $this->assertSame($mode === 'reject', $failure->retryable);
                $this->assertSame($mode === 'unknown', $failure->unknown);
            } finally {
                Mail::purge('support');
            }
            $commands = $this->serverResult($server);
            $this->assertContains('RCPT', $commands);
            if ($mode === 'unknown') {
                $this->assertContains('DATA_COMPLETE', $commands);
            }
        }
    }
}
