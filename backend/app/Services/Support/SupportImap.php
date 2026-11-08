<?php

namespace App\Services\Support;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

class SupportImap
{
    private ?Client $client = null;

    public function connect(): array
    {
        $config = config('support.imap');
        if (! $config['host'] || ! $config['username'] || ! $config['password']) {
            throw new \RuntimeException('NOT_CONFIGURED');
        }
        $this->client = (new ClientManager(['options' => ['debug' => false, 'rfc822' => false, 'fetch' => IMAP::FT_PEEK]]))->make([
            'host' => $config['host'], 'port' => $config['port'], 'encryption' => $config['encryption'],
            'username' => $config['username'], 'password' => $config['password'], 'protocol' => 'imap', 'validate_cert' => true, 'timeout' => 30,
        ]);
        $this->client->connect();

        // EXAMINE opens the mailbox read-only. Direct protocol reads never SELECT or STORE flags.
        return $this->client->getFolderByPath($config['folder'])->examine();
    }

    public function metadata(int $from, int $to): array
    {
        $response = $this->client->getConnection()->fetch(['UID', 'RFC822.SIZE', 'INTERNALDATE'], $from, $to, IMAP::ST_UID);
        $data = $response->validatedData();
        $dates = [];
        // Webklex 6.2 tokenizes a quoted INTERNALDATE immediately before ')' incorrectly.
        // Read only this bounded metadata field from the library's response, never sender Date.
        foreach ($response->getResponse() as $line) {
            if (preg_match('/\bUID (\d+)\b/', $line, $uidMatch) && preg_match('/\bINTERNALDATE "([^"]{1,80})"/', $line, $dateMatch)) {
                $dates[(int) $uidMatch[1]] = $dateMatch[1];
            }
        }
        $result = [];
        foreach ($data as $uid => $row) {
            $key = (int) ($row['UID'] ?? $uid);
            $result[$key] = ['bytes' => (int) ($row['RFC822.SIZE'] ?? 0), 'received_at' => $dates[$key] ?? null];
        }

        return $result;
    }

    public function raw(int $uid): string
    {
        // Fetch the original without marking Seen; MIME parsing happens only after private persistence.
        $data = $this->client->getConnection()->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
        if (! isset($data[$uid]['BODY[]']) || ! is_string($data[$uid]['BODY[]'])) {
            throw new \RuntimeException('ORIGINAL_UNAVAILABLE');
        }

        return $data[$uid]['BODY[]'];
    }

    public function headers(int $uid): string
    {
        // Fetch only headers before deciding whether an email belongs to an existing web ticket.
        $data = $this->client->getConnection()->fetch(['UID', 'BODY.PEEK[HEADER]'], [$uid], null, IMAP::ST_UID)->validatedData();
        $headers = $data[$uid]['BODY[HEADER]'] ?? null;
        if (! is_string($headers) || $headers === '') {
            throw new \RuntimeException('HEADER_UNAVAILABLE');
        }
        if (strlen($headers) > 65536) {
            throw new \RuntimeException('HEADER_TOO_LARGE');
        }

        return $headers;
    }

    public function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }
}
