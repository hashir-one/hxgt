<?php

return [
    'enabled' => (bool) env('ANDROID_ATTESTATION_ENABLED', false),
    'staging_identity_enabled' => (bool) env('ANDROID_ATTESTATION_STAGING_IDENTITY_ENABLED', false),
    'staging_package' => env('ANDROID_ATTESTATION_STAGING_PACKAGE'),
    'staging_signer_sha256' => env('ANDROID_ATTESTATION_STAGING_SIGNER_SHA256'),
    // Release never falls back to staging, including with cached configuration.
    'release_package' => env('ANDROID_ATTESTATION_RELEASE_PACKAGE'),
    'release_signer_sha256' => env('ANDROID_ATTESTATION_RELEASE_SIGNER_SHA256'),
    'min_os_version' => env('ANDROID_ATTESTATION_MIN_OS_VERSION'),
    'min_os_patch' => env('ANDROID_ATTESTATION_MIN_OS_PATCH'),
    'min_vendor_patch' => env('ANDROID_ATTESTATION_MIN_VENDOR_PATCH'),
    'min_boot_patch' => env('ANDROID_ATTESTATION_MIN_BOOT_PATCH'),
    'max_patch_age_months' => env('ANDROID_ATTESTATION_MAX_PATCH_AGE_MONTHS'),
    // Only APP_ENV=staging may use this exception; never a release fallback.
    'staging_vendor_patch_exception' => [
        'enabled' => (bool) env('ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_ENABLED', false),
        'spki_sha256' => env('ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_SPKI_SHA256'),
        'min_vendor_patch' => env('ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_MIN_PATCH'),
        'max_age_months' => env('ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_MAX_AGE_MONTHS'),
    ],
    'trust_max_age_seconds' => 86400,
];
