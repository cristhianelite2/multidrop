@extends('layouts.buyer')

@section('title', 'Reclamo')
@section('heading', $claim->subject)
@section('subheading', ($claim->order?->number ?? '').' · '.$claim->statusLabel())

@section('content')
<div class="buyer-card">
    <p class="buyer-muted" style="margin-top:0">{{ $claim->created_at?->format('d/m/Y H:i') }} · {{ $claim->store?->name }}</p>
    <p style="white-space:pre-wrap">{{ $claim->body }}</p>
</div>
@if(filled($claim->admin_note))
<div class="buyer-card">
    <h2 style="margin:0 0 8px;font-size:1.05rem">Respuesta de la tienda</h2>
    <p style="white-space:pre-wrap">{{ $claim->admin_note }}</p>
</div>
@else
<div class="buyer-card">
    <p class="buyer-muted" style="margin:0">La tienda aún no ha respondido.</p>
</div>
@endif

@include('partials.platform-contact', [
    'title' => '¿Urgente?',
    'intro' => 'También puedes contactarnos por estos canales mientras revisamos tu reclamo.',
    'boxClass' => 'buyer-card',
])

<a class="buyer-btn-secondary" href="{{ route('buyer.claims.index') }}">← Reclamos</a>
@endsection
