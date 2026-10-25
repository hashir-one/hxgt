@extends('layouts.app')
@section('content')
<section class="surface mx-auto max-w-md rounded-2xl p-7"><p class="eyebrow">Account security</p><h1 class="mt-3 text-2xl font-semibold">{{ $secret ? 'Set up your authenticator' : 'Verify your sign-in' }}</h1>
@if($secret)<p class="mt-3 text-sm text-muted">Scan this QR in your authenticator app, keep the setup key in a safe place, then enter the six-digit code.</p><div class="mx-auto mt-5 w-fit bg-white p-3">{!! $qr !!}</div><code class="mt-4 block break-all text-sm">{{ $secret }}</code>@endif
<form class="mt-6" method="post" action="{{ route('security.totp.verify') }}">@csrf<label class="field-label">Authenticator code<input class="field" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus></label><button class="btn-primary mt-5 w-full">Verify and continue</button></form></section>
@endsection
