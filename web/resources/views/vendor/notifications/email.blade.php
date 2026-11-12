{{--
    Laravel's notification mail, with one change: greeting, lines, button label
    and salutation go through MailText::literal, so the names people choose
    (their own, their team's, a token's) stay text instead of turning into
    Markdown links. See app/Support/MailText.php.
--}}
@use('App\Support\MailText')
<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ MailText::literal($greeting) }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ MailText::literal($line) }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ MailText::literal($actionText) }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ MailText::literal($line) }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ MailText::literal($salutation) }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
