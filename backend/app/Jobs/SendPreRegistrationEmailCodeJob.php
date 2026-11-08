<?php

namespace App\Jobs;

use App\Services\SmtpBzApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPreRegistrationEmailCodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $email,
        private readonly string $code,
        private readonly int $ttlMinutes
    ) {}

    public function handle(SmtpBzApiClient $smtpBzApiClient): void
    {
        $subject = 'Подтверждение email';
        $title = 'Код для подтверждения email';
        $html = '<p>'.$title.': <b>'.$this->code.'</b></p><p>Код действует '.$this->ttlMinutes.' минут.</p>';

        if ($smtpBzApiClient->sendHtmlMessage($this->email, $subject, $html)) {
            return;
        }

        try {
            Mail::raw(
                "{$title}: {$this->code}\nКод действует {$this->ttlMinutes} минут.",
                function ($message) use ($subject): void {
                    $message->to($this->email)->subject($subject);
                }
            );
        } catch (Throwable $e) {
            Log::warning('mail.pre_registration_email_code.dispatch_failed', [
                'exception' => $e::class,
            ]);
        }
    }
}
