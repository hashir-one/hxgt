<?php

namespace App\Console\Commands;

use App\Domain\Payments\EmailPaymentMatchingService;
use App\Models\Merchant;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class PollBankEmail extends Command
{
    protected $signature = 'gateway:poll-bank-email';

    protected $description = 'Poll the configured bank-email inbox and ingest strictly parsed credit alerts';

    public function handle(EmailPaymentMatchingService $matching): int
    {
        if (! config('gateway.email.enabled')) {
            $this->warn('Bank-email confirmation is disabled. Set BANK_EMAIL_CONFIRMATION_ENABLED=true after parser validation.');

            return self::SUCCESS;
        }

        if (! function_exists('imap_open')) {
            $this->error('The PHP IMAP extension is not installed.');

            return self::FAILURE;
        }

        $merchant = Merchant::find(config('gateway.email.merchant_id'));
        if ($merchant === null) {
            $this->error('BANK_EMAIL_MERCHANT_ID does not identify a merchant.');

            return self::FAILURE;
        }

        $username = (string) env('BANK_EMAIL_USERNAME', config('mail.mailers.smtp.username'));
        $password = (string) env('BANK_EMAIL_PASSWORD', config('mail.mailers.smtp.password'));
        $validation = config('gateway.email.imap_validate_certificate') ? '' : '/novalidate-cert';
        $mailbox = sprintf(
            '{%s:%d/imap/%s%s}INBOX',
            config('gateway.email.imap_host'),
            config('gateway.email.imap_port'),
            config('gateway.email.imap_encryption'),
            $validation,
        );
        $stream = @imap_open($mailbox, $username, $password);
        if ($stream === false) {
            $this->error('Could not connect to the bank-email inbox.');

            return self::FAILURE;
        }

        $messageNumbers = imap_search($stream, 'UNSEEN') ?: [];
        $processed = 0;
        foreach ($messageNumbers as $messageNumber) {
            try {
                $overview = imap_fetch_overview($stream, $messageNumber, 0)[0] ?? null;
                $headers = imap_fetchheader($stream, $messageNumber);
                $body = imap_body($stream, $messageNumber, FT_PEEK);
                $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '';
                $sender = $this->extractEmail(($overview->from ?? '').' '.$headers);
                $amount = $this->extractAmount($subject.' '.$body);
                $reference = $this->extractReference($subject.' '.$body);

                if ($sender === null || $amount === null || $reference === null) {
                    $this->line("Skipped message {$messageNumber}: required parsed fields were not found.");
                    imap_setflag_full($stream, (string) $messageNumber, '\\Seen');

                    continue;
                }

                $messageId = $this->extractMessageId($headers) ?? 'imap-'.$messageNumber.'-'.($overview->date ?? now()->toIso8601String());
                $result = $matching->ingest($merchant, [
                    'message_id' => $messageId,
                    'sender_email' => $sender,
                    'subject' => $subject,
                    'amount_minor' => $amount,
                    'reference_number' => $reference,
                    'occurred_at' => isset($overview->date) ? Carbon::parse($overview->date) : now(),
                    'authentication' => $this->authentication($headers),
                ]);
                $this->line("Message {$messageNumber}: {$result['status']}.");
                imap_setflag_full($stream, (string) $messageNumber, '\\Seen');
                $processed++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Message {$messageNumber} failed safely: {$exception->getMessage()}");
            }
        }

        imap_close($stream);
        $this->info("Processed {$processed} bank-email message(s).");

        return self::SUCCESS;
    }

    private function extractEmail(string $value): ?string
    {
        return preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $matches) ? strtolower($matches[0]) : null;
    }

    private function extractAmount(string $value): ?int
    {
        if (! preg_match('/(?:INR|Rs\.?|₹)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $value, $matches)) {
            return null;
        }

        try {
            return Money::parseInr($matches[1]);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractReference(string $value): ?string
    {
        if (preg_match('/(?:UTR|UPI(?:\s+reference)?|reference(?:\s+number)?|transaction(?:\s+id)?)\D{0,30}(\d{12})/i', $value, $matches)) {
            return $matches[1];
        }

        return preg_match('/\b(\d{12})\b/', $value, $matches) ? $matches[1] : null;
    }

    private function extractMessageId(string $headers): ?string
    {
        return preg_match('/^Message-ID:\s*<([^>]+)>/mi', $headers, $matches) ? $matches[1] : null;
    }

    /**
     * @return array{spf:bool,dkim:bool,dmarc:bool}
     */
    private function authentication(string $headers): array
    {
        $normalized = strtolower($headers);

        return [
            'spf' => str_contains($normalized, 'spf=pass'),
            'dkim' => str_contains($normalized, 'dkim=pass'),
            'dmarc' => str_contains($normalized, 'dmarc=pass'),
        ];
    }
}
