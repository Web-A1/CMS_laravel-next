<?php

namespace App\Services\Kinescope;

use App\Data\Kinescope\KinescopeVideoInspection;
use App\Exceptions\KinescopeApiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class KinescopeApiClient
{
    public function inspectReady(string $embedLocator): KinescopeVideoInspection
    {
        $inspection = $this->resolveIdentity($embedLocator);
        if ($inspection->status !== 'done') {
            throw $this->unavailable();
        }

        return $inspection;
    }

    public function resolveIdentity(string $embedLocator): KinescopeVideoInspection
    {
        [$baseUrl, $apiToken, $projectId] = $this->configuration();

        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/', $embedLocator) !== 1) {
            throw $this->unavailable();
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->timeout(10)
                ->get($baseUrl.'/v1/videos/'.rawurlencode($embedLocator));
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        if (! $response->successful()) {
            throw $this->unavailable();
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw $this->unavailable();
        }

        $responseVideoId = $data['id'] ?? null;
        $responseProjectId = $data['project_id'] ?? null;
        $status = $data['status'] ?? null;
        $embedLink = $data['embed_link'] ?? null;

        if (
            ! is_string($responseVideoId)
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $responseVideoId) !== 1
            || ! is_string($responseProjectId)
            || ! hash_equals($projectId, $responseProjectId)
            || ! is_string($status)
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $status) !== 1
            || ! is_string($embedLink)
        ) {
            throw $this->unavailable();
        }

        $embedParts = parse_url($embedLink);
        if (
            ! is_array($embedParts)
            || ($embedParts['scheme'] ?? null) !== 'https'
            || ($embedParts['host'] ?? null) !== 'kinescope.io'
            || preg_match('#^/embed/([A-Za-z0-9_-]{1,255})$#', (string) ($embedParts['path'] ?? ''), $embedMatches) !== 1
            || ! hash_equals($embedLocator, $embedMatches[1])
            || isset($embedParts['user'])
            || isset($embedParts['pass'])
            || isset($embedParts['query'])
            || isset($embedParts['fragment'])
        ) {
            throw $this->unavailable();
        }

        return new KinescopeVideoInspection(
            videoId: $responseVideoId,
            embedLocator: $embedLocator,
            projectId: $responseProjectId,
            status: $status,
            embedHost: $embedParts['host'],
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function configuration(): array
    {
        $baseUrl = config('services.kinescope.api_base_url');
        $apiToken = config('services.kinescope.api_token');
        $projectId = config('services.kinescope.project_id');

        if (
            $baseUrl !== 'https://api.kinescope.io'
            || ! is_string($apiToken)
            || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $apiToken) !== 1
            || ! is_string($projectId)
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $projectId) !== 1
        ) {
            throw $this->unavailable();
        }

        return [$baseUrl, $apiToken, $projectId];
    }

    private function unavailable(): KinescopeApiUnavailableException
    {
        return new KinescopeApiUnavailableException('Kinescope video could not be verified.');
    }
}
