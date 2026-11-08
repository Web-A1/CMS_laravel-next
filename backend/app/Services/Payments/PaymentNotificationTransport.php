<?php

namespace App\Services\Payments;

use DomainException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class PaymentNotificationTransport
{
    public function send(array $payload): string
    {
        $sent = Mail::mailer($payload['mailer'])->raw($payload['body'], function (Message $message) use ($payload): void {
            $message->to($payload['recipient'])->from($payload['sender'], 'Christmedschool')->subject($payload['subject']);
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $payload['message_id']);
        });
        if ($sent === null) {
            throw new DomainException('PAYMENT_NOTIFICATION_ACCEPTANCE_UNKNOWN');
        }

        return $sent->getSymfonySentMessage()->getMessageId();
    }
}
