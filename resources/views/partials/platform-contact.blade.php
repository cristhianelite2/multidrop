{{-- Contacto global de plataforma --}}
@php
    $pc = $platformContact ?? app(\App\Services\Platform\PlatformContact::class)->all();
    $pcSvc = app(\App\Services\Platform\PlatformContact::class);
@endphp
@if($pcSvc->hasAny())
<div class="{{ $boxClass ?? 'md-platform-contact' }}" style="{{ $boxStyle ?? '' }}">
    @if(!empty($title))
        <h3 style="margin:0 0 8px;font-size:1rem">{{ $title }}</h3>
    @endif
    @if(!empty($intro))
        <p class="muted" style="margin:0 0 10px;{{ isset($mutedStyle) ? $mutedStyle : 'color:#64748b;font-size:14px' }}">{{ $intro }}</p>
    @endif
    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px;font-size:14px">
        @if(!empty($pc['email']))
            <li><strong>Email:</strong> <a href="mailto:{{ $pc['email'] }}" style="color:#0f766e">{{ $pc['email'] }}</a></li>
        @endif
        @if(!empty($pc['phone']))
            <li><strong>Teléfono:</strong> <a href="tel:{{ preg_replace('/\s+/', '', $pc['phone']) }}" style="color:#0f766e">{{ $pc['phone'] }}</a></li>
        @endif
        @if(!empty($pc['whatsapp']))
            <li><strong>WhatsApp:</strong> <a href="{{ $pcSvc->whatsappUrl() }}" target="_blank" rel="noopener" style="color:#0f766e">{{ $pc['whatsapp'] }}</a></li>
        @endif
        @if(!empty($pc['hours']))
            <li><strong>Horario:</strong> {{ $pc['hours'] }}</li>
        @endif
    </ul>
    @if(!empty($pc['note']))
        <p style="margin:10px 0 0;font-size:13px;color:#475569">{{ $pc['note'] }}</p>
    @endif
</div>
@endif
