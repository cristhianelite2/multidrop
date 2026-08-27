@php
    /** @var \App\Models\Order $order */
    $compact = $compact ?? false;
    $payment = strtolower((string) ($order->payment_status ?? 'pending'));
    $fulfillment = strtolower((string) ($order->fulfillment_status ?? 'unfulfilled'));
    $orderStatus = strtolower((string) ($order->status ?? 'pending'));
    $fulfillments = $order->fulfillments ?? collect();
    $firstFulfillment = $fulfillments->first();
    $lastFulfillment = $fulfillments->sortByDesc('id')->first();

    $isCancelled = in_array($orderStatus, ['cancelled', 'canceled'], true)
        || in_array($payment, ['failed', 'rejected', 'cancelled', 'canceled'], true)
        || $fulfillment === 'error';
    $isPaid = in_array($payment, ['paid', 'approved', 'completed'], true);
    $isDelivered = in_array($fulfillment, ['delivered', 'completed'], true);
    $isShipped = in_array($fulfillment, ['shipped', 'in_transit'], true) || $isDelivered;
    $isPreparing = in_array($fulfillment, ['submitted', 'processing', 'manual', 'skipped', 'unfulfilled'], true) || $isShipped;

    $createdIso = optional($order->created_at)->toIso8601String();
    $createdLabel = optional($order->created_at)->format('d/m/Y H:i');

    $paidIso = $isPaid ? optional($order->updated_at)->toIso8601String() : null;
    $paidLabel = $isPaid ? optional($order->updated_at)->format('d/m/Y H:i') : null;

    $prepIso = $isPreparing ? optional($firstFulfillment?->created_at)->toIso8601String() : null;
    $prepLabel = $isPreparing && $firstFulfillment?->created_at ? $firstFulfillment->created_at->format('d/m/Y H:i') : null;

    $shipIso = $isShipped ? optional($lastFulfillment?->updated_at)->toIso8601String() : null;
    $shipLabel = $isShipped && $lastFulfillment?->updated_at ? $lastFulfillment->updated_at->format('d/m/Y H:i') : null;

    $deliveryIso = $isDelivered ? optional($lastFulfillment?->updated_at)->toIso8601String() : null;
    $deliveryLabel = $isDelivered && $lastFulfillment?->updated_at ? $lastFulfillment->updated_at->format('d/m/Y H:i') : null;

    $steps = [
        [
            'label' => 'Compra realizada',
            'hint' => 'Hecho',
            'icon' => 'check',
            'state' => 'done',
            'date' => $createdLabel,
            'date_iso' => $createdIso,
        ],
        [
            'label' => 'Pago validado',
            'hint' => $isCancelled ? 'Pendiente' : ($isPaid ? 'Hecho' : 'En trámite'),
            'icon' => 'check',
            'state' => $isCancelled ? 'error' : ($isPaid ? 'done' : 'current'),
            'date' => $paidLabel,
            'date_iso' => $paidIso,
        ],
        [
            'label' => 'Preparación',
            'hint' => $isCancelled ? 'Pendiente' : ($isPreparing ? ($isShipped ? 'Hecho' : 'En trámite') : 'Pendiente'),
            'icon' => 'box',
            'state' => $isCancelled ? 'error' : ($isPreparing ? ($isShipped ? 'done' : 'current') : 'todo'),
            'date' => $prepLabel,
            'date_iso' => $prepIso,
        ],
        [
            'label' => 'Enviado',
            'hint' => $isCancelled ? 'Pendiente' : ($isShipped ? ($isDelivered ? 'Hecho' : 'En trámite') : 'Pendiente'),
            'icon' => 'truck',
            'state' => $isCancelled ? 'error' : ($isShipped ? ($isDelivered ? 'done' : 'current') : 'todo'),
            'date' => $shipLabel,
            'date_iso' => $shipIso,
        ],
        [
            'label' => 'Entregado',
            'hint' => $isCancelled ? 'Pendiente' : ($isDelivered ? 'Hecho' : 'Pendiente'),
            'icon' => 'home',
            'state' => $isCancelled ? 'error' : ($isDelivered ? 'done' : 'todo'),
            'date' => $deliveryLabel,
            'date_iso' => $deliveryIso,
        ],
    ];
@endphp

@include('sandbox-buyer.partials.status-pipeline', ['steps' => $steps, 'compact' => $compact])
