<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Subject;
use App\Models\User;
use App\Services\Faq\AdminFaqService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminFaqServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private AdminFaqService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'admin_full']);
        $this->service = app(AdminFaqService::class);
    }

    private function create(array $data = []): ContentEntry
    {
        return $this->service->execute($this->actor, 'create', [...$this->input(), ...$data], (string) Str::uuid());
    }

    private function input(): array
    {
        return ['title' => ' Вопрос? ', 'body' => " Ответ\n\nВторой абзац ", 'show_on_home' => true, 'sort_order' => 12];
    }

    private function change(ContentEntry $entry, string $operation, array $data = []): ?ContentEntry
    {
        return $this->service->execute($this->actor, $operation, $data, (string) Str::uuid(), $entry->id, $entry->revision);
    }

    public function test_lifecycle_preserves_text_and_separates_publication_from_placement(): void
    {
        $entry = $this->create();
        $this->assertSame('draft', $entry->status);
        $this->assertSame('Вопрос?', $entry->title);
        $this->assertSame("Ответ\n\nВторой абзац", $entry->body);
        $this->getJson('/api/v1/faqs')->assertJsonCount(0, 'data');
        $entry = $this->change($entry, 'publish');
        $this->getJson('/api/v1/faqs')->assertJsonCount(1, 'data');
        $entry = $this->change($entry, 'update', [...$this->input(), 'show_on_home' => false, 'sort_order' => 3]);
        $this->assertSame('published', $entry->status);
        $this->assertSame(3, $entry->sort_order);
        $this->assertSame([3, 3], $entry->placements->pluck('sort_order')->all());
        $this->getJson('/api/v1/faqs?placement=home.faq')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/faqs')->assertJsonCount(1, 'data');
        $entry = $this->change($entry, 'hide');
        $this->getJson('/api/v1/faqs')->assertJsonCount(0, 'data');
        $other = ContentEntry::query()->create(['content_type' => 'page', 'slug' => 'page', 'title' => 'Page', 'body' => 'Body']);
        $this->change($entry, 'delete');
        $this->assertDatabaseMissing('content_entries', ['id' => $entry->id]);
        $this->assertSame(0, ContentPlacement::query()->where('content_entry_id', $entry->id)->count());
        $this->assertNotNull($other->fresh());
        $this->assertSame(['revision'], array_keys(AdminOperationEvent::query()->latest('id')->firstOrFail()->payload));
        $this->assertStringNotContainsString('Вопрос', AdminOperationEvent::query()->get()->toJson());
        $this->assertStringNotContainsString('Ответ', AdminOperationEvent::query()->get()->toJson());
    }

    public function test_stale_revision_and_reused_key_cannot_modify_records(): void
    {
        $entry = $this->create();
        $key = (string) Str::uuid();
        $this->service->execute($this->actor, 'publish', [], $key, $entry->id, $entry->revision);
        foreach ([['hide', $key], ['parameters', $key], ['delete', (string) Str::uuid()], ['placement', (string) Str::uuid()], ['parameters', (string) Str::uuid()], ['update', (string) Str::uuid()]] as [$operation, $operationKey]) {
            try {
                $this->service->execute($this->actor, $operation, [], $operationKey, $entry->id, $entry->revision);
                $this->fail('Stale revision or key must fail.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertSame(2, $entry->fresh()->revision);
        $this->assertSame(2, AdminOperationEvent::query()->count());
    }

    public function test_whitespace_and_unknown_fields_fail_without_writes(): void
    {
        foreach ([['title' => '  '], ['body' => "\n "], ['status' => 'published'], ['content_type' => 'page'], ['body' => str_repeat('a', 20001)]] as $input) {
            try {
                $this->create($input);
                $this->fail('Invalid input must fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('content_entries', 0);
            }
        }
        $this->assertDatabaseCount('admin_operation_events', 0);
    }

    public function test_fresh_permissions_and_impersonation_are_checked(): void
    {
        $entry = $this->create();
        User::query()->whereKey($this->actor->id)->update(['role' => 'admin_limited']);
        try {
            $this->change($entry, 'delete');
            $this->fail('Revoked permissions must apply to stale actor.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            $this->service->query($this->actor);
            $this->fail('Revoked read permissions must apply.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actor->update(['role' => 'admin_full']);
        request()->attributes->set('impersonation.active', true);
        $this->expectException(HttpException::class);
        $this->change($entry, 'delete');
    }

    public function test_other_content_types_are_outside_faq_scope(): void
    {
        $other = ContentEntry::query()->create(['content_type' => 'article', 'slug' => 'article', 'title' => 'Article', 'body' => 'Body']);
        $this->expectException(ModelNotFoundException::class);
        $this->change($other, 'delete');
    }

    public function test_parameter_patch_preserves_manual_content_and_rejects_manual_fields(): void
    {
        $entry = $this->create();
        $subject = Subject::create(['slug' => 'faq-test', 'title' => 'Предмет', 'is_active' => true]);
        $entry = $this->change($entry, 'parameters', ['subject_id' => (string) $subject->id, 'show_on_home' => false]);
        $this->assertSame('Вопрос?', $entry->title);
        $this->assertSame("Ответ\n\nВторой абзац", $entry->body);
        $this->assertSame(12, $entry->sort_order);
        $this->assertSame($subject->id, $entry->subject_id);
        $this->assertFalse($entry->placements->firstWhere('placement', ContentPlacement::HOME_FAQ)->is_enabled);
        $this->assertSame(2, $entry->revision);
        $this->expectException(ValidationException::class);
        $this->change($entry, 'parameters', ['title' => 'Не записывать']);
    }

    public function test_normalized_no_op_preserves_revision_audit_and_placement_timestamps(): void
    {
        $entry = $this->create();
        $before = $entry->placements->map->getAttributes()->all();
        $this->travel(10)->seconds();
        foreach ([['parameters', ['show_on_home' => 1, 'subject_id' => '']], ['placement', ['show_on_home' => true]], ['update', $this->input()]] as [$operation, $input]) {
            $unchanged = $this->change($entry, $operation, $input);
            $this->assertSame(1, $unchanged->revision);
            $this->assertSame($before, $unchanged->placements->map->getAttributes()->all());
            $this->assertDatabaseCount('admin_operation_events', 1);
        }
        $changed = $this->change($entry, 'parameters', ['show_on_home' => false]);
        $this->assertSame(2, $changed->revision);
        $this->assertDatabaseCount('admin_operation_events', 2);
        $this->expectException(HttpException::class);
        $this->change($entry, 'parameters', ['show_on_home' => false]);
    }

    public function test_parameter_subject_validation_retains_only_current_inactive_subject(): void
    {
        $subject = Subject::create(['slug' => 'faq-current', 'title' => 'Предмет', 'is_active' => true]);
        $entry = $this->create(['subject_id' => $subject->id]);
        $subject->update(['is_active' => false]);
        $unchanged = $this->change($entry, 'parameters', ['subject_id' => (string) $subject->id]);
        $this->assertSame($entry->revision, $unchanged->revision);
        $other = Subject::create(['slug' => 'faq-other', 'title' => 'Другой', 'is_active' => false]);
        try {
            $this->change($entry, 'parameters', ['subject_id' => $other->id]);
            $this->fail('Other inactive subject must fail.');
        } catch (ValidationException) {
            $this->assertSame($subject->id, $entry->fresh()->subject_id);
        }
        $cleared = $this->change($entry, 'parameters', ['subject_id' => null]);
        $this->assertNull($cleared->subject_id);
    }

    public function test_parameter_write_is_forbidden_during_impersonation(): void
    {
        $entry = $this->create();
        request()->attributes->set('impersonation.active', true);
        try {
            $this->change($entry, 'parameters', ['show_on_home' => false]);
            $this->fail('Impersonation must forbid parameter writes.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(1, $entry->fresh()->revision);
            $this->assertDatabaseCount('admin_operation_events', 1);
        }
    }
}
