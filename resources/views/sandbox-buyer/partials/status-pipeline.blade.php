{{--
  Pipeline: título arriba → círculo → fecha abajo.
  Vars: $steps (list), $compact (bool)
--}}
@php
    $compact = $compact ?? false;
@endphp
<div class="md-pipe {{ $compact ? 'md-pipe--compact' : '' }}" role="list" aria-label="{{ __('buyer.tracking.heading') }}">
    @foreach($steps as $step)
        @php
            $state = $step['state'];
            $cls = match ($state) {
                'done' => 'is-done',
                'current' => 'is-current',
                'error' => 'is-error',
                'warn' => 'is-warn',
                default => 'is-todo',
            };
            $dateLabel = $step['date'] ?? null;
        @endphp
        <div class="md-pipe__step {{ $cls }}" role="listitem" title="{{ $step['label'] }} — {{ $step['hint'] }}">
            <div class="md-pipe__title">{{ $step['label'] }}</div>
            <div class="md-pipe__status">{{ $step['hint'] }}</div>
            <div class="md-pipe__icon" aria-hidden="true">
                @include('sandbox-buyer.partials.status-icon', ['icon' => $step['icon'], 'state' => $state])
            </div>
            <div class="md-pipe__date {{ $dateLabel ? '' : 'is-empty' }}">
                @if($dateLabel)
                    <time datetime="{{ $step['date_iso'] ?? '' }}">{{ $dateLabel }}</time>
                @else
                    <span>—</span>
                @endif
            </div>
            @if(! $loop->last)
                <div class="md-pipe__line {{ $state === 'done' ? 'is-done' : '' }}" aria-hidden="true"></div>
            @endif
        </div>
    @endforeach
</div>
