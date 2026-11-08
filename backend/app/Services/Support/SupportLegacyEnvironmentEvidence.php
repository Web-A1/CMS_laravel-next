<?php

namespace App\Services\Support;

use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMediaReservation;
use RuntimeException;

class SupportLegacyEnvironmentEvidence
{
    public function __construct(protected readonly SupportMediaStorage $storage) {}

    public function importFile(SupportMediaDeletion $deletion, string $path, bool $localOperator = false): void
    {
        if (! app()->runningInConsole() || ($localOperator && ! app()->environment('local'))) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SOURCE_UNTRUSTED');
        }
        $canonical = realpath($path);
        if (! $canonical || is_link($path) || str_starts_with($canonical, storage_path().'/') || str_starts_with($canonical, public_path().'/')) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SOURCE_UNTRUSTED');
        }
        $stream = fopen($canonical, 'rb');
        if (! $stream) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SOURCE_UNAVAILABLE');
        }
        try {
            $stat = fstat($stream);
            $expectedOwner = $localOperator ? posix_geteuid() : 0;
            if (($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== $expectedOwner || ($stat['mode'] & 0022) !== 0 || $stat['size'] > 262144) {
                throw new RuntimeException('MEDIA_ENVIRONMENT_SOURCE_UNTRUSTED');
            }
            $bytes = stream_get_contents($stream, 262145);
            $receipt = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($stream);
        }
        $source = ['channel' => $localOperator ? 'local_operator_file' : 'root_owned_file', 'path' => $canonical, 'owner_uid' => $stat['uid'], 'mode' => $stat['mode'] & 0777, 'digest' => hash('sha256', $bytes), 'receipt_digest' => hash('sha256', json_encode($receipt)), 'imported_at' => now()->toIso8601String()];
        $this->verify($receipt, $source, $deletion);
        $evidence = $deletion->inventory_evidence ?? [];
        $evidence['environment_receipt'] = $receipt;
        $evidence['environment_source'] = $source;
        $evidence['snapshot_digest'] = hash('sha256', json_encode($deletion->snapshot));
        $deletion->update(['inventory_evidence' => $evidence]);
    }

    public function evidenceFor(SupportMediaDeletion $deletion): array
    {
        [$receipt, $source] = $this->storedSource($deletion);
        $this->verify($receipt, $source, $deletion);

        return ['receipt' => $receipt, 'source' => $source, 'snapshot_digest' => hash('sha256', json_encode($deletion->snapshot)), 'deletion_id' => $deletion->id];
    }

    protected function storedSource(SupportMediaDeletion $deletion): array
    {
        $evidence = $deletion->inventory_evidence ?? [];
        if (! isset($evidence['environment_receipt'], $evidence['environment_source'])) {
            throw new RuntimeException('MEDIA_LEGACY_ENVIRONMENT_EVIDENCE_NOT_CONFIGURED');
        }
        if (($evidence['snapshot_digest'] ?? null) !== hash('sha256', json_encode($deletion->snapshot))) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SNAPSHOT_MISMATCH');
        }

        return [$evidence['environment_receipt'], $evidence['environment_source']];
    }

    private function verify(array $receipt, array $source, SupportMediaDeletion $deletion): void
    {
        if (! in_array($source['channel'] ?? null, ['root_owned_file', 'local_operator_file'], true)
            || ($source['channel'] === 'root_owned_file' && ($source['owner_uid'] ?? null) !== 0)
            || ($source['channel'] === 'local_operator_file' && ! app()->environment('local'))
            || ! is_int($source['mode'] ?? null) || ($source['mode'] & 0022) !== 0
            || ($source['receipt_digest'] ?? null) !== hash('sha256', json_encode($receipt))) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SOURCE_UNTRUSTED');
        }
        if (($receipt['schema_version'] ?? null) !== 1 || ($receipt['environment']['writer_contract'] ?? null) !== 1) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_CONTRACT_INVALID');
        }
        foreach (['receipt_id', 'issuer', 'issued_at', 'source_kind', 'source_reference'] as $field) {
            if (! is_string($receipt['provenance'][$field] ?? null) || trim($receipt['provenance'][$field]) === '') {
                throw new RuntimeException('MEDIA_ENVIRONMENT_PROVENANCE_INVALID');
            }
        }
        if (! in_array($receipt['provenance']['source_kind'], ['host_capture', 'deployment_owner_attestation'], true)) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_PROVENANCE_INVALID');
        }
        foreach (['application_instance', 'host_id', 'app_root', 'retirement_release_reference'] as $field) {
            if (! is_string($receipt['environment'][$field] ?? null) || $receipt['environment'][$field] === '') {
                throw new RuntimeException('MEDIA_ENVIRONMENT_INSTANCE_INVALID');
            }
        }
        if ($receipt['environment']['host_id'] !== gethostname()
            || $receipt['environment']['app_root'] !== realpath(base_path())
            || $receipt['environment']['application_instance'] !== config('support.instance_id', hash('sha256', realpath(base_path())))) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_INSTANCE_MISMATCH');
        }
        foreach (['issuer', 'application_instance', 'writer_entrypoints', 'physical_domains', 'basis', 'transition_reference'] as $field) {
            if (empty($receipt['attested_scope'][$field])) {
                throw new RuntimeException('MEDIA_ENVIRONMENT_SCOPE_INCOMPLETE');
            }
        }
        if ($receipt['attested_scope']['application_instance'] !== $receipt['environment']['application_instance']
            || ($receipt['writer_retirement']['unresolved_producers'] ?? null) !== []
            || ($receipt['writer_retirement']['uncertain_external_io'] ?? null) !== []) {
            throw new RuntimeException('MEDIA_LEGACY_WRITERS_UNCONFIRMED');
        }
        $producers = $receipt['writer_retirement']['producers'] ?? null;
        if (! is_array($producers) || $producers === []) {
            throw new RuntimeException('MEDIA_LEGACY_WRITERS_UNCONFIRMED');
        }
        foreach ($producers as $producer) {
            if (empty($producer['role']) || empty($producer['identity']) || empty($producer['observation_source']) || ($producer['result'] ?? null) !== 'drained') {
                throw new RuntimeException('MEDIA_LEGACY_WRITERS_UNCONFIRMED');
            }
        }
        $entrypoints = $receipt['attested_scope']['writer_entrypoints'];
        if (! is_array($entrypoints) || array_diff(['http', 'queue', 'maintenance', 'processor'], $entrypoints) !== []
            || array_diff($entrypoints, array_column($producers, 'role')) !== []) {
            throw new RuntimeException('MEDIA_LEGACY_WRITERS_UNCONFIRMED');
        }
        $domains = $receipt['domains'] ?? [];
        if (! is_array($domains) || $domains === [] || count($domains) > 30) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_DOMAINS_INVALID');
        }
        $ids = array_column($domains, 'id');
        if (count(array_unique($ids)) !== count($domains) || ! is_array($receipt['attested_scope']['physical_domains'])
            || array_diff($ids, $receipt['attested_scope']['physical_domains']) !== []) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_SCOPE_INCOMPLETE');
        }
        $known = $this->knownDomains($deletion);
        foreach ($domains as $domain) {
            if (empty($domain['id']) || ! in_array($domain['purpose'] ?? null, ['legacy_store', 'image_work', 'mail_work', 'upload_tmp', 'processor_tmp'], true)
                || ! in_array($domain['basis']['kind'] ?? null, ['current_runtime', 'deployment_record', 'owner_attestation'], true) || empty($domain['basis']['reference'])) {
                throw new RuntimeException('MEDIA_ENVIRONMENT_DOMAIN_INVALID');
            }
            $identity = $domain['identity'];
            if ($domain['purpose'] === 'legacy_store') {
                $this->storage->verifyIdentity($identity);
            } elseif (($identity['driver'] ?? null) !== 'local' || ($identity['host'] ?? null) !== gethostname()
                || ! is_string($identity['root'] ?? null) || $identity['root'] === '/' || realpath($identity['root']) !== $identity['root'] || ! is_dir($identity['root']) || is_link($identity['root'])) {
                throw new RuntimeException('MEDIA_ENVIRONMENT_ROOT_UNAVAILABLE');
            }
            foreach ($known as $index => $expected) {
                if ($expected['purpose'] === $domain['purpose'] && $this->storage->sameIdentity($expected['identity'], $identity)) {
                    unset($known[$index]);
                }
            }
        }
        if ($known !== []) {
            throw new RuntimeException('MEDIA_ENVIRONMENT_KNOWN_DOMAIN_MISSING');
        }
    }

    protected function knownDomains(SupportMediaDeletion $deletion): array
    {
        $temporary = realpath(sys_get_temp_dir());
        $upload = realpath(ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
        $processor = realpath(getenv('MAGICK_TEMPORARY_PATH') ?: (getenv('TMPDIR') ?: sys_get_temp_dir()));
        $local = fn ($root) => ['driver' => 'local', 'host' => gethostname(), 'root' => $root];

        $snapshot = $deletion->snapshot;
        $disks = array_unique([
            config('support.disk'),
            ...config('support.media_disks', []),
            ...SupportAttachment::whereIn('id', $snapshot['attachments'])->pluck('disk')->all(),
            ...SupportMailReceipt::whereIn('id', $snapshot['receipts'])->pluck('disk')->all(),
        ]);
        $identities = array_map(fn ($disk) => $this->storage->identity($disk), $disks);
        foreach (SupportMediaReservation::whereIn('id', $snapshot['reservations'])->get() as $reservation) {
            $this->storage->verifyIdentity($reservation->storage_identity);
            $identities[] = $reservation->storage_identity;
        }
        $stores = [];
        foreach ($identities as $identity) {
            $this->storage->verifyIdentity($identity);
            if (! array_any($stores, fn ($domain) => $this->storage->sameIdentity($domain['identity'], $identity))) {
                $stores[] = ['purpose' => 'legacy_store', 'identity' => $identity];
            }
        }

        return [
            ...$stores,
            ['purpose' => 'image_work', 'identity' => $local($temporary)],
            ['purpose' => 'mail_work', 'identity' => $local($temporary)],
            ['purpose' => 'upload_tmp', 'identity' => $local($upload)],
            ['purpose' => 'processor_tmp', 'identity' => $local($processor)],
        ];
    }
}
