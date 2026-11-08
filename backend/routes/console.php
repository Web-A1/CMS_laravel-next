<?php

use App\Services\ProtectedFiles\ProtectedFileOutputRemoval;
use App\Services\Reviews\ReviewImageAssets;
use App\Services\Reviews\ReviewImageProcessor;
use App\Services\Support\SupportMailbox;
use App\Services\Support\SupportRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:cleanup-pre-registration-verifications --days=1')->dailyAt('03:10');
Schedule::command('app:cleanup-video-watermark-sessions')->dailyAt('03:20');

Artisan::command('reviews:cleanup-images', function (ReviewImageAssets $assets): void {
    $this->info('Удалено неиспользуемых наборов: '.$assets->cleanup());
})->purpose('Повторить удаление снятых изображений и убрать неиспользованные загрузки старше суток');
Schedule::command('reviews:cleanup-images')->hourly()->withoutOverlapping();

Artisan::command('support:recover-work', function (SupportRecovery $recovery): void {
    $recovery->run();
    $this->info('Восстановление очереди поддержки выполнено.');
});
Artisan::command('support:activate {--confirm : Сохранить показанную границу и включить приём новых писем}', function (SupportMailbox $mailbox): int {
    $result = $mailbox->activation((bool) $this->option('confirm'), function (array $preview): void {
        $this->line('Ящик: '.$preview['mailbox']);
        $this->line('Папка: '.$preview['folder']);
        $this->line('UIDVALIDITY: '.$preview['uidvalidity']);
        $this->line('Граница чтения (UIDNEXT − 1): '.$preview['baseline_uid']);
        $this->line('Писем в папке: '.$preview['existing_messages']);
    });
    if ($result['status'] === 'preview') {
        $this->info('Активация не выполнена. Для подтверждения: php artisan support:activate --confirm');

        return 0;
    }
    if ($result['status'] === 'active') {
        $this->info('Приём включён. Сохранена граница UID '.$result['baseline_uid'].' при UIDVALIDITY '.$result['uidvalidity'].'. Исторические письма не импортированы.');

        return 0;
    }
    $this->line('Ящик: '.$result['mailbox']);
    $this->line('Папка: '.$result['folder']);
    if ($result['status'] === 'already_active') {
        $this->info('Приём уже активирован. Граница UID '.$result['baseline_uid'].' при UIDVALIDITY '.$result['uidvalidity'].' сохранена без изменений.');

        return 0;
    }
    $this->warn(match ($result['status']) {
        'disabled' => 'Приём писем выключен. Активация не выполнена.',
        'not_configured' => 'Приём писем не подключён: заполните настройки IMAP. Активация не выполнена.',
        'busy' => 'С ящиком уже выполняется другая операция. Повторите позже.',
        'invalid_metadata' => 'Сервер не подтвердил границу чтения или число писем. Активация не выполнена.',
        default => 'Не удалось подключиться к почте. Активация не выполнена.',
    });

    return 1;
});
Artisan::command('support:poll', function (SupportMailbox $mailbox): void {
    $this->info($mailbox->run());
});
Artisan::command('support:recover-mailbox', function (SupportMailbox $mailbox): void {
    $this->info($mailbox->run(recover: true));
});
Artisan::command('support:health', function (SupportRecovery $recovery): void {
    $this->line(json_encode($recovery->health(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
});
Schedule::command('support:recover-work')->everyMinute()->withoutOverlapping();
Schedule::command('support:poll')->everyMinute()->withoutOverlapping();

Artisan::command('protected-files:cleanup-outputs', function (ProtectedFileOutputRemoval $removal): void {
    $this->info('Удалено персональных PDF: '.$removal->retry());
})->purpose('Повторить отложенное удаление персональных PDF');
Schedule::command('protected-files:cleanup-outputs')->hourly()->withoutOverlapping();

Artisan::command('reviews:check-image-runtime', function (ReviewImageProcessor $processor): void {
    $processor->checkRuntime();
    $this->info('ImageMagick: JPEG/PNG/HEIC read, AVIF/WebP read/write доступны.');
})->purpose('Проверить декодеры и кодировщики изображений отзывов');
