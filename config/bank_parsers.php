<?php

$amount = '(?:0|[1-9][0-9]{0,8})\.[0-9]{2}';
$account = '[Xx]{0,4}[0-9]{4}';
$party = '[^\r\n]{1,120}';

return [
    // Message-body templates only. Sender/package/signing identities are not inferred.
    // RE2 syntax, no delimiters; amount/reference extractors each expose group 1.
    'kotak_sms' => [
        'schema' => 'hx-credit-re2-v1',
        'amount_pattern' => '(?i)\AReceived Rs\.('.$amount.') ',
        'reference_pattern' => '(?i)\bUPI (?:ref no\. |ref:)([0-9]{12})(?:\.|\z)',
        'credit_pattern' => '(?i)\AReceived Rs\.'.$amount.' (?:from '.$party.' in your Kotak811 a/c '.$account.' on [0-9]{2}-[A-Za-z]{3}-[0-9]{2}\. UPI ref no\. [0-9]{12}\.(?: View balance: https://kotk\.in/[A-Za-z0-9/_-]{1,120} -Kotak)?|in your Kotak Bank AC '.$account.' from '.$party.' on [0-9]{2}-[0-9]{2}-[0-9]{2}\.UPI Ref:[0-9]{12}\.?)\z',
        'reject_pattern' => '(?i)\b(?:debited|reversed|reversal|refunded|failed|declined|OTP|collect request)\b',
    ],
];
