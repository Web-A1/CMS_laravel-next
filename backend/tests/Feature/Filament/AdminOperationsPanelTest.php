<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Operations\Orders;
use App\Filament\Pages\Operations\Promos;
use App\Filament\Pages\Operations\ShortLinks;
use App\Filament\Pages\Operations\SupportTicketDetail;
use App\Filament\Pages\Operations\SupportTickets;
use App\Filament\Pages\Operations\Tasks;
use App\Models\AdminOperationEvent;
use App\Models\AdminShortLink;
use App\Models\AdminSupportTicket;
use App\Models\AdminTask;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\SupportMailReceipt;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\Support\SupportMailbox;
use App\Services\Support\SupportOperations;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SupportDatabase;
use Tests\TestCase;

class AdminOperationsPanelTest extends TestCase
{
    use SupportDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('support-private');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    public function test_task_create_edit_delete_use_current_actor_and_audit(): void
    {
        $page = Livewire::test(Tasks::class)->callAction(TestAction::make('create')->table(), data: ['title' => 'Задача', 'description' => 'Личный текст']);
        $task = AdminTask::query()->firstOrFail();
        $this->assertSame(auth('web')->id(), $task->created_by);
        $page->callAction(TestAction::make('edit')->table($task), data: ['title' => 'Изменена', 'description' => 'Личный текст']);
        $this->assertSame('Изменена', $task->fresh()->title);
        $page->callAction(TestAction::make('delete')->table($task));
        $this->assertDatabaseMissing('admin_tasks', ['id' => $task->id]);
        $this->assertDatabaseCount('admin_operation_events', 3);
        foreach (AdminOperationEvent::all() as $event) {
            $this->assertArrayNotHasKey('description', $event->payload);
            $this->assertArrayNotHasKey('title', $event->payload);
        }
    }

    public function test_support_status_preserves_body_and_has_no_delete_or_body_in_audit(): void
    {
        $page = Livewire::test(SupportTickets::class)->callAction(TestAction::make('create')->table(), data: ['subject' => 'Помощь', 'description' => 'Текст обращения']);
        $ticket = AdminSupportTicket::query()->firstOrFail();
        Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id])
            ->set('status', 'closed')->callAction(TestAction::make('saveDetails')->schemaComponent('detailsActions', 'detailsForm'));
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('Текст обращения', $ticket->fresh()->description);
        $page->assertActionDoesNotExist(TestAction::make('delete')->table($ticket));
        $this->assertDatabaseHas('admin_support_tickets', ['id' => $ticket->id]);
        $this->assertStringNotContainsString('Текст обращения', AdminOperationEvent::all()->toJson());
    }

    public function test_support_subject_email_and_number_filters_restrict_actual_records(): void
    {
        $operations = app(SupportOperations::class);
        $first = $operations->createTicket('anatomy@example.test', 'Anatomy lesson question', 'admin');
        $second = $operations->createTicket('billing@example.test', 'Оплата курса', 'admin');
        $third = $operations->createTicket('technical@example.test', 'Видео не открывается', 'admin');
        foreach ([
            ['subject', 'Anatomy', $first, [$second, $third]],
            ['contact_email', 'billing@example.test', $second, [$first, $third]],
            ['public_number', $third->public_number, $third, [$first, $second]],
        ] as [$filter, $value, $matching, $others]) {
            Livewire::test(SupportTickets::class)
                ->filterTable($filter, ['value' => $value])
                ->assertCanSeeTableRecords([$matching])->assertCanNotSeeTableRecords($others);
        }
        Livewire::test(SupportTickets::class)
            ->filterTable('contact', ['value' => $third->public_number])
            ->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$first, $second]);
        Livewire::test(SupportTickets::class)
            ->filterTable('contact_email', ['value' => 'missing@example.test'])
            ->assertCanNotSeeTableRecords([$first, $second, $third])->assertSee('Обращения не найдены');
    }

    public function test_support_detail_reply_conflict_retains_draft_and_current_rights_are_rechecked(): void
    {
        Queue::fake();
        app(SupportOperations::class)->submit('guest@example.test', 'Вопрос', 'livewire-submit');
        $ticket = AdminSupportTicket::firstOrFail();
        $page = Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id])->set('replyBody', 'Сохранённый черновик');
        app(SupportOperations::class)->change(auth('web')->user(), $ticket->id, ['revision' => $ticket->revision, 'status' => 'in_progress'], 'livewire-status');
        $replyAction = TestAction::make('sendReply')->schemaComponent('replyActions', 'replyForm');
        $page->callAction($replyAction)->assertHasErrors('operation')->assertSet('replyBody', 'Сохранённый черновик');
        $this->assertDatabaseCount('support_messages', 1);
        $page->call('refreshHistory')->callAction($replyAction)->assertHasNoErrors();
        $this->assertDatabaseCount('support_messages', 2);
        $page->set('replyBody', 'Запрещено');
        auth('web')->user()->update(['role' => 'admin_limited']);
        $page->call('sendReply')->assertForbidden();
        $this->assertDatabaseCount('support_messages', 2);
    }

    public function test_support_parameter_and_metadata_ack_keep_reply_draft_and_independent_command_keys(): void
    {
        Queue::fake();
        Mail::fake();
        $ticket = app(SupportOperations::class)->createTicket('stored@example.test', 'Stored body', 'admin');
        $page = Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id]);
        $opening = $page->get('savingOpening');
        $keys = [$page->get('replyKey'), $page->get('retryKey'), $page->get('closeKey')];
        $page->set('contactEmail', 'invalid draft')->set('replyBody', 'Unsent reply')->set('status', 'in_progress')
            ->call('saveSupportParameters', ['status' => 'in_progress'], 1, $opening)->assertHasNoErrors()
            ->assertSet('contactEmail', 'invalid draft')->assertSet('replyBody', 'Unsent reply')->assertSet('revision', 2);
        $this->assertSame('stored@example.test', $ticket->fresh()->contact_email);
        $page->call('saveDetails')->assertHasErrors('contactEmail')->assertSet('replyBody', 'Unsent reply');
        $page->set('contactEmail', 'new@example.test')->call('saveDetails')->assertHasNoErrors()
            ->assertSet('replyBody', 'Unsent reply')->assertSet('revision', 3)->assertSet('savingOpening', $opening);
        $page->call('saveDetails')->assertHasNoErrors()->assertSet('revision', 3);
        $page->set('contactEmail', '')->call('saveDetails')->assertHasNoErrors()->assertSet('revision', 3);
        $this->assertSame('new@example.test', $ticket->fresh()->contact_email);
        $this->assertSame($keys, [$page->get('replyKey'), $page->get('retryKey'), $page->get('closeKey')]);
        $this->assertDatabaseCount('admin_operation_events', 2);
        $this->assertDatabaseCount('support_messages', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_explicit_support_reply_and_retry_ack_do_not_replace_dirty_metadata(): void
    {
        Queue::fake();
        Mail::fake();
        $ticket = app(SupportOperations::class)->createTicket('stored@example.test', 'Stored body', 'admin');
        $page = Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id]);
        $changeKey = $page->get('changeKey');
        $opening = $page->get('savingOpening');
        $page->set('status', 'waiting')->set('contactEmail', 'unsaved@example.test')->set('replyBody', 'Explicit reply')
            ->call('sendReply')->assertHasNoErrors()->assertSet('replyBody', '')->assertSet('revision', 2)
            ->assertSet('status', 'waiting')->assertSet('contactEmail', 'unsaved@example.test')
            ->assertSet('changeKey', $changeKey)->assertSet('savingOpening', $opening);
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertSame('stored@example.test', $ticket->fresh()->contact_email);
        $message = $ticket->messages()->sole();
        $message->update(['delivery_status' => 'failed']);
        $replyKey = $page->get('replyKey');
        $page->set('replyBody', 'Next unsent reply')->call('retryDelivery', $message->id)->assertHasNoErrors()
            ->assertSet('replyBody', 'Next unsent reply')->assertSet('contactEmail', 'unsaved@example.test')
            ->assertSet('status', 'waiting')->assertSet('changeKey', $changeKey)->assertSet('replyKey', $replyKey);
        $this->assertDatabaseCount('support_messages', 1);
        Mail::assertNothingSent();
    }

    public function test_support_detail_row_url_full_text_reply_conflict_and_status(): void
    {
        Queue::fake();
        $body = str_repeat('Полный текст ', 40).'<script>alert(1)</script>';
        app(SupportOperations::class)->submit('guest@example.test', $body, 'detail-submit');
        $ticket = AdminSupportTicket::firstOrFail();
        $url = SupportTicketDetail::getUrl(['ticketId' => $ticket->id, 'list' => ['page' => 1]]);
        $this->assertNotSame($body, $ticket->subject);
        $list = Livewire::test(SupportTickets::class);
        $this->assertSame($url, $list->instance()->getTable()->getRecordUrl($ticket));
        $response = $this->get($url)->assertOk()->assertSee(e($body), false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('data-ui="admin.support.reply"', false)->assertSee('data-ui="admin.support.messages"', false);
        $this->assertSame(1, substr_count($response->getContent(), e($body)));
        $response->assertSee('<h2 data-ui="admin.support.subject">'.e($ticket->subject).'</h2>', false);
        $page = Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id])->set('replyBody', 'Черновик ответа');
        app(SupportOperations::class)->change(auth('web')->user(), $ticket->id, ['revision' => $ticket->revision, 'status' => 'in_progress'], 'detail-external-status');
        $replyAction = TestAction::make('sendReply')->schemaComponent('replyActions', 'replyForm');
        $page->callAction($replyAction)->assertHasErrors('operation')->assertSet('replyBody', 'Черновик ответа');
        $this->assertDatabaseCount('support_messages', 1);
        $page->call('refreshHistory')->assertSet('replyBody', 'Черновик ответа')->callAction($replyAction)->assertHasNoErrors()->assertSet('replyBody', '');
        $this->assertDatabaseCount('support_messages', 2);
        $page->set('status', 'closed')->callAction(TestAction::make('saveDetails')->schemaComponent('detailsActions', 'detailsForm'))->assertHasNoErrors();
        $this->assertSame('closed', $ticket->fresh()->status);
        auth('web')->user()->update(['role' => 'admin_limited']);
        $page->call('refreshHistory')->assertForbidden();
    }

    public function test_support_detail_requires_admin_session_and_current_support_rights(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', 'Скрытый текст', 'admin');
        $url = SupportTicketDetail::getUrl(['ticketId' => $ticket->id]);
        auth('web')->logout();
        $this->get($url)->assertRedirect();
        foreach (['user', 'admin_limited', 'teacher', 'manager'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')->get($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $this->get(SupportTicketDetail::getUrl(['ticketId' => 999999]))->assertNotFound();
    }

    public function test_support_detail_breadcrumb_preserves_bounded_list_state(): void
    {
        $ticket = app(SupportOperations::class)->createTicket('guest@example.test', str_repeat('Полный исходный текст ', 30), 'admin');
        $context = ['filters' => ['subject' => ['value' => 'Полный'], 'contact' => ['value' => 'guest@example.test'], 'status' => ['value' => 'new']], 'sort' => 'last_message_at:desc', 'page' => 3];
        $list = Livewire::test(SupportTickets::class)->set('tableFilters', $context['filters'])->set('tableSort', $context['sort'])->call('setPage', 3);
        $url = $list->instance()->getTable()->getRecordUrl($ticket);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEquals($context, $query['list']);
        $page = Livewire::withQueryParams(['list' => $context])->test(SupportTicketDetail::class, ['ticketId' => $ticket->id]);
        $this->assertSame([SupportTickets::getUrl($context) => 'Поддержка', 'Обращение'], $page->instance()->getBreadcrumbs());
        $this->assertSame([], SupportTicketDetail::sanitizeListContext(['filters' => ['subject' => ['value' => ['bad']], 'redirect' => ['value' => 'https://example.test']], 'page' => ['bad'], 'sort' => ['bad']]));
    }

    public function test_support_health_uses_operator_labels_and_recovery_action_requires_unresolved_candidates(): void
    {
        config(['mail.mailers.support.host' => '127.0.0.1', 'support.imap.host' => null]);
        $page = Livewire::test(SupportTickets::class)
            ->assertSee('Отправка писем: настроена')->assertSee('Приём писем: не подключён')
            ->assertActionHidden(TestAction::make('resolveReceipt')->table());
        $receipt = SupportMailReceipt::create(['mailbox' => config('support.address'), 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => 1, 'outcome' => 'quarantine', 'reason' => 'MIME_PARSE_FAILED']);
        Livewire::test(SupportTickets::class)->assertActionHidden(TestAction::make('resolveReceipt')->table())->assertSee('MIME_PARSE_FAILED');
        $receipt->update(['outcome' => 'recovery_manual', 'reason' => 'RECEIVED_TIME_UNKNOWN', 'original_key' => 'fixture.eml', 'disk' => 'support-private']);
        Livewire::test(SupportTickets::class)->assertActionVisible(TestAction::make('resolveReceipt')->table())->assertSee('Разобрать спорные письма');
        $receipt->update(['outcome' => 'historical']);
        Livewire::test(SupportTickets::class)->assertActionHidden(TestAction::make('resolveReceipt')->table());
        try {
            app(SupportOperations::class)->resolveReceipt(auth('web')->user(), $receipt->id, 'accept', 'resolved-receipt-guard');
            $this->fail('Resolved receipt must reject a fresh action.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('historical', $receipt->fresh()->outcome);
        $this->assertDatabaseCount('admin_operation_events', 0);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_support_health_translates_all_mailbox_states_without_changing_service_codes(): void
    {
        config(['support.imap.host' => 'fixture.invalid', 'support.imap.username' => 'fixture', 'support.imap.password' => 'synthetic', 'support.imap.enabled' => true]);
        $state = app(SupportMailbox::class)->state();
        foreach (['inactive' => 'ожидает первого включения', 'active' => 'включён', 'recovery_required' => 'требует восстановления после сбоя', 'connection_failed' => 'ошибка подключения'] as $code => $label) {
            $state->update(['status' => $code]);
            $page = Livewire::test(SupportTickets::class)->assertSee('Приём писем: '.$label);
            $this->assertSame($code, $page->instance()->supportHealth()['imap']);
            $page->assertActionHidden(TestAction::make('resolveReceipt')->table());
        }
        config(['support.imap.enabled' => false]);
        Livewire::test(SupportTickets::class)->assertSee('Приём писем: выключен');
    }

    public function test_support_history_and_local_action_labels_use_russian_without_internal_revision_heading(): void
    {
        Queue::fake();
        app(SupportOperations::class)->submit('guest@example.test', 'Вопрос для истории', 'history-labels-key');
        $ticket = AdminSupportTicket::firstOrFail();
        $operations = app(SupportOperations::class);
        $operations->reply(auth('web')->user(), $ticket->id, 'Ответ для истории', $ticket->revision, 'history-labels-reply');
        $operations->outgoing($ticket->fresh(), 'Уведомление для истории', 'notice', 'history-labels-notice');
        $html = view('filament.operations.support-history', ['ticket' => $ticket->fresh()->load('messages.attachments'), 'events' => collect()])->render();
        $this->assertStringContainsString('Сообщение пользователя', $html);
        $this->assertStringContainsString('Ответ поддержки', $html);
        $this->assertStringContainsString('Служебное сообщение', $html);
        $this->assertStringNotContainsString('revision', $html);
        $this->assertStringNotContainsString(' · inbound · ', $html);
        $page = Livewire::test(SupportTickets::class);
        foreach (['history', 'reply', 'edit', 'retry'] as $action) {
            $page->assertActionDoesNotExist(TestAction::make($action)->table($ticket));
        }
        $page->assertActionExists(TestAction::make('create')->table(), fn ($action) => $action->getModalSubmitActionLabel() === 'Создать обращение' && $action->getModalCancelActionLabel() === 'Отмена');
        $detail = Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id]);
        $detail->assertActionHidden(TestAction::make('retryDelivery'));
        $ticket->messages()->where('direction', 'outbound')->firstOrFail()->update(['delivery_status' => 'failed']);
        Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id])
            ->assertActionVisible(TestAction::make('retryDelivery'))
            ->assertActionExists(TestAction::make('retryDelivery'), fn ($action) => $action->getModalSubmitActionLabel() === 'Повторить отправку' && $action->getModalCancelActionLabel() === 'Отмена');
    }

    public function test_support_retry_in_detail_checks_ticket_scope(): void
    {
        Queue::fake();
        $operations = app(SupportOperations::class);
        $operations->submit('guest@example.test', 'Вопрос', 'retry-detail-ticket');
        $ticket = AdminSupportTicket::latest('id')->firstOrFail();
        $operations->submit('other@example.test', 'Другой вопрос', 'retry-detail-other');
        $other = AdminSupportTicket::latest('id')->firstOrFail();
        $message = $operations->reply(auth('web')->user(), $ticket->id, 'Ответ', $ticket->revision, 'retry-detail-reply');
        $message->update(['delivery_status' => 'failed']);

        Livewire::test(SupportTicketDetail::class, ['ticketId' => $other->id])
            ->call('retryDelivery', $message->id)->assertNotFound();
        Livewire::test(SupportTicketDetail::class, ['ticketId' => $ticket->id])
            ->callAction(TestAction::make('retryDelivery'), data: ['message_id' => $message->id])
            ->assertHasNoErrors();
        $this->assertSame('pending', $message->fresh()->delivery_status);
        $this->assertDatabaseHas('admin_operation_events', ['operation' => 'support_retry', 'resource_id' => $ticket->id]);
    }

    public function test_support_history_explains_delivery_and_only_renders_actual_status_transitions(): void
    {
        Queue::fake();
        app(SupportOperations::class)->submit('guest@example.test', 'Вопрос', 'history-delivery-key');
        $ticket = AdminSupportTicket::firstOrFail();
        foreach (['pending', 'sending', 'sent', 'failed', 'delivery_unknown'] as $status) {
            $ticket->messages()->create(['direction' => 'outbound', 'body' => 'Тестовый ответ', 'delivery_status' => $status, 'error_code' => $status === 'failed' ? 'SMTP_PERMANENT_REJECTION' : null]);
        }
        $source = $ticket->messages()->first();
        foreach (['pending', 'failed'] as $status) {
            $source->attachments()->create(['status' => $status, 'disk' => 'support-private', 'mime' => 'image/png', 'bytes' => 10, 'error_code' => $status === 'failed' ? 'IMAGE_PROCESSING_FAILED' : null]);
        }
        $events = collect([
            new AdminOperationEvent(['operation' => 'support_reply', 'payload' => []]),
            new AdminOperationEvent(['operation' => 'support_update', 'payload' => ['from' => 'new', 'to' => 'new']]),
            new AdminOperationEvent(['operation' => 'support_sent', 'payload' => ['from' => 'new', 'to' => 'waiting']]),
        ])->each(fn ($event) => $event->created_at = now());
        $html = view('filament.operations.support-history', ['ticket' => $ticket->fresh()->load('messages.attachments'), 'events' => $events])->render();
        $text = html_entity_decode(strip_tags($html));
        $this->assertStringContainsString('Принято почтовым сервером', $text);
        $this->assertStringContainsString('Это не подтверждение прочтения.', $text);
        $this->assertStringContainsString('Результат отправки неизвестен', $text);
        $this->assertStringContainsString('Не удалось отправить', $text);
        $this->assertStringContainsString('Обрабатывается', $text);
        $this->assertStringContainsString('Не удалось обработать', $text);
        $this->assertStringContainsString('SMTP_PERMANENT_REJECTION', $text);
        $this->assertStringContainsString('IMAGE_PROCESSING_FAILED', $text);
        $this->assertStringContainsString('Ответ подготовлен к отправке', $text);
        $this->assertStringContainsString('Новое → Ждём ответа', $text);
        $this->assertSame(1, substr_count($text, '→'));
        $this->assertStringNotContainsString('support_reply', $text);
        $this->assertStringNotContainsString('support_sent', $text);
        $this->assertStringNotContainsString('delivery_unknown', $text);
    }

    public function test_short_link_create_and_edit_render_target_help_with_native_save_hint_and_association(): void
    {
        $link = app(AdminOperationsService::class)->links(auth('web')->user(), 'create', ['slug' => 'help-link', 'target_path' => '/articles'], key: 'help-link-create')['data'];
        foreach ([TestAction::make('create')->table(), TestAction::make('edit')->table($link)] as $action) {
            $page = Livewire::test(ShortLinks::class)->mountAction($action);
            $html = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
            $this->assertNotEmpty($html);
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $input = $xpath->query('//input[contains(@id, "target_path")]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $input);
            $this->assertContains('short-link-target-help', explode(' ', $input->getAttribute('aria-describedby')));
            $help = $xpath->query('//*[@id="short-link-target-help"]');
            $this->assertSame(1, $help->length);
            $this->assertSame('Например, /courses/anatomy', trim($help->item(0)->textContent));
            $hint = $xpath->query('//*[@data-ui="field-saving.hint" and contains(@*[name()="x-show"], "target_path")]//button[@type="submit"]');
            $this->assertSame(1, $hint->length);
            $this->assertSame('Сохранить', trim($hint->item(0)->textContent));
        }
    }

    public function test_short_links_reject_unsafe_target_and_keep_actor_ownership(): void
    {
        $page = Livewire::test(ShortLinks::class)->callAction(TestAction::make('create')->table(), data: ['slug' => 'safe-link', 'target_path' => '/courses']);
        $link = AdminShortLink::query()->firstOrFail();
        $this->assertSame(auth('web')->id(), $link->created_by);
        $page->callAction(TestAction::make('edit')->table($link), data: ['slug' => 'safe-link', 'target_path' => '/admin/users']);
        $this->assertSame('/courses', $link->fresh()->target_path);
        $this->assertDatabaseCount('admin_operation_events', 1);
    }

    public function test_promos_convert_percentage_and_toggle_without_exposing_hashes(): void
    {
        $page = Livewire::test(Promos::class)->callAction(TestAction::make('create')->table(), data: [
            'code' => 'PANEL15', 'discount_kind' => 'percent_bps', 'percent' => 15,
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), 'activation_limit' => 10, 'per_user_limit' => 1,
        ]);
        $promo = PromoCode::query()->firstOrFail();
        $this->assertSame(1500, $promo->discount_bps);
        $this->assertSame(hash('sha256', 'PANEL15'), $promo->code_hash);
        $page->callAction(TestAction::make('create')->table(), data: [
            'code' => 'PANEL20', 'discount_kind' => 'percent_bps', 'percent' => 20,
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), 'activation_limit' => 10, 'per_user_limit' => 1,
        ]);
        $otherPromo = PromoCode::query()->whereKeyNot($promo->id)->firstOrFail();
        $page->callAction(TestAction::make('toggle')->table((string) $promo->id));
        $this->assertSame('revoked', $promo->fresh()->status);
        $this->assertSame('active', $otherPromo->fresh()->status);
        $page->assertDontSee($promo->code_hash);
    }

    public function test_short_link_domain_validation_is_visible_and_preserves_open_form(): void
    {
        $page = Livewire::test(ShortLinks::class)
            ->callAction(TestAction::make('create')->table(), data: ['slug' => 'invalid-link', 'target_path' => 'https://example.com'])
            ->assertHasErrors(['mountedActions.0.data.target_path'])
            ->assertSet('mountedActions.0.name', 'create')
            ->assertSet('mountedActions.0.data.target_path', 'https://example.com');
        $message = $page->instance()->getErrorBag()->first('mountedActions.0.data.target_path');
        $this->assertNotEmpty($message);
        // Filament renders action modals as response partials; Livewire's html()
        // retains the preceding full page and does not merge those partials.
        $modalHtml = $page->effects['partials']['action-modals.0'] ?? '';
        $this->assertNotEmpty($modalHtml, 'Expected the open action modal to be rendered.');
        $this->assertStringContainsString($message, html_entity_decode(strip_tags($modalHtml)));
        $this->assertDatabaseCount('admin_short_links', 0);
        $this->assertDatabaseCount('admin_operation_events', 0);
    }

    public function test_short_link_unsafe_target_can_be_corrected_in_the_same_edit_modal(): void
    {
        $link = app(AdminOperationsService::class)->links(auth('web')->user(), 'create', ['slug' => 'correctable-link', 'target_path' => '/articles'], key: 'correctable-link-create')['data'];
        $page = Livewire::test(ShortLinks::class)->mountAction(TestAction::make('edit')->table($link));
        $key = $page->get('operationKey');
        $opening = $page->get('manualOpening');
        $page->setActionData(['slug' => 'retained-draft-slug', 'target_path' => '/admin/users'])
            ->callMountedAction()->assertHasErrors(['mountedActions.0.data.target_path'])
            ->assertActionMounted(TestAction::make('edit')->table($link))
            ->assertActionDataSet(['slug' => 'retained-draft-slug', 'target_path' => '/admin/users'])
            ->assertSet('operationKey', $key)->assertSet('manualOpening', $opening);
        $this->assertSame('correctable-link', $link->fresh()->slug);
        $this->assertSame('/articles', $link->fresh()->target_path);
        $this->assertDatabaseCount('admin_operation_events', 1);
        $page->set('mountedActions.0.data.target_path', '/faq')->callMountedAction()
            ->assertHasNoActionErrors()->assertReturned(['ok' => true]);
        $this->assertSame('/faq', $link->fresh()->target_path);
        $this->assertSame('retained-draft-slug', $link->fresh()->slug);
        $this->assertDatabaseCount('admin_operation_events', 2);
    }

    public function test_panel_rejects_forged_task_fields(): void
    {
        Livewire::test(Tasks::class)->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data', ['title' => 'Wrong', 'description' => '', 'created_by' => 99])
            ->callMountedAction()->assertHasErrors();
        $this->assertDatabaseCount('admin_tasks', 0);
    }

    public function test_duplicate_operation_notifies_and_keeps_form_open(): void
    {
        $page = Livewire::test(Tasks::class)->mountAction(TestAction::make('create')->table());
        app(AdminOperationsService::class)->tasks(auth('web')->user(), 'create', ['title' => 'Original'], key: $page->get('operationKey'));
        $page->fillForm(['title' => 'Retry'])->callMountedAction()
            ->assertNotified('Изменение не сохранено')
            ->assertSet('mountedActions.0.name', 'create')
            ->assertSet('mountedActions.0.data.title', 'Retry');
        $this->assertDatabaseCount('admin_tasks', 1);
    }

    public function test_revoked_role_after_mount_cannot_write(): void
    {
        $page = Livewire::test(Tasks::class)->mountAction(TestAction::make('create')->table())->fillForm(['title' => 'Wrong']);
        User::query()->whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('admin_tasks', 0);
    }

    public function test_service_keeps_duplicate_key_conflict_and_blocks_impersonation(): void
    {
        $service = app(AdminOperationsService::class);
        $actor = auth('web')->user();
        $service->tasks($actor, 'create', ['title' => 'Once'], key: 'one-key');
        try {
            $service->tasks($actor, 'create', ['title' => 'Twice'], key: 'one-key');
            $this->fail('Expected duplicate-key conflict');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        request()->attributes->set('impersonation.active', true);
        try {
            $service->tasks($actor, 'create', ['title' => 'Wrong'], key: 'another-key');
            $this->fail('Expected impersonation refusal');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('admin_tasks', 1);
    }

    public function test_order_filters_and_read_only_page_preserve_amounts(): void
    {
        $user = User::factory()->create();
        Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 12345,
            'amount_final' => 12345, 'amount_paid' => 12345, 'payment_status' => 'paid']);
        Order::query()->create(['user_id' => $user->id, 'currency' => 'USD', 'amount_total' => 100,
            'amount_final' => 100, 'payment_status' => 'pending']);
        $page = Livewire::test(Orders::class)->filterTable('payment_status', 'paid')->assertSee('123,45 RUB');
        $this->assertCount(1, $page->instance()->getTableRecords());
        $this->assertSame([], $page->instance()->getTable()->getActions());
    }

    public function test_non_admin_operations_routes_and_queries_are_denied(): void
    {
        foreach (['manager', 'teacher', 'admin_limited', 'user_no_purchase'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            foreach ([Tasks::class, SupportTickets::class, ShortLinks::class, Promos::class] as $page) {
                Livewire::test($page)->assertForbidden();
            }
        }
    }

    public function test_manual_create_modals_keep_drafts_until_explicit_native_submit_and_return_ack(): void
    {
        Queue::fake();
        Mail::fake();
        foreach ([
            [Tasks::class, AdminTask::class, ['title' => 'Manual task', 'description' => 'Private task body']],
            [ShortLinks::class, AdminShortLink::class, ['slug' => 'manual-link', 'target_path' => '/faq']],
            [SupportTickets::class, AdminSupportTicket::class, ['subject' => 'Manual support', 'description' => 'Private support body', 'contact_email' => 'fixture@example.test']],
        ] as [$pageClass, $modelClass, $data]) {
            $page = Livewire::test($pageClass)->mountAction(TestAction::make('create')->table());
            $action = $page->instance()->getMountedAction();
            $cancel = $action->getModalCancelAction();
            $this->assertSame('cancel', $cancel->getName());
            $this->assertTrue($cancel->shouldClose());
            $this->assertSame($action->getModalCancelActionLabel(), $cancel->getLabel());
            $opening = $page->get('manualOpening');
            $this->assertNotEmpty($opening);
            $this->assertNull($page->get('manualSnapshot'));
            $page->setActionData($data)->assertSet('manualOpening', $opening);
            $this->assertSame(0, $modelClass::count());
            $page->callMountedAction()->assertHasNoActionErrors()->assertReturned(['ok' => true]);
            $this->assertSame(1, $modelClass::count());
        }
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertDatabaseMissing('support_messages', ['direction' => 'outbound']);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_manual_edit_snapshot_conflict_retains_open_draft_for_tasks_and_links(): void
    {
        $service = app(AdminOperationsService::class);
        foreach ([
            [Tasks::class, 'tasks', ['title' => 'Original', 'description' => 'Body'], ['title' => 'External', 'description' => 'Body'], ['title' => 'Draft', 'description' => 'Unsaved']],
            [ShortLinks::class, 'links', ['slug' => 'panel-cas', 'target_path' => '/courses'], ['slug' => 'panel-cas', 'target_path' => '/faq'], ['slug' => 'panel-cas', 'target_path' => '/about']],
        ] as [$pageClass, $method, $original, $external, $draft]) {
            $model = $service->$method(auth('web')->user(), 'create', $original, key: $method.'-panel-cas-create')['data'];
            $page = Livewire::test($pageClass)->mountAction(TestAction::make('edit')->table($model));
            $this->assertSame($method === 'tasks' ? [...$original, 'assignee_id' => null, 'status' => 'open'] : $original, $page->get('manualSnapshot'));
            $service->$method(auth('web')->user(), 'update', $external, $model->id, $method.'-panel-cas-external');
            $page->setActionData($draft)->callMountedAction()->assertNotified('Изменение не сохранено')
                ->assertActionMounted(TestAction::make('edit')->table($model))->assertActionDataSet($draft)->assertReturned(null);
            $this->assertSame($external, $model->fresh()->only(array_keys($external)));
        }
        $this->assertDatabaseCount('admin_operation_events', 4);
    }

    public function test_manual_clean_edit_returns_ack_without_audit_and_opening_rotates(): void
    {
        $service = app(AdminOperationsService::class);
        foreach ([
            [Tasks::class, 'tasks', ['title' => 'Original', 'description' => null]],
            [ShortLinks::class, 'links', ['slug' => 'panel-noop', 'target_path' => '/courses']],
        ] as [$pageClass, $method, $data]) {
            $model = $service->$method(auth('web')->user(), 'create', $data, key: $method.'-panel-noop-create')['data'];
            $before = $model->fresh()->getAttributes();
            $page = Livewire::test($pageClass)->mountAction(TestAction::make('edit')->table($model));
            $opening = $page->get('manualOpening');
            $page->callMountedAction()->assertHasNoActionErrors()->assertReturned(['ok' => true]);
            $this->assertSame($before, $model->fresh()->getAttributes());
            $page->mountAction(TestAction::make('edit')->table($model));
            $this->assertNotSame($opening, $page->get('manualOpening'));
            $page->assertActionDataSet($data);
        }
        $this->assertDatabaseCount('admin_operation_events', 2);
    }

    public function test_support_create_validation_preserves_draft_and_does_not_send(): void
    {
        Queue::fake();
        Mail::fake();
        Livewire::test(SupportTickets::class)->mountAction(TestAction::make('create')->table())
            ->setActionData(['subject' => 'Draft subject', 'description' => 'Draft body', 'contact_email' => 'invalid'])
            ->callMountedAction()->assertHasErrors(['mountedActions.0.data.contact_email'])
            ->assertActionDataSet(['subject' => 'Draft subject', 'description' => 'Draft body', 'contact_email' => 'invalid']);
        $this->assertDatabaseCount('admin_support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }
}
