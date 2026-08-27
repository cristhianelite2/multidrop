@php $icon = $icon ?? 'check'; @endphp
<svg class="md-ico" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($icon)
    @case('check')
        <circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>
        @break
    @case('box')
        <path d="M21 8l-9-4-9 4 9 4 9-4z"/><path d="M3 8v8l9 4 9-4V8"/><path d="M12 12v8"/>
        @break
    @case('warehouse')
        <path d="M3 21V9l9-5 9 5v12"/><path d="M9 21v-6h6v6"/><path d="M3 9h18"/>
        @break
    @case('truck')
        <path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7V10z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>
        @break
    @case('home')
        <path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>
        @break
    @default
        <circle cx="12" cy="12" r="9"/>
@endswitch
</svg>
