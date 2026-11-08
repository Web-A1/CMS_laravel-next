<?php

namespace App\Services\Support;

use App\Models\SupportMessage;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class SupportSmtp
{
    public function send(SupportMessage $message): void
    {
        if (! config('mail.mailers.support.host')) {
            throw new SupportSmtpFailure('NOT_CONFIGURED', false);
        }
        try {
            Mail::mailer('support')->raw($message->body, function (Message $mail) use ($message): void {
                $address = config('support.address');
                $mail->from($address, 'Поддержка школы')->replyTo($address)->to($message->ticket->contact_email);
                $mail->subject('['.$message->ticket->public_number.'] '.$message->ticket->subject);
                $headers = $mail->getSymfonyMessage()->getHeaders();
                $headers->addIdHeader('Message-ID', $message->message_id);
                $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                foreach (['in_reply_to' => 'In-Reply-To', 'references' => 'References'] as $key => $name) {
                    if ($value = $message->headers[$key] ?? null) {
                        $headers->addIdHeader($name, $value);
                    }
                }
            });
        } catch (TransportExceptionInterface $e) {
            // A definite negative SMTP reply proves rejection. A disconnect/timeout may follow DATA acceptance.
            $code = (int) $e->getCode();
            if (preg_match('/Connection (?:could not be established|refused)|Unable to connect|Failed to connect/i', $e->getMessage())) {
                throw new SupportSmtpFailure('SMTP_CONNECTION_REFUSED', true, previous: $e);
            }
            if ($code >= 400 && $code < 600) {
                throw new SupportSmtpFailure($code < 500 ? 'SMTP_TEMPORARY_REJECTION' : 'SMTP_PERMANENT_REJECTION', $code < 500, previous: $e);
            }
            throw new SupportSmtpFailure('SMTP_OUTCOME_UNKNOWN', false, true, $e);
        }
    }
}
