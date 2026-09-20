@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The shop's own logo, not Laravel's. Referenced by absolute URL because
     an email client fetches it from the internet, not from the app. --}}
@php($mark = public_path('images/brand/logo-email.png'))
@if (file_exists($mark))
<img src="{{ asset('images/brand/logo-email.png') }}" class="logo" alt="{{ config('app.name') }}">
@else
<span class="wordmark">{{ config('app.name') }}</span>
@endif
</a>
</td>
</tr>
