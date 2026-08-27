@extends('layouts.buyer')

@section('title', 'Reclamo')
@section('heading', $claim['subject'] ?? 'Reclamo')
@section('subheading', ($claim['order_number'] ?? '').' · '.$theme->name)

@section('content')
<div class="buyer-card">
    <p class="buyer-muted" style="margin-top:0">Estado: <strong>{{ $claim['status'] ?? 'open' }}</strong> · {{ $claim['created_at'] ?? '' }}</p>
    <p style="white-space:pre-wrap">{{ $claim['body'] ?? '' }}</p>
    <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.claims', $theme->slug) }}">← Volver</a>
</div>
@endsection
