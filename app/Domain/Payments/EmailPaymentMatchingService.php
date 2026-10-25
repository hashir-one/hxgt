<?php

namespace App\Domain\Payments;

use App\Models\EmailPaymentSignal;
use App\Models\Merchant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class EmailPaymentMatchingService
{
    /**
     * @param  array{message_id:string,sender_email:string,subject?:string,amount_minor:int,reference_number:string,occurred_at:string|\DateTimeInterface,authentication:array<string,bool>,parser_rule_version?:int}  $data
     * @return array{accepted:bool,status:string,reason?:string,signal_id?:int,order_id?:string}
     */
    public function ingest(Merchant $merchant, array $data): array
    {
        if (! config('gateway.email.enabled')) {
            return ['accepted' => false, 'status' => 'rejected', 'reason' => 'email_confirmation_disabled'];
        }

        $reference = Money::assertReference($data['reference_number']);
        $amount = (int) $data['amount_minor'];
        $sender = strtolower(trim($data['sender_email']));
        $allowedSenders = array_map('strtolower', config('gateway.email.sender_allowlist', []));
        $authentication = $data['authentication'] ?? [];

        if ($amount < config('gateway.email.minimum_amount_minor')) {
            return ['accepted' => false, 'status' => 'rejected', 'reason' => 'email_amount_below_minimum'];
        }
        if ($allowedSenders === [] || ! in_array($sender, $allowedSenders, true)) {
            return ['accepted' => false, 'status' => 'rejected', 'reason' => 'sender_not_allowlisted'];
        }
        if (! ($authentication['spf'] ?? false) || ! ($authentication['dkim'] ?? false) || ! ($authentication['dmarc'] ?? false)) {
            return ['accepted' => false, 'status' => 'rejected', 'reason' => 'email_authentication_required'];
        }

        return DB::transaction(function () use ($merchant, $data, $reference, $amount, $sender, $authentication): array {
            $messageHash = hash('sha256', $data['message_id']);
            $signal = EmailPaymentSignal::query()->where('message_id_hash', $messageHash)->first();
            if ($signal !== null) {
                return ['accepted' => true, 'status' => 'duplicate', 'reason' => 'message_already_seen', 'signal_id' => $signal->id];
            }

            $signal = EmailPaymentSignal::create([
                'merchant_id' => $merchant->id,
                'message_id_hash' => $messageHash,
                'sender_email' => $sender,
                'subject_hash' => isset($data['subject']) ? hash('sha256', $data['subject']) : null,
                'amount_minor' => $amount,
                'reference_number_normalized' => $reference,
                'reference_number_hash' => Money::referenceHash($reference),
                'occurred_at' => $data['occurred_at'],
                'authentication' => $authentication,
                'parser_rule_version' => $data['parser_rule_version'] ?? null,
                'status' => 'received',
            ]);

            // Email is supplementary evidence, never a substitute for SMS + notification.
            $signal->update(['status' => 'needs_review', 'failure_reason' => 'two_bank_sources_required']);

            return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'two_bank_sources_required', 'signal_id' => $signal->id];
        });
    }
}
