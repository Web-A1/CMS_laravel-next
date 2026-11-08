<?php

namespace Tests\Feature;

use App\Models\SupportMediaDeletion;
use App\Models\User;
use App\Services\Support\SupportLegacyEnvironmentEvidence;
use App\Services\Support\SupportMedia;
use App\Services\Support\SupportMediaRemoval;
use App\Services\Support\SupportMediaStorage;
use App\Services\Support\SupportOperations;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\Filesystem;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class SupportMediaStorageTest extends TestCase
{
    use SupportDatabase;

    private array $commands = [];

    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function disk(array $responses): MockHandler
    {
        $handler = new MockHandler(array_map(fn ($step) => function ($command, $request) use ($step) {
            $this->assertSame($step[0], $command->getName());
            $this->commands[] = ['name' => $command->getName(), 'arguments' => $command->toArray()];

            return $step[1];
        }, $responses));
        $config = ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'fixture-bucket', 'root' => 'support', 'endpoint' => 'https://s3.fixture.test', 'throw' => true];
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $config['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => $handler]);
        $adapter = new S3Adapter($client, $config['bucket'], $config['root']);
        config(['filesystems.disks.support-private' => $config]);
        Storage::set('support-private', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $config, $client));

        return $handler;
    }

    private function emptyInventory(): array
    {
        return array_map(fn ($name) => [$name, new Result(['IsTruncated' => false])], ['ListObjectsV2', 'ListObjectVersions', 'ListMultipartUploads']);
    }

    public function test_a_25_mib_object_uses_exactly_one_bounded_put_and_no_multipart(): void
    {
        $handler = $this->disk([['PutObject', new Result(['ETag' => 'fixture'])]]);
        $path = tempnam(sys_get_temp_dir(), 'support-s3-fixture-');
        $this->temporary[] = $path;
        $stream = fopen($path, 'wb');
        ftruncate($stream, SupportMediaStorage::MAX_BYTES);
        fclose($stream);
        $storage = app(SupportMediaStorage::class);
        $owner = '11111111-1111-4111-8111-111111111111';
        $result = $storage->put($storage->identity('support-private'), 'v1/'.$owner.'/original.eml', $path, $owner, 'original.eml');

        $this->assertSame(SupportMediaStorage::MAX_BYTES, $result['bytes']);
        $this->assertCount(1, $this->commands);
        $this->assertSame(0, $this->commands[0]['arguments']['@retries']);
        $this->assertSame(SupportMediaStorage::MAX_BYTES, $this->commands[0]['arguments']['ContentLength']);
        $this->assertSame($owner, $handler->getLastRequest()->getHeaderLine('x-amz-meta-support-reservation'));
        $this->assertSame(0, count($handler));
    }

    public function test_ambiguous_put_is_not_retried_by_the_sdk(): void
    {
        $failure = new AwsException('fixture transport interruption', new Command('PutObject'), ['connection_error' => true]);
        $this->disk([['PutObject', $failure]]);
        $path = tempnam(sys_get_temp_dir(), 'support-s3-fixture-');
        $this->temporary[] = $path;
        file_put_contents($path, 'fixture bytes');
        $storage = app(SupportMediaStorage::class);
        try {
            $storage->put($storage->identity('support-private'), 'v1/11111111-1111-4111-8111-111111111111/voice', $path, '11111111-1111-4111-8111-111111111111', 'voice');
            $this->fail('Expected the transport interruption');
        } catch (AwsException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertCount(1, $this->commands);
    }

    public function test_all_versions_null_version_and_markers_are_deleted_without_touching_neighbor_key(): void
    {
        $key = 'support/images/1/full.webp';
        $neighbor = $key.'-foreign';
        $this->disk([
            ['ListObjectsV2', new Result(['Contents' => [['Key' => $key], ['Key' => $neighbor]], 'IsTruncated' => false])],
            ['ListObjectVersions', new Result(['Versions' => [['Key' => $key, 'VersionId' => 'first']], 'IsTruncated' => true, 'NextKeyMarker' => $key, 'NextVersionIdMarker' => 'first'])],
            ['ListObjectVersions', new Result(['Versions' => [['Key' => $key, 'VersionId' => 'null'], ['Key' => $neighbor, 'VersionId' => 'null']], 'DeleteMarkers' => [['Key' => $key, 'VersionId' => 'marker']], 'IsTruncated' => false])],
            ['ListMultipartUploads', new Result(['IsTruncated' => false])],
            ['DeleteObject', new Result], ['DeleteObject', new Result], ['DeleteObject', new Result],
            ['ListObjectsV2', new Result(['Contents' => [['Key' => $neighbor]], 'IsTruncated' => false])],
            ['ListObjectVersions', new Result(['Versions' => [['Key' => $neighbor, 'VersionId' => 'null']], 'IsTruncated' => false])],
            ['ListMultipartUploads', new Result(['IsTruncated' => false])],
        ]);
        $storage = app(SupportMediaStorage::class);
        $storage->deleteKey($storage->identity('support-private'), 'images/1/full.webp');
        $deletes = array_values(array_filter($this->commands, fn ($command) => $command['name'] === 'DeleteObject'));
        $this->assertSame(['first', 'null', 'marker'], array_map(fn ($command) => $command['arguments']['VersionId'], $deletes));
        $this->assertSame([$key, $key, $key], array_map(fn ($command) => $command['arguments']['Key'], $deletes));
        $this->assertSame('first', $this->commands[2]['arguments']['VersionIdMarker']);
        $this->assertCount(10, $this->commands);
    }

    public function test_owned_historical_multipart_is_aborted_and_parts_and_upload_absence_are_checked(): void
    {
        $key = 'support/sources/fixture';
        $this->disk([
            ['ListObjectsV2', new Result(['IsTruncated' => false])],
            ['ListObjectVersions', new Result(['IsTruncated' => false])],
            ['ListMultipartUploads', new Result(['Uploads' => [['Key' => $key, 'UploadId' => 'fixture-upload']], 'IsTruncated' => false])],
            ['ListParts', new Result(['Parts' => [['PartNumber' => 1, 'Size' => 10]], 'IsTruncated' => true, 'NextPartNumberMarker' => 1])],
            ['ListParts', new Result(['Parts' => [['PartNumber' => 2, 'Size' => 20]], 'IsTruncated' => false])],
            ['AbortMultipartUpload', new Result],
            ['ListParts', new AwsException('fixture upload is gone', new Command('ListParts'), ['code' => 'NoSuchUpload'])],
            ...$this->emptyInventory(),
        ]);
        $storage = app(SupportMediaStorage::class);
        $storage->deleteKey($storage->identity('support-private'), 'sources/fixture');
        $abort = array_values(array_filter($this->commands, fn ($command) => $command['name'] === 'AbortMultipartUpload'));
        $this->assertCount(1, $abort);
        $this->assertSame($key, $abort[0]['arguments']['Key']);
        $this->assertSame('fixture-upload', $abort[0]['arguments']['UploadId']);
        $this->assertSame(1, $this->commands[4]['arguments']['PartNumberMarker']);
        $this->assertCount(10, $this->commands);
    }

    public function test_repeated_version_marker_fails_before_any_delete(): void
    {
        $page = ['Versions' => [['Key' => 'support/sources/lost', 'VersionId' => 'old']], 'IsTruncated' => true, 'NextKeyMarker' => 'support/sources/lost', 'NextVersionIdMarker' => 'old'];
        $this->disk([
            ['ListObjectsV2', new Result(['IsTruncated' => false])],
            ['ListObjectVersions', new Result($page)], ['ListObjectVersions', new Result($page)],
        ]);
        $storage = app(SupportMediaStorage::class);
        try {
            $storage->deleteKey($storage->identity('support-private'), 'sources/lost');
            $this->fail('Expected the incomplete inventory to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('MEDIA_INVENTORY_CURSOR_INVALID', $exception->getMessage());
        }
        $this->assertSame([], array_filter($this->commands, fn ($command) => $command['name'] === 'DeleteObject'));
    }

    public function test_a_lost_source_existing_only_as_an_old_version_blocks_legacy_completion(): void
    {
        Queue::fake();
        $this->disk([
            ['ListObjectsV2', new Result(['IsTruncated' => false])],
            ['ListObjectVersions', new Result(['Versions' => [['Key' => 'support/sources/lost-uuid', 'VersionId' => 'noncurrent']], 'IsTruncated' => false])],
            ['ListMultipartUploads', new Result(['IsTruncated' => false])],
        ]);
        $ticket = app(SupportOperations::class)->createTicket('fixture@example.test', 'Legacy without keys', 'web');
        $ticket->messages()->create(['direction' => 'inbound', 'body' => 'Retained text']);
        $identity = app(SupportMediaStorage::class)->identity('support-private');
        $this->mock(SupportLegacyEnvironmentEvidence::class)->shouldReceive('evidenceFor')->once()->andReturn([
            'receipt' => ['domains' => [['id' => 'fixture-store', 'purpose' => 'legacy_store', 'identity' => $identity]]],
        ]);
        app(SupportOperations::class)->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->revision, 's3-version-close');
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);

        $this->assertSame('blocked', SupportMediaDeletion::sole()->status);
        $this->assertSame('legacy_unknown', SupportMediaDeletion::sole()->coverage);
        $this->assertSame('MEDIA_LEGACY_RESOURCE_UNRESOLVED', SupportMediaDeletion::sole()->error_code);
        $this->assertCount(3, $this->commands);
    }

    public function test_a_transport_failure_retains_committed_ownership_and_never_reports_cleanup_complete(): void
    {
        Queue::fake();
        $this->disk([['PutObject', new AwsException('fixture interrupted put', new Command('PutObject'), ['connection_error' => true])]]);
        $operations = app(SupportOperations::class);
        $ticket = $operations->createTicket('fixture@example.test', 'Existing text', 'web');
        $message = $ticket->messages()->create(['direction' => 'inbound', 'body' => 'Existing text']);
        $media = app(SupportMedia::class);
        $reservation = $media->reserve('fixture-ambiguous-put', hash('sha256', 'fixture'), 'images', $ticket->id);
        $path = tempnam(sys_get_temp_dir(), 'support-s3-fixture-');
        $this->temporary[] = $path;
        file_put_contents($path, 'fixture');
        try {
            $media->write($reservation, 'image', $path);
            $this->fail('Expected an interrupted put');
        } catch (AwsException) {
            $this->assertSame('uncertain', $reservation->fresh()->files['image']['state']);
        }
        $operations->close(User::factory()->create(['role' => 'admin_full']), $ticket->id, $ticket->revision, 's3-uncertain-close');
        app(SupportMediaRemoval::class)->run(SupportMediaDeletion::sole()->id);

        $this->assertSame('blocked', SupportMediaDeletion::sole()->status);
        $this->assertSame('MEDIA_IO_UNCONFIRMED', SupportMediaDeletion::sole()->error_code);
        $this->assertNull(SupportMediaDeletion::sole()->completed_at);
        $this->assertSame('Existing text', $message->fresh()->body);
        $this->assertCount(1, $this->commands);
    }

    public function test_retention_failure_is_returned_without_governance_bypass_or_false_absence(): void
    {
        $key = 'support/voices/locked';
        $this->disk([
            ['ListObjectsV2', new Result(['Contents' => [['Key' => $key]], 'IsTruncated' => false])],
            ['ListObjectVersions', new Result(['Versions' => [['Key' => $key, 'VersionId' => 'locked']], 'IsTruncated' => false])],
            ['ListMultipartUploads', new Result(['IsTruncated' => false])],
            ['DeleteObject', new AwsException('fixture retention', new Command('DeleteObject'), ['code' => 'AccessDenied'])],
        ]);
        $storage = app(SupportMediaStorage::class);
        try {
            $storage->deleteKey($storage->identity('support-private'), 'voices/locked');
            $this->fail('Expected retention refusal');
        } catch (AwsException $exception) {
            $this->assertSame('AccessDenied', $exception->getAwsErrorCode());
        }
        $this->assertArrayNotHasKey('BypassGovernanceRetention', $this->commands[3]['arguments']);
        $this->assertCount(4, $this->commands);
    }
}
