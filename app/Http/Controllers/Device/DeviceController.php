<?php

namespace App\Http\Controllers\Device;

use App\Domain\Devices\AttestationVerifier;
use App\Domain\Devices\GatewayHealthService;
use App\Domain\Payments\PaymentMatchingService;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\ParserRule;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function pair(Request $request, AuditLogger $audit, AttestationVerifier $attestation)
    {
        $payload = $request->validate([
            'pairing_token' => ['required', 'string'],
            'public_key' => ['required', 'string', 'max:8192'],
            'attestation_certificate_chain' => ['nullable', 'array', 'max:8'],
            'attestation_certificate_chain.*' => ['string', 'max:16384'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array:label,model,android_version'],
            'metadata.label' => ['nullable', 'string', 'max:80'],
            'metadata.model' => ['nullable', 'string', 'max:80'],
            'metadata.android_version' => ['nullable', 'string', 'max:20'],
        ]);

        $pairing = DevicePairing::query()
            ->where('token_hash', hash('sha256', $payload['pairing_token']))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($pairing === null || $pairing->approval_status !== 'approved') {
            return response()->json(['error' => 'PAIRING_NOT_AVAILABLE'], 422);
        }

        $rawKey = base64_decode($payload['public_key'], true);
        $pemKey = str_contains($payload['public_key'], 'BEGIN PUBLIC KEY') ? openssl_pkey_get_public($payload['public_key']) : false;
        abort_unless($pemKey !== false || ($rawKey !== false && strlen($rawKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES), 422, 'Invalid device public key');
        $attestationStatus = $attestation->status($payload['attestation_certificate_chain'] ?? null);
        $attestationDigest = isset($payload['attestation_certificate_chain'])
            ? hash('sha256', json_encode($payload['attestation_certificate_chain'], JSON_THROW_ON_ERROR))
            : null;

        $device = DB::transaction(function () use ($pairing, $payload, $attestationStatus, $attestationDigest): Device {
            $merchant = Merchant::whereKey($pairing->merchant_id)->lockForUpdate()->firstOrFail();
            $pairing = DevicePairing::query()->lockForUpdate()->findOrFail($pairing->id);
            if ($pairing->consumed_at !== null || $pairing->expires_at->isPast() || $pairing->approval_status !== 'approved') {
                abort(422, 'PAIRING_NOT_AVAILABLE');
            }

            abort_if($merchant->status !== 'active', 422, 'MERCHANT_SUSPENDED');
            if ($pairing->replaces_device_id) {
                $old = Device::where('merchant_id', $merchant->id)->where('slot_number', $pairing->slot_number)->lockForUpdate()->findOrFail($pairing->replaces_device_id);
                abort_if($old->revoked_at !== null, 422, 'MIGRATION_DEVICE_REVOKED');
                $old->update(['revoked_at' => now(), 'status' => 'revoked']);
                app(AuditLogger::class)->record('device.migrated', $merchant->id, Device::class, $old->id);
            }
            abort_if(
                Device::query()
                    ->where('merchant_id', $merchant->id)
                    ->where('slot_number', $pairing->slot_number)
                    ->whereNull('revoked_at')
                    ->exists(),
                422,
                'DEVICE_SLOT_IN_USE',
            );
            abort_if(Device::query()->where('public_key', $payload['public_key'])->exists(), 422, 'DEVICE_KEY_ALREADY_REGISTERED');

            $device = Device::create([
                'merchant_id' => $merchant->id,
                'slot_number' => $pairing->slot_number,
                'label' => $payload['metadata']['label'] ?? null,
                'public_key' => $payload['public_key'],
                'attestation_status' => $attestationStatus,
                'attestation_digest' => $attestationDigest,
                'metadata' => array_merge($payload['metadata'] ?? [], ['app_version' => $payload['app_version'] ?? null]),
            ]);
            $pairing->update(['consumed_at' => now(), 'approval_status' => 'consumed']);

            return $device;
        });

        $audit->record('device.paired', $device->merchant_id, Device::class, $device->id, metadata: ['device_id' => $device->public_id]);

        return response()->json(['device_id' => $device->public_id, 'status' => $device->status], 201);
    }

    public function heartbeat(Request $request, GatewayHealthService $health)
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $payload = $request->validate([
            'observed_at' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(2)->toIso8601String(), 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
            'sms_listener_healthy' => ['required', 'boolean'],
            'notification_listener_healthy' => ['required', 'boolean'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'parser_rule_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($device, $payload, $health) {
            Merchant::whereKey($device->merchant_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_if($device->revoked_at !== null, 401, 'Device revoked');
            $healthy = $payload['sms_listener_healthy'] && $payload['notification_listener_healthy'];
            $settings = $health->settings($device->merchant);
            $stale = ! $device->last_heartbeat_at || $device->last_heartbeat_at->lt(now()->subSeconds($settings->heartbeat_timeout_seconds));
            $streak = $healthy ? min(255, ($stale ? 0 : $device->consecutive_good_heartbeats) + 1) : 0;
            $device->update([
                'last_heartbeat_at' => now(),
                'sms_listener_healthy' => $payload['sms_listener_healthy'],
                'notification_listener_healthy' => $payload['notification_listener_healthy'],
                'consecutive_good_heartbeats' => $streak,
                'status' => $device->attestation_status !== 'verified'
                    ? 'pending_review'
                    : ($healthy && ($streak >= $settings->healthy_heartbeats_to_resume)
                        ? 'online'
                        : ($healthy ? 'offline' : 'degraded')),
                'metadata' => array_merge($device->metadata ?? [], [
                    'app_version' => $payload['app_version'] ?? null,
                    'parser_rule_version' => $payload['parser_rule_version'] ?? null,
                ]),
            ]);

            return response()->json(['status' => $device->status, 'gateway_open' => $health->canAcceptOrders($device->merchant), 'alerts' => DB::table('operational_alerts')->where('merchant_id', $device->merchant_id)->whereNull('resolved_at')->latest()->limit(10)->get(['id', 'type', 'message'])]);
        });
    }

    public function signals(Request $request, PaymentMatchingService $matching)
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        if ($device->attestation_status !== 'verified') {
            return response()->json(['error' => 'DEVICE_ATTESTATION_REQUIRED'], 403);
        }

        $payload = $request->validate([
            'signals' => ['required', 'array', 'max:50'],
            'signals.*.client_signal_id' => ['required', 'string', 'max:128'],
            'signals.*.source' => ['required', 'in:sms,notification'],
            'signals.*.amount_minor' => ['required', 'integer', 'min:1'],
            'signals.*.reference_number' => ['nullable', 'digits:12'],
            'signals.*.occurred_at' => ['required', 'date'],
            'signals.*.captured_at' => ['nullable', 'date'],
            'signals.*.parser_rule_version' => ['nullable', 'integer', 'min:1'],
            'signals.*.source_metadata' => ['required', 'array:sender_id,package_name'],
            'signals.*.source_metadata.sender_id' => ['nullable', 'string', 'max:20'],
            'signals.*.source_metadata.package_name' => ['nullable', 'string', 'max:150'],
        ]);

        $results = [];
        foreach ($payload['signals'] as $signal) {
            $rule = ParserRule::where('status', 'active')->where('version', $signal['parser_rule_version'] ?? 0)->first();
            $metadata = $signal['source_metadata'];
            $valid = $rule && ($signal['source'] === 'sms'
                ? preg_match('/^[A-Z]{2}-KOTAKB(?:-[A-Z])?$/', $metadata['sender_id'] ?? '') === 1 && in_array($metadata['sender_id'], $rule->rules['sender_ids'] ?? [], true)
                : in_array($metadata['package_name'] ?? '', $rule->rules['notification_packages'] ?? [], true));
            if (! $valid) {
                return response()->json(['error' => 'BANK_SOURCE_NOT_VERIFIED'], 422);
            }
            if ($this->containsRawSignalData($signal['source_metadata'] ?? [])) {
                return response()->json(['error' => 'RAW_SIGNAL_DATA_NOT_ALLOWED'], 422);
            }

            $results[] = $matching->ingest($device, $signal);
        }

        return response()->json(['data' => $results], 202);
    }

    public function faults(Request $request, GatewayHealthService $health, AuditLogger $audit)
    {
        abort_unless(array_diff(array_keys($request->all()), ['client_fault_id', 'type', 'source', 'parser_rule_version', 'occurred_at']) === [], 422, 'Only structured fault fields are accepted');
        $data = $request->validate([
            'client_fault_id' => ['required', 'uuid'],
            'type' => ['required', 'in:parser_failure,upload_error,sync_error'],
            'source' => ['required', 'in:sms,notification,device'],
            'parser_rule_version' => ['nullable', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ]);
        $authenticated = $request->attributes->get('device');

        return DB::transaction(function () use ($authenticated, $data, $health, $audit) {
            $merchant = Merchant::whereKey($authenticated->merchant_id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $device->revoked_at && $device->attestation_status === 'verified', 403);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = DB::table('device_fault_reports')->where('device_id', $device->id)->where('client_fault_id', $data['client_fault_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'FAULT_ID_REUSED');

                return response()->json(['accepted' => true, 'duplicate' => true, 'report_id' => $existing->id]);
            }
            $id = DB::table('device_fault_reports')->insertGetId($data + ['device_id' => $device->id, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $health->recordFault($merchant, $data['type'], ['device_id' => $device->id, 'report_id' => $id, 'source' => $data['source']]);
            $audit->record('device.fault_reported', $merchant->id, Device::class, $device->id, metadata: ['report_id' => $id, 'type' => $data['type']]);

            return response()->json(['accepted' => true, 'duplicate' => false, 'report_id' => $id], 201);
        });
    }

    public function parserRules()
    {
        $rule = ParserRule::query()->where('status', 'active')->latest('version')->first();

        return response()->json($rule ? [
            'version' => $rule->version,
            'source' => $rule->source,
            'rules' => $rule->rules,
        ] : ['version' => null, 'source' => 'kotak', 'rules' => []]);
    }

    private function containsRawSignalData(array $metadata): bool
    {
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), ['body', 'content', 'message', 'raw', 'sms', 'notification', 'text'], true)) {
                return true;
            }

            if (is_array($value) && $this->containsRawSignalData($value)) {
                return true;
            }
        }

        return false;
    }
}
