@php
    $title = $title ?? 'Cómo obtener las llaves';
    $steps = $steps ?? [];
@endphp
<details class="mt-3 rounded-xl border border-dashed border-line bg-white/60 px-3 py-2 text-sm">
    <summary class="cursor-pointer select-none font-medium text-ink">{{ $title }}</summary>
    <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs leading-relaxed text-ink-soft/80">
        @foreach($steps as $step)
            <li>{!! $step !!}</li>
        @endforeach
    </ol>
</details>
