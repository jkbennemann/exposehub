@props(['url'])
{{-- The wordmark: a short yellow cable and the name, as in the app. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="cable"></span>{!! $slot !!}
</a>
</td>
</tr>
