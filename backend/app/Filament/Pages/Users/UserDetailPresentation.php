<?php

namespace App\Filament\Pages\Users;

use App\Filament\Pages\UserDetail;
use App\Support\Admin\UsersDisplay;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Presentation only: authorization and authoritative state remain in UserDetail and its queries. */
final class UserDetailPresentation
{
    public static function status(?string $value): string
    {
        return [
            'draft' => 'Черновик', 'pending' => 'Ожидает оплаты', 'pending_payment' => 'Ожидает оплаты', 'paid' => 'Оплачен', 'failed' => 'Ошибка оплаты',
            'refunded' => 'Возвращён', 'partially_refunded' => 'Частичный возврат',
            'cancelled' => 'Отменён', 'canceled' => 'Отменён',
            'not_granted' => 'Не выдан', 'granted' => 'Выдан', 'revoked' => 'Отозван',
            'pending_grant' => 'Ожидает выдачи', 'grant_failed' => 'Ошибка выдачи доступа',
            'active' => 'Активен', 'expired' => 'Срок истёк',
            'full' => 'Полный', 'partial' => 'Частичный', 'none' => 'Нет доступа',
            'inactive' => 'Материал неактивен', 'not_found' => 'Материал не найден',
            'order' => 'Заказ', 'order_item' => 'Позиция заказа', 'manual' => 'Вручную',
            'legacy' => 'Старый сайт', 'lesson' => 'Урок', 'guide' => 'Пособие',
            'admin_grant' => 'Выдан администратором', 'migration' => 'Перенос со старого сайта',
            'support_adjustment' => 'Корректировка поддержки', 'test_fixture' => 'Тестовые данные',
            'backend_confirmation' => 'Подтверждение оплаты', 'provider_payment' => 'Через платёжный сервис',
            'settled_zero_cash' => 'Без денежного платежа',
            'profile' => 'Изменение анкеты', 'manager' => 'Назначение менеджера',
            'tags' => 'Изменение меток', 'comment' => 'Комментарий',
            'local_fixture' => 'Создание тестового профиля', 'local_fixture_data' => 'Подготовка тестовых данных',
            'matched' => 'Связан', 'linked' => 'Связан', 'unmatched' => 'Не связан',
            'historical_only' => 'Только история старого сайта',
        ][$value ?? ''] ?? ($value ? 'Неизвестное значение: '.$value : 'Не указано');
    }

    public static function grantStatus(array $grant): string
    {
        if ($grant['effective_active']) {
            return 'Действует';
        }

        return match ($grant['status']) {
            'active' => 'Срок истёк',
            'revoked' => 'Отозван',
            'expired' => 'Срок истёк',
            'pending' => 'Ожидает активации',
            default => 'Не действует · '.self::status($grant['status']),
        };
    }

    public static function schemas(UserDetail $page, array $data): array
    {
        $detail = $data['detail'];
        $schema = fn (array $entries): Schema => Schema::make($page)->components($entries)->columns(['default' => 1, 'md' => 2, 'xl' => 3]);
        $entry = fn (string $key, string $label, mixed $value): TextEntry => TextEntry::make($key)->label($label)->state($value)->placeholder('Не указано');
        $date = fn ($value): string => UsersDisplay::date($value ? (string) $value : null);
        $result['summary'] = $schema([
            $entry('identity', 'ID и роль', $page->userId.' · '.UsersDisplay::role($detail['role'])),
            $entry('created', 'Регистрация', $date($detail['created_at'])),
            $entry('manager', 'Менеджер', $detail['manager']?->name ?? 'Не назначен'),
            $entry('email', 'Email', $detail['email'])->helperText($detail['email_verified_at'] ? 'Подтверждён: '.$date($detail['email_verified_at']) : 'Не подтверждён'),
            $entry('phone', 'Телефон', $detail['phone'])->helperText($detail['phone_verified_at'] ? 'Подтверждён: '.$date($detail['phone_verified_at']) : 'Не подтверждён'),
            $entry('tags', 'Метки', implode(', ', $detail['tags']) ?: 'Нет'),
            $entry('migration', 'Миграция', $detail['migration_hold'] ? 'Вход заблокирован до завершения миграции' : 'Вход доступен')
                ->helperText($detail['legacy_auto_verified'] ? 'Подтверждён при миграции' : null)->columnSpanFull(),
        ]);

        if ($page->tab === 'profile') {
            $result['management'] = Schema::make($page)->columns(['default' => 1, 'lg' => 2])->components([
                Section::make('Назначение менеджера')->visible($data['canAssign'])->schema([
                    Form::make([EmbeddedSchema::make('managerForm')])->id('manager-assignment')->livewireSubmitHandler('saveManager')
                        ->footer([Actions::make([Action::make('saveManager')->label('Сохранить назначение')->submit('managerForm')->formId('manager-assignment')])]),
                ]),
                Section::make('Метки')->visible($data['canEdit'])->schema([
                    Form::make([EmbeddedSchema::make('tagsForm')])->id('user-tags')->livewireSubmitHandler('saveTags')
                        ->footer([Actions::make([Action::make('saveTags')->label('Сохранить метки')->submit('tagsForm')->formId('user-tags')])]),
                ]),
            ]);
            $consent = $detail['consent'];
            $result['consents'] = $schema($consent ? [
                $entry('privacy', 'Политика конфиденциальности', $consent->privacy_policy_version.' · '.$date($consent->privacy_policy_accepted_at)),
                $entry('offer', 'Оферта', $consent->offer_version.' · '.$date($consent->offer_accepted_at)),
                $entry('marketing', 'Рассылки', ($consent->marketing_opt_in ? 'Согласие получено' : 'Согласия нет').' · '.$date($consent->marketing_accepted_at)),
            ] : [$entry('empty', 'Согласия', 'Согласия не зафиксированы.')]);
        } elseif ($page->tab === 'legacy') {
            $legacy = $detail['legacy_profile'];
            $result['legacyInfo'] = $schema([
                $entry('name', 'Имя', $legacy['name']), $entry('email', 'Email', $legacy['email']),
                $entry('phone', 'Телефон', $legacy['phone']),
                $entry('mapping', 'Связь', self::status($legacy['mapping_status']))->helperText('Код: '.$legacy['mapping_status']),
                $entry('created', 'Регистрация', $date($legacy['source_created_at'])),
            ]);
        } elseif ($page->tab === 'orders') {
            $result['ordersInfo'] = self::records($page, array_map(function (array $order) use ($date): array {
                $money = fn (string $key): string => UsersDisplay::legacyMoney((string) $order[$key], $order['currency']);
                return [
                    'title' => 'Заказ № '.$order['id'], 'created' => $date($order['created_at']),
                    'payment' => self::status($order['payment_status']), 'access' => self::status($order['access_status']),
                    'settlement' => self::status($order['settlement_type']),
                    'total' => $money('amount_total'), 'discount' => $money('amount_discount'), 'final' => $money('amount_final'),
                    'paid' => $money('amount_paid'), 'refunded' => $money('amount_refunded'), 'paid_at' => $date($order['paid_at']),
                    'items' => array_map(fn (array $item): string => $item['display_title'].' × '.$item['quantity'].' — '.UsersDisplay::legacyMoney((string) $item['final_amount'], $item['currency']).' · '.self::status($item['access_status']).(($item['tariff'] ?? null) === null ? '' : ' · '.$item['tariff']['label'].' — '.$item['tariff']['supportDescription']), $order['items']),
                    'codes' => implode(' · ', [$order['payment_status'], $order['access_status'], $order['settlement_type']]),
                ];
            }, $data['orders']['data']), [
                'title' => 'Заказ', 'created' => 'Создан', 'payment' => 'Оплата', 'access' => 'Доступ', 'settlement' => 'Расчёт',
                'total' => 'Сумма', 'discount' => 'Скидка', 'final' => 'К оплате', 'paid' => 'Оплачено', 'refunded' => 'Возвращено', 'paid_at' => 'Дата оплаты',
                'items' => 'Состав заказа', 'codes' => 'Коды состояний',
            ], ['total', 'discount', 'paid', 'refunded', 'paid_at', 'codes']);
        } elseif ($page->tab === 'access') {
            $result['accessInfo'] = self::records($page, array_map(fn (array $grant): array => [
                'title' => $grant['title'] ?: self::status($grant['atom_kind']).' № '.$grant['atom_id'],
                'status' => self::grantStatus($grant),
                'content' => $grant['content_active'] ? 'Материал активен' : 'Материал недоступен',
                'granted' => $date($grant['granted_at']), 'expires' => $grant['expires_at'] ? $date($grant['expires_at']) : 'Без ограничения',
                'revoked' => $date($grant['revoked_at']), 'reason' => $grant['revoked_reason'],
                'source' => self::status($grant['source_type']).' № '.$grant['source_id'], 'order' => $grant['order_id'] ?: '—',
                'codes' => $grant['status'].' · '.$grant['source_type'],
            ], $data['access']['data']), [
                'title' => 'Материал', 'status' => 'Доступ', 'content' => 'Публикация', 'granted' => 'Выдан', 'expires' => 'Действует до',
                'revoked' => 'Отозван', 'reason' => 'Причина отзыва', 'source' => 'Источник', 'order' => 'Заказ', 'codes' => 'Коды состояний',
            ], ['granted', 'expires', 'revoked', 'reason', 'source', 'order', 'codes']);
            $result['coursesInfo'] = self::records($page, array_map(fn (array $course): array => [
                'title' => $course['title'], 'access' => self::status($course['access']),
                'required' => $course['required_atoms_count'], 'owned' => $course['owned_atoms_count'], 'codes' => $course['access'],
            ], $data['courses']['data']), ['title' => 'Курс', 'access' => 'Покрытие', 'required' => 'Всего уроков', 'owned' => 'Доступно уроков', 'codes' => 'Код покрытия']);
        } elseif ($page->tab === 'events') {
            $result['eventsInfo'] = self::records($page, array_map(fn (array $event): array => [
                'type' => self::status($event['type']), 'actor' => $event['actor_name'], 'created' => $date($event['created_at']),
                'comment' => filled($event['comment']) ? preg_split('/\R/u', $event['comment']) : [],
                'changes' => self::changes($event['changes'] ?? []), 'codes' => $event['type'],
            ], $data['events']['data']), ['type' => 'Событие', 'actor' => 'Сотрудник', 'created' => 'Дата', 'comment' => 'Комментарий', 'changes' => 'Изменения', 'codes' => 'Код события']);
        }

        return $result;
    }

    private static function records(UserDetail $page, array $rows, array $labels, array $secondary = ['codes']): Schema
    {
        $entries = [];
        $details = [];
        foreach ($labels as $key => $label) {
            $entry = TextEntry::make($key)->label($label)->placeholder('—');
            if (in_array($key, ['items', 'comment', 'changes'], true)) {
                $entry->columnSpanFull();
            }
            if (in_array($key, ['items', 'comment', 'changes'], true)) {
                $entry->listWithLineBreaks();
            }
            if (in_array($key, $secondary, true)) {
                $details[] = $entry;
                continue;
            }
            $entries[] = $entry;
        }
        $entries[] = Section::make(count($secondary) > 1 ? 'Подробности' : 'Служебные данные')->compact()->collapsible()->collapsed()->columnSpanFull()
            ->schema($details)->columns(['default' => 1, 'md' => 2, 'xl' => 3]);

        return Schema::make($page)->constantState(['records' => $rows])->components([
            RepeatableEntry::make('records')->label('Записи')->hiddenLabel()->schema($entries)->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
        ]);
    }

    private static function changes(array $values, string $prefix = ''): array
    {
        $lines = [];
        foreach ($values as $key => $value) {
            $label = $prefix.UsersDisplay::label((string) $key);
            if (is_array($value)) {
                array_push($lines, ...self::changes($value, $label.' → '));
            } else {
                $lines[] = $label.': '.(is_bool($value) ? ($value ? 'Да' : 'Нет') : ($value === null || $value === '' ? '—' : $value));
            }
        }

        return $lines;
    }
}
