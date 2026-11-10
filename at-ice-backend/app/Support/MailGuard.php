<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

// No email ever goes to an address ending in .invalid (client, 2026-11-11):
// the imported route customers have dummy logins such as
// alfamart-gayagaya@atice.invalid, and mail to them would bounce at Resend.
// Listens to every message (AppServiceProvider): .invalid recipients are
// dropped, and a message left with nobody to send to is not sent at all.
// Mail to real addresses is unchanged.
class MailGuard
{
    public static function isUndeliverable(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && str_ends_with($email, '.invalid');
    }

    public static function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! $message instanceof Email) {
            return null;
        }

        $dropped = [];
        $keep = function (array $addresses) use (&$dropped) {
            return array_values(array_filter($addresses, function (Address $a) use (&$dropped) {
                if (self::isUndeliverable($a->getAddress())) {
                    $dropped[] = $a->getAddress();

                    return false;
                }

                return true;
            }));
        };
        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());
        if (! $dropped) {
            return null;
        }

        Log::info('Email not sent to '.implode(', ', $dropped).' (.invalid address).');
        if (! $to && ! $cc && ! $bcc) {
            return false; // nobody left: the message is not sent
        }
        $message->to(...$to)->cc(...$cc)->bcc(...$bcc);

        return null;
    }
}
