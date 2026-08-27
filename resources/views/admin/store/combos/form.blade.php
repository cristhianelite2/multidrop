@extends('layouts.admin')

@section('title', ($combo->exists ? 'Editar' : 'Nuevo').' combo')
@section('heading', $combo->exists ? 'Editar combo' : 'Nuevo combo')
@section('subheading', $store->name)

@section('content')
@php
    $imagesText = old('images', implode("\n", is_array($combo->images) ? $combo->images : []));
    $selected   = collect(old('product_ids', $selected_ids ?? []))->map(fn($id) => (int)$id)->all();
    $strategy   = old('strategy', $combo->strategy ?? 'qty');
    $discType   = old('discount_type', $combo->discount_type ?? 'percent');
    // Indexar productos por id para JS
    $productsById = $products->keyBy('id')->map(function ($p) use ($store) {
        $images = [];
        $main = trim((string) ($p->image_url ?? ''));
        if ($main !== '') {
            $images[] = $main;
        }
        foreach ((array) data_get($p->verified_data, 'images', []) as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                $images[] = $url;
            }
        }
        foreach ((array) data_get($p->creative_data, 'images', []) as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                $images[] = $url;
            }
        }
        $quote = $p->quoteIn($store->currency());

        return [
            'id'    => $p->id,
            'name'  => $p->name,
            'price' => (float) $quote['price'],
            'currency' => $quote['currency'] ?: ($p->currency ?? ''),
            'thumb' => $p->image_url ?? data_get($p->verified_data, 'images.0', ''),
            'images' => array_values(array_unique($images)),
            'sku'   => $p->sku ?? '',
            'status'=> $p->status,
        ];
    })->all();
    $storeCurrency = $store->currency();
    $storageBaseUrl = rtrim(request()->getSchemeAndHttpHost().request()->getBaseUrl(), '/').'/storage';
@endphp

<form method="post"
      action="{{ $combo->exists ? route('admin.store.combos.update', $combo) : route('admin.store.combos.store') }}"
      id="combo-form"
      data-combo-id="{{ $combo->exists ? $combo->id : '' }}"
      class="space-y-5">
    @csrf
    @if($combo->exists) @method('PUT') @endif

    <div><a href="{{ route('admin.store.combos.index') }}" class="admin-btn-secondary">← Combos</a></div>

    @if($errors->any())
        <div class="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 space-y-1">
            @foreach($errors->all() as $e)<p>• {{ $e }}</p>@endforeach
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════
         BUILDER: productos del combo
    ═══════════════════════════════════════════════════════ --}}
    <div class="admin-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
            <div>
                <h2 class="font-display text-base font-bold text-ink">Productos del combo</h2>
                <p class="text-xs text-ink-soft/60 mt-0.5">Agrega los productos y define la cantidad mínima de cada uno.</p>
            </div>
            <button type="button" id="btn-add-product"
                    class="admin-btn !px-3 !py-1.5 text-xs flex items-center gap-1.5">
                <span class="text-base leading-none">+</span> Agregar producto
            </button>
        </div>

        {{-- Lista de productos agregados al combo --}}
        @php
            $selectedProducts = $products->filter(fn($p) => in_array((int)$p->id, $selected, true));
        @endphp
        <div id="combo-builder-list" class="divide-y divide-line/50">
            @foreach($selectedProducts as $p)
                @php
                    $thumb = $p->image_url ?? data_get($p->verified_data, 'images.0', '');
                    $quote = $p->quoteIn($store->currency());
                @endphp
                <div class="combo-builder-row flex items-center gap-3 px-5 py-3" data-id="{{ $p->id }}" data-price="{{ (float)$quote['price'] }}">
                    <input type="hidden" name="product_ids[]" value="{{ $p->id }}">
                    @if($thumb)
                        <img src="{{ $thumb }}" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover border border-line">
                    @else
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-line bg-mist text-ink-soft/40 text-xs">—</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="font-medium text-sm text-ink truncate">{{ $p->name }}</div>
                        <div class="text-xs text-ink-soft/60">{{ $p->sku }} · {{ number_format((float)$quote['price'],2) }} {{ $quote['currency'] }}</div>
                    </div>
                    <button type="button" class="btn-remove-row ml-2 text-ink-soft/40 hover:text-coral text-xl leading-none" title="Quitar">×</button>
                </div>
            @endforeach
        </div>
        <div id="combo-builder-empty"
             class="flex flex-col items-center justify-center gap-2 py-10 text-center text-ink-soft/50 {{ $selectedProducts->isNotEmpty() ? 'hidden' : '' }}">
            <span class="text-4xl">📦</span>
            <p class="text-sm">Aún no agregaste productos.<br>Pulsa <strong>+ Agregar producto</strong> para empezar.</p>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         Regla de activación
    ═══════════════════════════════════════════════════════ --}}
    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink mb-1">Regla de activación</h2>
        <p class="text-xs text-ink-soft/60 mb-4">¿Cuándo se aplica el descuento?</p>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach([
                ['qty',  '📦', 'Por cantidad', 'El cliente compra la cantidad mínima del mismo producto.'],
                ['pair', '🔗', 'Por combinación', 'El cliente lleva todos los productos del combo juntos.'],
                ['both', '⚡', 'Ambas', 'Aplica si se cumple cualquiera de las dos reglas.'],
            ] as [$val, $icon, $label, $desc])
            <label class="combo-rule-card cursor-pointer rounded-xl border-2 p-4 transition-all select-none
                          {{ $strategy === $val ? 'border-teal bg-teal/5' : 'border-line bg-white hover:border-teal/40' }}">
                <input type="radio" name="strategy" value="{{ $val }}" class="sr-only" @checked($strategy === $val)>
                <div class="mb-2 text-xl">{{ $icon }}</div>
                <div class="text-sm font-semibold text-ink">{{ $label }}</div>
                <div class="mt-1 text-xs text-ink-soft/60 leading-snug">{{ $desc }}</div>
            </label>
            @endforeach
        </div>
        <div class="mt-4" id="combo-qty-min-wrap">
            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Cantidad mínima</label>
            <input type="number" name="qty_min" id="combo-qty-min" min="1" max="99"
                   value="{{ old('qty_min', $combo->qty_min ?: 1) }}"
                   class="admin-input !w-24">
            <p class="mt-1 text-xs text-ink-soft/55">Solo para «Por cantidad»: unidades del mismo producto que activan el descuento.</p>
        </div>
        <p id="combo-strategy-hint" class="hidden mt-3 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Hay varios productos. Al guardar se usará «Por combinación» para que el precio del pack coincida con este resumen.
        </p>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         Precio / descuento
    ═══════════════════════════════════════════════════════ --}}
    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink mb-1">Precio especial</h2>
        <p class="text-xs text-ink-soft/60 mb-4">¿Qué obtiene el cliente al cumplir la regla?</p>
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex gap-2">
                <label class="combo-disc-card cursor-pointer rounded-xl border-2 px-5 py-3 text-center transition-all select-none
                              {{ $discType === 'percent' ? 'border-teal bg-teal/5' : 'border-line bg-white hover:border-teal/40' }}">
                    <input type="radio" name="discount_type" value="percent" class="sr-only" @checked($discType === 'percent')>
                    <div class="text-2xl font-bold text-ink">%</div>
                    <div class="text-xs text-ink-soft mt-0.5">Descuento</div>
                </label>
                <label class="combo-disc-card cursor-pointer rounded-xl border-2 px-5 py-3 text-center transition-all select-none
                              {{ $discType === 'fixed' ? 'border-teal bg-teal/5' : 'border-line bg-white hover:border-teal/40' }}">
                    <input type="radio" name="discount_type" value="fixed" class="sr-only" @checked($discType === 'fixed')>
                    <div class="text-2xl font-bold text-ink">$</div>
                    <div class="text-xs text-ink-soft mt-0.5">Precio fijo</div>
                </label>
            </div>
            <div class="flex-1 min-w-[140px] max-w-[200px]">
                <label class="mb-1 block text-xs font-medium text-ink-soft">
                    Valor <span id="disc-suffix" class="text-ink-soft/50">({{ $discType === 'percent' ? '%' : 'precio fijo' }})</span>
                </label>
                <div class="relative">
                    <span id="disc-prefix" class="absolute left-3 top-1/2 -translate-y-1/2 font-semibold text-ink-soft/50 pointer-events-none text-sm">
                        {{ $discType === 'percent' ? '%' : '$' }}
                    </span>
                    <input type="number" step="0.01" min="0" name="discount_value"
                           value="{{ old('discount_value', $combo->discount_value) }}"
                           required placeholder="10"
                           class="admin-input !pl-7">
                </div>
            </div>
        </div>

        <div id="combo-price-summary" class="mt-4 rounded-xl border border-line/80 bg-mist/40 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft/70 mb-2">Referencia de precio</p>
            <div id="combo-price-summary-body" class="space-y-2 text-sm">
                <p class="text-ink-soft/60 text-xs">Agrega productos al combo para ver cuánto pagaría el cliente sin descuento.</p>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         Nombre, imagen y publicación
    ═══════════════════════════════════════════════════════ --}}
    <div class="admin-card p-5 sm:p-6 space-y-4" id="sec-combo-copy">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-base font-bold text-ink mb-0.5">Nombre e imagen</h2>
                <p class="text-xs text-ink-soft/60 !mt-0">Cómo se mostrará en el catálogo si lo publicas como producto.</p>
            </div>
            <button type="button"
                    id="combo-ai-copy-btn"
                    class="admin-btn-secondary flex items-center gap-1.5 !px-3 !py-2 text-xs shrink-0"
                    data-url="{{ route('admin.store.combos.ai-copy') }}"
                    @disabled(!($has_miia ?? false))
                    title="{{ ($has_miia ?? false) ? 'Genera nombre, slug, descripción y prompt de imagen con MIIA' : 'Configura MIIA en General' }}">
                <i class="fa-solid fa-wand-magic-sparkles text-teal"></i>
                Generar copy con IA
            </button>
        </div>

        <div id="combo-ai-status" class="hidden rounded-xl border px-3 py-2 text-xs"></div>

        <div id="combo-draft-bar" class="hidden rounded-xl border border-teal/30 bg-teal/5 px-3 py-2.5 text-xs">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span id="combo-draft-bar-text" class="text-ink-soft">Hay cambios guardados localmente en este navegador.</span>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <button type="button" id="combo-draft-restore-local" class="hidden admin-btn-secondary !px-2.5 !py-1 text-[11px]">
                        Usar borrador
                    </button>
                    <button type="button" id="combo-draft-restore-pre-ai" class="hidden admin-btn-secondary !px-2.5 !py-1 text-[11px]">
                        Deshacer cambios de IA
                    </button>
                    <button type="button" id="combo-draft-clear" class="text-[11px] text-ink-soft/60 hover:text-ink underline">
                        Descartar borradores
                    </button>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre del combo</label>
                <input name="name" id="combo-name" value="{{ old('name', $combo->name) }}" required
                       class="admin-input" maxlength="190" placeholder="Ej: Pack Verano 2×1">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Slug <span class="font-normal text-ink-soft/40">(auto)</span></label>
                <input name="slug" id="combo-slug" value="{{ old('slug', $combo->slug) }}" class="admin-input" placeholder="pack-verano-2x1">
            </div>
        </div>
        <div>
            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Descripción <span class="font-normal text-ink-soft/40">(opcional)</span></label>
            <textarea name="description" id="combo-description" rows="2" class="admin-input"
                      placeholder="Breve descripción visible al cliente…">{{ old('description', $combo->description) }}</textarea>
        </div>
        <div>
            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prompt de imagen <span class="font-normal text-ink-soft/40">(brief para MIIA)</span></label>
            <textarea id="combo-image-prompt" rows="3" class="admin-input text-sm"
                      placeholder="Tras «Generar copy con IA» aparecerá el brief visual. Edítalo antes de generar las imágenes promocionales."></textarea>
            <p class="mt-1 text-xs text-ink-soft/55">Revisa y ajusta el prompt. MIIA usará este texto, las plantillas de estilo y las fotos de los productos del combo.</p>
        </div>

        <div class="rounded-xl border border-line bg-mist/15 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-ink">Imágenes promocionales (móvil)</p>
                <p class="text-xs text-ink-soft/60 mt-0.5">Formato 9:16 · 1 imagen por estilo (las plantillas marcadas son referencias)</p>
                <p id="combo-promo-selection-summary" class="text-xs text-teal mt-1">0 estilos seleccionados</p>
            </div>
            <button type="button"
                    id="combo-promo-open-modal"
                    class="admin-btn-secondary flex items-center gap-1.5 !px-3 !py-1.5 text-xs shrink-0"
                    @disabled(!($has_miia ?? false))>
                <i class="fa-solid fa-images text-teal"></i>
                Elegir plantillas
            </button>
        </div>

        @if(!($has_miia ?? false))
            <p class="text-xs text-amber-700/80 bg-amber-50 border border-amber-200/80 rounded-lg px-3 py-2">
                Configura <strong>MIIA</strong> en General para habilitar la generación promocional.
            </p>
        @endif

        <div id="combo-promo-progress" class="hidden">
            <div class="h-2 rounded-full bg-line overflow-hidden">
                <div id="combo-promo-progress-bar" class="h-full bg-teal transition-all duration-300" style="width:0%"></div>
            </div>
            <p id="combo-promo-progress-text" class="mt-1.5 text-xs text-ink-soft/70"></p>
        </div>

        <div id="combo-ai-image-status" class="hidden rounded-xl border px-3 py-2 text-xs"></div>
        <div>
            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Imagen(es) <span class="font-normal text-ink-soft/40">(la primera es la principal)</span></label>
            <textarea name="images" id="combo-images" rows="3" class="hidden" aria-hidden="true">{{ $imagesText }}</textarea>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="combo-use-product-images" class="admin-btn-secondary !px-3 !py-1.5 text-xs">
                    Usar foto principal de cada producto
                </button>
                <button type="button" id="combo-pick-product-images" class="admin-btn-secondary !px-3 !py-1.5 text-xs">
                    Elegir fotos de productos
                </button>
                <label class="admin-btn-secondary !px-3 !py-1.5 text-xs cursor-pointer mb-0">
                    Subir imágenes
                    <input type="file" id="combo-image-upload" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="sr-only">
                </label>
                <span id="combo-upload-status" class="text-xs text-ink-soft/60 hidden"></span>
            </div>
            <p class="mt-2 text-[11px] text-ink-soft/55">La primera es la principal. Usa ← → para ordenar, la estrella para marcarla y <kbd class="rounded border border-line px-1">Ctrl</kbd>+<kbd class="rounded border border-line px-1">V</kbd> para pegar una imagen.</p>
            <style>
                #combo-image-previews .combo-preview-move,
                #combo-image-previews .combo-preview-principal {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 0;
                    line-height: 0;
                }
                #combo-image-previews .combo-preview-move i,
                #combo-image-previews .combo-preview-principal i {
                    display: block;
                    line-height: 1;
                    width: 10px;
                    height: 10px;
                    font-size: 10px;
                }
            </style>
            <div id="combo-image-previews" class="mt-2 flex flex-wrap items-start gap-2"></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 pt-1">
            <label class="flex items-start gap-3 rounded-xl border-2 p-3 cursor-pointer transition-colors
                          has-[:checked]:border-teal has-[:checked]:bg-teal/5 border-line bg-white hover:border-teal/30">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $combo->is_active ?? true))
                       class="mt-0.5 h-4 w-4 rounded border-line text-teal">
                <span>
                    <span class="block text-sm font-semibold text-ink">Combo activo</span>
                    <span class="block text-xs text-ink-soft/55 mt-0.5">Se aplica automáticamente en carrito y checkout.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border-2 p-3 cursor-pointer transition-colors
                          has-[:checked]:border-teal has-[:checked]:bg-teal/5 border-line bg-white hover:border-teal/30">
                <input type="checkbox" name="publish_as_product" value="1"
                       @checked(old('publish_as_product', $combo->publish_as_product ?? true))
                       class="mt-0.5 h-4 w-4 rounded border-line text-teal">
                <span>
                    <span class="block text-sm font-semibold text-ink">Publicar en catálogo</span>
                    <span class="block text-xs text-ink-soft/55 mt-0.5">Aparece como producto con precio tachado y precio especial.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border-2 p-3 cursor-pointer transition-colors sm:col-span-2
                          has-[:checked]:border-teal has-[:checked]:bg-teal/5 border-line bg-white hover:border-teal/30">
                <input type="checkbox" name="modify_landing" id="combo-modify-landing" value="1"
                       @checked(old('modify_landing'))
                       class="mt-0.5 h-4 w-4 rounded border-line text-teal">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-semibold text-ink">Modificar landing para producto principal</span>
                    <span class="block text-xs text-ink-soft/55 mt-0.5">MIIA aplica las imágenes promocionales 9:16 a la landing, PDP y CSS global (móvil + sitio). Lo marca como producto estrella al guardar.</span>
                    <button type="button" id="combo-ai-landing-btn"
                            class="mt-2 admin-btn-secondary !px-3 !py-1.5 text-xs"
                            data-url="{{ route('admin.store.combos.ai-landing') }}"
                            @disabled(!($has_miia ?? false))>
                        Aplicar a landing ahora
                    </button>
                </span>
            </label>
        </div>
    </div>

    <div class="admin-form-actions flex flex-wrap gap-3">
        <button class="admin-btn">{{ $combo->exists ? 'Guardar cambios' : 'Crear combo' }}</button>
        <a href="{{ route('admin.store.combos.index') }}" class="admin-btn-secondary">Cancelar</a>
    </div>
</form>

{{-- Modal: vista previa plantilla promocional --}}
<div id="combo-promo-preview-modal"
     style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.75);align-items:center;justify-content:center;padding:16px">
    <div class="relative max-w-md w-full">
        <button type="button" id="combo-promo-preview-close"
                class="absolute -top-10 right-0 text-white/80 hover:text-white text-2xl leading-none">×</button>
        <img id="combo-promo-preview-img" src="" alt="" class="w-full max-h-[80vh] object-contain rounded-xl shadow-2xl border border-white/20 mx-auto">
        <p id="combo-promo-preview-label" class="mt-2 text-center text-xs text-white/80"></p>
    </div>
</div>

{{-- Modal: selector de plantillas promocionales --}}
<div id="combo-promo-modal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl flex flex-col overflow-hidden min-h-0" style="max-height:90vh">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-line shrink-0">
            <div>
                <h3 class="font-display font-bold text-ink">Imágenes promocionales (móvil)</h3>
                <p class="text-xs text-ink-soft/60 mt-0.5">Expande cada estilo, elige referencias · 1 imagen final por estilo</p>
            </div>
            <button type="button" id="combo-promo-modal-close" class="text-ink-soft/50 hover:text-ink text-2xl leading-none">×</button>
        </div>
        <div class="px-4 py-3 border-b border-line shrink-0">
            <input type="search" id="combo-promo-style-search" class="admin-input text-sm"
                   placeholder="Buscar estilo…" autocomplete="off">
            <div class="mt-2 flex flex-wrap gap-3">
                <button type="button" id="combo-promo-expand-all" class="text-xs text-teal hover:underline">Expandir todos</button>
                <button type="button" id="combo-promo-collapse-all" class="text-xs text-ink-soft/70 hover:underline">Comprimir todos</button>
            </div>
        </div>
        <div id="combo-promo-styles-list" class="overflow-y-auto flex-1 min-h-0 px-4 py-3 space-y-3">
            <p class="text-xs text-ink-soft/60 text-center py-6">Cargando estilos…</p>
        </div>
        <div class="relative z-20 px-5 py-4 border-t border-line shrink-0 flex flex-wrap items-center justify-between gap-3 bg-white">
            <p id="combo-promo-modal-error" class="hidden w-full text-xs text-rose-700"></p>
            <p id="combo-promo-modal-count" class="text-xs text-ink-soft">0 estilos → 0 imágenes</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="combo-promo-modal-cancel" class="admin-btn-secondary !px-3 !py-1.5 text-xs">Cerrar</button>
                <button type="button"
                        id="combo-ai-promo-btn"
                        class="admin-btn !px-3 !py-1.5 text-xs flex items-center gap-1.5"
                        data-url="{{ route('admin.store.combos.ai-image') }}"
                        @disabled(!($has_miia ?? false))>
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    Generar imágenes
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: fotos de productos del combo --}}
<div id="combo-product-photos-modal"
     style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg flex flex-col" style="max-height:85vh">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-line shrink-0">
            <div>
                <h3 class="font-display font-bold text-ink">Fotos de los productos</h3>
                <p class="text-xs text-ink-soft/60 mt-0.5">Marca las que quieras agregar al combo</p>
            </div>
            <button type="button" id="combo-product-photos-close" class="text-ink-soft/50 hover:text-ink text-2xl leading-none">×</button>
        </div>
        <div id="combo-product-photos-list" class="overflow-y-auto flex-1 px-4 py-3 space-y-4"></div>
        <div class="px-5 py-4 border-t border-line shrink-0 flex flex-wrap items-center justify-between gap-3 bg-mist/20">
            <p id="combo-product-photos-count" class="text-xs text-ink-soft">0 seleccionadas</p>
            <div class="flex gap-2">
                <button type="button" id="combo-product-photos-cancel" class="admin-btn-secondary !px-3 !py-1.5 text-xs">Cancelar</button>
                <button type="button" id="combo-product-photos-add" class="admin-btn !px-3 !py-1.5 text-xs">Agregar</button>
            </div>
        </div>
    </div>
</div>
<div id="combo-picker-modal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg flex flex-col" style="max-height:85vh">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-line">
            <h3 class="font-display font-bold text-ink">Agregar producto al combo</h3>
            <button type="button" id="combo-picker-close" class="text-ink-soft/50 hover:text-ink text-2xl leading-none">×</button>
        </div>
        <div class="px-4 py-3 border-b border-line">
            <input type="search" id="combo-picker-search" class="admin-input"
                   placeholder="Buscar por nombre, SKU…" autocomplete="off">
        </div>
        <div id="combo-picker-list" class="overflow-y-auto flex-1 divide-y divide-line/50">
            @foreach($products as $p)
                @php
                    $thumb = $p->image_url ?? data_get($p->verified_data, 'images.0', '');
                    $hay   = mb_strtolower(implode(' ', array_filter([$p->name, $p->slug, $p->sku, (string)$p->id])));
                    $viewUrl = $p->slug
                        ? route('store.design.page', ['slug' => $store->slug, 'handle' => $p->slug])
                        : route('admin.store.products.edit', $p);
                @endphp
                <div class="combo-picker-item flex items-center gap-2 px-4 py-3 hover:bg-mist/40 transition-colors"
                     data-id="{{ $p->id }}"
                     data-name="{{ $p->name }}"
                     data-price="{{ number_format((float)$p->price,2) }} {{ $p->currency }}"
                     data-sku="{{ $p->sku }}"
                     data-thumb="{{ $thumb }}"
                     data-view-url="{{ $viewUrl }}"
                     data-search="{{ $hay }}">
                    @if($thumb)
                        <button type="button"
                                class="combo-picker-thumb shrink-0 rounded-lg border border-line overflow-hidden hover:ring-2 hover:ring-teal/40 transition-shadow cursor-zoom-in"
                                data-thumb="{{ $thumb }}"
                                data-name="{{ $p->name }}"
                                title="Ver imagen ampliada"
                                aria-label="Ver imagen ampliada de {{ $p->name }}">
                            <img src="{{ $thumb }}" alt="" class="h-10 w-10 object-cover block pointer-events-none">
                        </button>
                    @else
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-line bg-mist text-xs text-ink-soft/40">—</span>
                    @endif
                    <button type="button"
                            class="combo-picker-toggle min-w-0 flex-1 flex items-center gap-3 text-left py-0.5">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-ink truncate">{{ $p->name }}</span>
                            <span class="block text-xs text-ink-soft/60">{{ $p->sku }} · {{ number_format((float)$p->price,2) }} {{ $p->currency }}</span>
                        </span>
                    </button>
                    <a href="{{ $viewUrl }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="combo-picker-view shrink-0 inline-flex items-center justify-center h-8 w-8 rounded-lg border border-line text-ink-soft hover:text-teal hover:border-teal/40 transition-colors"
                       title="Ver producto en nueva pestaña"
                       aria-label="Ver producto en nueva pestaña">
                        ↗
                    </a>
                    <span class="combo-picker-added-badge hidden shrink-0 text-xs bg-teal/10 text-teal px-2 py-0.5 rounded-full font-semibold">✓ Seleccionado</span>
                </div>
            @endforeach
        </div>
        <div class="px-4 py-3 border-t border-line flex items-center justify-between gap-3">
            <p class="text-xs text-ink-soft/60">Clic en el nombre para agregar o quitar · ↗ abre la tienda · imagen ampliable.</p>
            <button type="button" id="combo-picker-close2" class="admin-btn-secondary shrink-0">Cerrar</button>
        </div>
    </div>
</div>

<div id="combo-picker-image-modal"
     style="display:none;position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.82);align-items:center;justify-content:center;padding:24px">
    <button type="button" id="combo-picker-image-close"
            class="absolute top-4 right-4 text-white/80 hover:text-white text-3xl leading-none"
            aria-label="Cerrar imagen">×</button>
    <figure class="max-w-[min(92vw,720px)] max-h-[85vh] flex flex-col items-center gap-3">
        <img id="combo-picker-image-full" src="" alt="" class="max-w-full max-h-[75vh] w-auto h-auto rounded-xl shadow-2xl object-contain bg-white/5">
        <figcaption id="combo-picker-image-caption" class="text-sm text-white/80 text-center px-2"></figcaption>
    </figure>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    var $modal      = $('#combo-picker-modal');
    var $imageModal = $('#combo-picker-image-modal');
    var $imageFull  = $('#combo-picker-image-full');
    var $imageCaption = $('#combo-picker-image-caption');
    var $list       = $('#combo-builder-list');
    var $empty      = $('#combo-builder-empty');
    var $pickerList = $('#combo-picker-list');
    var $pickerSearch = $('#combo-picker-search');
    var productsById = @json($productsById);
    var storeCurrency = @json($storeCurrency);
    var storageBaseUrl = @json($storageBaseUrl);
    var builderSnapKey = 'md.combo.builder.' + location.pathname;
    var formDraftKey = 'md.combo.form-draft.' + location.pathname;
    var preAiDraftKey = 'md.combo.pre-ai-draft.' + location.pathname;
    var formDraftTimer = null;
    var comboId = String($('#combo-form').data('combo-id') || '');
    var formSubmitting = false;
    var aiGenerating = false;

    /* ── Borrador local (memoria parcial) ───────────────── */
    function snapshotFormDraft() {
        return {
            savedAt: Date.now(),
            name: String($('#combo-name').val() || ''),
            slug: String($('#combo-slug').val() || ''),
            description: String($('#combo-description').val() || ''),
            image_prompt: String($('#combo-image-prompt').val() || ''),
            images: String($('#combo-images').val() || ''),
            strategy: String($('[name=strategy]:checked').val() || 'qty'),
            qty_min: String($('#combo-qty-min').val() || '1'),
            discount_type: String($('[name=discount_type]:checked').val() || 'percent'),
            discount_value: String($('[name=discount_value]').val() || ''),
            is_active: $('[name=is_active]').is(':checked'),
            publish_as_product: $('[name=publish_as_product]').is(':checked'),
            modify_landing: $('#combo-modify-landing').is(':checked'),
            builder_rows: snapshotBuilderRows(),
            promo_templates: (typeof promoSelectedTemplates !== 'undefined' ? promoSelectedTemplates.slice() : []),
            promo_expanded_styles: (typeof promoExpandedStyles !== 'undefined' ? $.extend({}, promoExpandedStyles) : {})
        };
    }

    function draftHasContent(draft) {
        if (!draft || typeof draft !== 'object') return false;
        if (draft.name || draft.slug || draft.description || draft.image_prompt || draft.images) return true;
        if (draft.builder_rows && draft.builder_rows.length) return true;
        if (draft.promo_templates && draft.promo_templates.length) return true;
        return false;
    }

    function persistFormDraft() {
        var draft = snapshotFormDraft();
        if (!draftHasContent(draft)) {
            try { sessionStorage.removeItem(formDraftKey); } catch (e) {}
            updateDraftBar();
            return;
        }
        try {
            sessionStorage.setItem(formDraftKey, JSON.stringify(draft));
        } catch (e) {}
        updateDraftBar();
    }

    function schedulePersistFormDraft() {
        if (formDraftTimer) clearTimeout(formDraftTimer);
        formDraftTimer = setTimeout(persistFormDraft, 400);
    }

    function readFormDraft(key) {
        key = key || formDraftKey;
        try {
            var raw = sessionStorage.getItem(key);
            if (!raw) return null;
            var draft = JSON.parse(raw);
            return draft && typeof draft === 'object' ? draft : null;
        } catch (e) {
            return null;
        }
    }

    function savePreAiDraft() {
        try {
            if (readFormDraft(preAiDraftKey)) {
                updateDraftBar();
                return;
            }
            sessionStorage.setItem(preAiDraftKey, JSON.stringify(snapshotFormDraft()));
            $('#combo-draft-restore-pre-ai').removeClass('hidden');
            updateDraftBar();
        } catch (e) {}
    }

    function draftFieldSnapshot() {
        return {
            name: String($('#combo-name').val() || ''),
            slug: String($('#combo-slug').val() || ''),
            description: String($('#combo-description').val() || ''),
            image_prompt: String($('#combo-image-prompt').val() || ''),
            images: String($('#combo-images').val() || ''),
            strategy: String($('[name=strategy]:checked').val() || 'qty'),
            qty_min: String($('#combo-qty-min').val() || '1'),
            discount_type: String($('[name=discount_type]:checked').val() || 'percent'),
            discount_value: String($('[name=discount_value]').val() || ''),
            is_active: $('[name=is_active]').is(':checked'),
            publish_as_product: $('[name=publish_as_product]').is(':checked'),
            modify_landing: $('#combo-modify-landing').is(':checked'),
            builder_rows: snapshotBuilderRows()
        };
    }

    function draftDiffersFromForm(draft) {
        if (!draft) return false;
        var current = draftFieldSnapshot();
        var keys = ['name', 'slug', 'description', 'image_prompt', 'images', 'strategy', 'discount_type', 'discount_value'];
        for (var i = 0; i < keys.length; i++) {
            if (String(draft[keys[i]] || '') !== String(current[keys[i]] || '')) {
                return true;
            }
        }
        if (!!draft.is_active !== !!current.is_active) return true;
        if (!!draft.publish_as_product !== !!current.publish_as_product) return true;
        var draftIds = (draft.builder_rows || []).map(function (r) { return r.id; }).sort().join(',');
        var currentIds = (current.builder_rows || []).map(function (r) { return r.id; }).sort().join(',');
        return draftIds !== currentIds;
    }

    function applyDiscountUiFromType() {
        var isPct = $('[name=discount_type]:checked').val() === 'percent';
        $('#disc-prefix').text(isPct ? '%' : '$');
        $('#disc-suffix').text(isPct ? '(%)' : '(precio fijo)');
    }

    function clearFormDrafts() {
        try {
            sessionStorage.removeItem(formDraftKey);
            sessionStorage.removeItem(preAiDraftKey);
            sessionStorage.removeItem(builderSnapKey);
        } catch (e) {}
        $('#combo-draft-restore-pre-ai').addClass('hidden');
        updateDraftBar();
    }

    function restorePromoDraftSelection(templates, expanded) {
        if (typeof promoSelectedTemplates === 'undefined') return;
        promoSelectedTemplates = Array.isArray(templates) ? templates.slice() : [];
        promoExpandedStyles = expanded && typeof expanded === 'object' ? $.extend({}, expanded) : {};
        $('.combo-promo-template-check').each(function () {
            var $cb = $(this);
            var style = String($cb.data('style') || '');
            var file = String($cb.val() || '');
            var selected = promoSelectedTemplates.some(function (sel) {
                return sel.style === style && sel.file === file;
            });
            $cb.prop('checked', selected);
        });
        if (typeof updatePromoSelectionCount === 'function') updatePromoSelectionCount();
        if (typeof updateAllPromoStyleBadges === 'function') updateAllPromoStyleBadges();
        if ($('#combo-promo-modal').css('display') === 'flex' && typeof restorePromoModalState === 'function') {
            restorePromoModalState();
        }
    }

    function applyFormDraft(draft, options) {
        options = options || {};
        if (!draft) return false;

        if (options.fields !== false) {
            $('#combo-name').val(draft.name || '');
            $('#combo-slug').val(draft.slug || '');
            $('#combo-description').val(draft.description || '');
            $('#combo-image-prompt').val(draft.image_prompt || '');
            if (draft.images !== undefined && typeof setImageLines === 'function') {
                setImageLines(parseImageLinesFromText(draft.images));
            } else if (draft.images !== undefined) {
                $('#combo-images').val(draft.images);
                if (typeof refreshImagePreviews === 'function') refreshImagePreviews();
            }
            if (draft.strategy) {
                $('[name=strategy][value="' + draft.strategy + '"]').prop('checked', true);
            }
            if (draft.qty_min !== undefined && draft.qty_min !== '') {
                $('#combo-qty-min').val(draft.qty_min);
            }
            if (draft.discount_type) {
                $('[name=discount_type][value="' + draft.discount_type + '"]').prop('checked', true);
            }
            if (draft.discount_value !== undefined) {
                $('[name=discount_value]').val(draft.discount_value);
            }
            if (typeof draft.is_active === 'boolean') {
                $('[name=is_active]').prop('checked', draft.is_active);
            }
            if (typeof draft.publish_as_product === 'boolean') {
                $('[name=publish_as_product]').prop('checked', draft.publish_as_product);
            }
            if (typeof draft.modify_landing === 'boolean') {
                $('#combo-modify-landing').prop('checked', draft.modify_landing);
            }
            refreshRadioCards('.combo-rule-card');
            refreshRadioCards('.combo-disc-card');
            applyDiscountUiFromType();
        }

        if (draft.builder_rows && draft.builder_rows.length) {
            restoreBuilderRows(draft.builder_rows);
        }

        restorePromoDraftSelection(draft.promo_templates, draft.promo_expanded_styles);

        refreshComboPriceSummary();
        if (typeof refreshStrategyHint === 'function') refreshStrategyHint();
        if (!options.skipPersist) {
            persistBuilderSnapshot();
            persistFormDraft();
        }
        if (!options.silent) {
            $('#combo-draft-bar-text').text('Contenido restaurado. Revisa los campos antes de guardar el combo.');
            $('#combo-draft-bar').removeClass('hidden');
        }
        return true;
    }

    function parseImageLinesFromText(text) {
        return String(text || '')
            .split(/\r\n|\r|\n/)
            .map(function (l) { return l.trim(); })
            .filter(Boolean);
    }

    function formatDraftAge(ms) {
        if (!ms) return '';
        var mins = Math.max(1, Math.round((Date.now() - ms) / 60000));
        if (mins < 60) return 'hace ' + mins + ' min';
        return 'hace ' + Math.round(mins / 60) + ' h';
    }

    function updateDraftBar() {
        var draft = readFormDraft(formDraftKey);
        var preAi = readFormDraft(preAiDraftKey);
        var $bar = $('#combo-draft-bar');
        var $preAiBtn = $('#combo-draft-restore-pre-ai');
        var parts = [];
        var showBar = false;

        if (preAi && draftHasContent(preAi)) {
            $preAiBtn.removeClass('hidden');
            showBar = true;
            if (draftDiffersFromForm(preAi)) {
                parts.push('Puedes deshacer los cambios de IA y volver al texto anterior.');
            } else {
                parts.push('Copia de seguridad previa a IA disponible.');
            }
        } else {
            $preAiBtn.addClass('hidden');
        }

        if (draftHasContent(draft)) {
            showBar = true;
            if (!parts.length) {
                parts.push('Borrador local guardado ' + formatDraftAge(draft.savedAt) + '. Se recuperará si recargas la página.');
            }
        }

        if (!showBar) {
            $bar.addClass('hidden');
            return;
        }

        $('#combo-draft-bar-text').text(parts.join(' '));
        $bar.removeClass('hidden');
    }

    $('#combo-draft-restore-local').on('click', function (e) {
        e.preventDefault();
        var draft = readFormDraft(formDraftKey);
        if (!draftHasContent(draft)) {
            alert('No hay borrador local.');
            return;
        }
        if (!confirm('¿Reemplazar el combo guardado en pantalla por el borrador de este navegador? Luego pulsa Guardar para publicarlo.')) return;
        applyFormDraft(draft, { skipPersist: false });
        $('#combo-draft-restore-local').addClass('hidden');
        updateDraftBar();
    });
        e.preventDefault();
        var draft = readFormDraft(preAiDraftKey);
        if (!draftHasContent(draft)) {
            alert('No hay copia de antes de IA.');
            return;
        }
        if (!confirm('¿Deshacer los cambios de IA y restaurar el contenido anterior?')) return;
        applyFormDraft(draft, { skipPersist: false });
        updateDraftBar();
    });

    $('#combo-draft-clear').on('click', function (e) {
        e.preventDefault();
        if (!confirm('¿Descartar los borradores locales guardados en este navegador?')) return;
        clearFormDrafts();
    });

    function restoreFormDraftOnLoad() {
        var draft = readFormDraft(formDraftKey);
        if (!draftHasContent(draft)) {
            updateDraftBar();
            return;
        }
        if (!draftDiffersFromForm(draft)) {
            updateDraftBar();
            return;
        }
        if (comboId) {
            $('#combo-draft-restore-local').removeClass('hidden');
            $('#combo-draft-bar-text').text('Hay un borrador local distinto al combo ya guardado. No se aplicó solo para no cambiar el precio publicado.');
            $('#combo-draft-bar').removeClass('hidden');
            updateDraftBar();
            return;
        }
        applyFormDraft(draft, { silent: true, skipPersist: true });
        $('#combo-draft-bar-text').text('Se recuperó tu borrador local tras recargar. Revisa los campos antes de guardar.');
        $('#combo-draft-bar').removeClass('hidden');
        updateDraftBar();
    }

    $('#combo-form').on('input change', 'input, textarea, select', function () {
        if (aiGenerating) return;
        schedulePersistFormDraft();
    });

    /* ── Helpers ───────────────────────────────────────── */
    function normalizeStorageUrl(url) {
        url = String(url || '').trim();
        if (!url) return url;
        var match = url.match(/\/storage\/(.+)$/i);
        if (match) return storageBaseUrl + '/' + match[1];
        return url;
    }

    function fmtMoney(amount) {
        return Number(amount).toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function comboQtyForProduct(strategy, qtyMin) {
        if (strategy === 'qty' || strategy === 'both') {
            return Math.max(1, qtyMin);
        }

        return 1;
    }

    function comboPriceSummary() {
        var ids = addedIds();
        if (!ids.length) {
            return null;
        }

        var strategy = $('[name=strategy]:checked').val() || 'qty';
        var qtyMin = parseInt($('input[name=qty_min]').first().val() || '1', 10) || 1;
        var discType = $('[name=discount_type]:checked').val() || 'percent';
        var discValue = parseFloat($('[name=discount_value]').val() || '0') || 0;
        var normal = 0;
        var lines = [];

        ids.forEach(function (id) {
            var product = productsById[String(id)] || productsById[id];
            if (!product) {
                return;
            }
            var qty = comboQtyForProduct(strategy, qtyMin);
            var unit = parseFloat(product.price) || 0;
            var total = unit * qty;
            normal += total;
            lines.push({
                name: product.name,
                qty: qty,
                unit: unit,
                total: total
            });
        });

        if (normal <= 0) {
            return null;
        }

        var discounted;
        if (discType === 'fixed') {
            discounted = Math.min(normal, discValue);
        } else {
            var pct = Math.min(90, discValue);
            discounted = Math.round(normal * (1 - (pct / 100)) * 100) / 100;
        }

        var savings = Math.max(0, Math.round((normal - discounted) * 100) / 100);

        return {
            normal: Math.round(normal * 100) / 100,
            discounted: discounted,
            savings: savings,
            currency: storeCurrency,
            lines: lines,
            strategy: strategy,
            qtyMin: qtyMin,
            discType: discType,
            discValue: discValue
        };
    }

    function refreshComboPriceSummary() {
        var $body = $('#combo-price-summary-body');
        var summary = comboPriceSummary();

        if (!summary) {
            $body.html('<p class="text-ink-soft/60 text-xs">Agrega productos al combo para ver cuánto pagaría el cliente sin descuento.</p>');
            return;
        }

        var discLabel = summary.discType === 'fixed'
            ? 'Precio fijo del combo'
            : 'Con descuento (' + fmtMoney(summary.discValue) + '%)';
        var breakdownHtml = summary.lines.map(function (line) {
            var qtyLabel = line.qty > 1 ? (line.qty + '× ') : '';
            return '<div class="text-xs text-ink-soft/55 leading-snug">' + qtyLabel + line.name + ': ' + fmtMoney(line.total) + ' ' + summary.currency + '</div>';
        }).join('');

        $body.html([
            '<div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">',
                '<span class="text-ink-soft">Sin descuento</span>',
                '<span class="font-semibold text-ink">' + fmtMoney(summary.normal) + ' ' + summary.currency + '</span>',
            '</div>',
            '<div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">',
                '<span class="text-ink-soft">' + discLabel + '</span>',
                '<span class="font-semibold text-teal">' + fmtMoney(summary.discounted) + ' ' + summary.currency + '</span>',
            '</div>',
            summary.savings > 0
                ? '<p class="text-xs text-ink-soft/70">Ahorro estimado: <span class="font-medium text-ink">' + fmtMoney(summary.savings) + ' ' + summary.currency + '</span></p>'
                : '',
            '<div class="mt-1 space-y-1">' + breakdownHtml + '</div>'
        ].join(''));
    }
    function addedIds() {
        return $list.find('.combo-builder-row').map(function () {
            return parseInt($(this).data('id'));
        }).get();
    }

    function refreshStrategyHint() {
        var strategy = $('[name=strategy]:checked').val() || 'qty';
        var many = $list.find('.combo-builder-row').length >= 2;
        $('#combo-strategy-hint').toggleClass('hidden', !(strategy === 'qty' && many));
        $('#combo-qty-min-wrap').toggleClass('hidden', strategy === 'pair');
    }

    function maybeSwitchToPair() {
        if ($list.find('.combo-builder-row').length >= 2 && ($('[name=strategy]:checked').val() || 'qty') === 'qty') {
            $('[name=strategy][value="pair"]').prop('checked', true);
            refreshRadioCards('.combo-rule-card');
        }
        refreshStrategyHint();
    }

    function syncEmpty() {
        var hasRows = $list.find('.combo-builder-row').length > 0;
        $empty.toggleClass('hidden', hasRows);
        maybeSwitchToPair();
        refreshComboPriceSummary();
        persistBuilderSnapshot();
        schedulePersistFormDraft();
    }

    function snapshotBuilderRows() {
        var rows = [];
        $list.find('.combo-builder-row').each(function () {
            var $row = $(this);
            rows.push({
                id: parseInt($row.data('id'), 10),
                qty: parseInt($row.find('input[name=qty_min]').first().val() || '1', 10) || 1
            });
        });
        return rows;
    }

    function persistBuilderSnapshot() {
        var rows = snapshotBuilderRows();
        try {
            if (rows.length) {
                sessionStorage.setItem(builderSnapKey, JSON.stringify(rows));
            } else {
                sessionStorage.removeItem(builderSnapKey);
            }
        } catch (e) {}
    }

    function readBuilderSnapshot() {
        try {
            var raw = sessionStorage.getItem(builderSnapKey);
            if (!raw) return [];
            var rows = JSON.parse(raw);
            return Array.isArray(rows) ? rows : [];
        } catch (e) {
            return [];
        }
    }

    function restoreBuilderRows(rows) {
        if (!rows || !rows.length) return;

        $list.find('.combo-builder-row').remove();
        rows.forEach(function (row) {
            var product = productsById[String(row.id)] || productsById[row.id];
            if (!product) return;
            $list.append(buildRow(
                row.id,
                product.name,
                product.price,
                product.sku,
                product.thumb,
                row.qty
            ));
        });
        syncEmpty();
        syncPickerBadges();
    }

    function ensureBuilderRowsPersisted() {
        var saved = readBuilderSnapshot();
        if (!saved.length) {
            var draft = readFormDraft(formDraftKey);
            if (draft && draft.builder_rows && draft.builder_rows.length) {
                saved = draft.builder_rows;
            }
        }

        var domRows = snapshotBuilderRows();
        if (!saved.length) {
            if (domRows.length) {
                persistBuilderSnapshot();
            }
            return;
        }

        var savedKey = saved.map(function (r) { return r.id; }).sort().join(',');
        var domKey = domRows.map(function (r) { return r.id; }).sort().join(',');

        if (comboId && domRows.length && savedKey !== domKey) {
            persistBuilderSnapshot();
            return;
        }

        if (domRows.length === 0 || savedKey !== domKey) {
            restoreBuilderRows(saved);
            return;
        }

        persistBuilderSnapshot();
    }

    function syncPickerBadges() {
        var ids = addedIds();
        $pickerList.find('.combo-picker-item').each(function () {
            var added = ids.indexOf(parseInt($(this).data('id'))) !== -1;
            $(this).find('.combo-picker-added-badge').toggleClass('hidden', !added);
            $(this).toggleClass('bg-teal/5 ring-1 ring-inset ring-teal/25', added);
        });
    }

    function buildRow(id, name, price, sku, thumb, qty) {
        qty = qty || 1;
        var product = productsById[String(id)] || productsById[id];
        var unitPrice = product ? (parseFloat(product.price) || 0) : (parseFloat(price) || 0);
        var priceLabel = product
            ? (fmtMoney(unitPrice) + ' ' + (product.currency || storeCurrency))
            : price;
        var imgHtml = thumb
            ? '<img src="' + thumb + '" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover border border-line">'
            : '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-line bg-mist text-xs text-ink-soft/40">—</span>';

        return $([
            '<div class="combo-builder-row flex items-center gap-3 px-5 py-3" data-id="' + id + '" data-price="' + unitPrice + '">',
                '<input type="hidden" name="product_ids[]" value="' + id + '">',
                imgHtml,
                '<div class="min-w-0 flex-1">',
                    '<div class="font-medium text-sm text-ink truncate">' + name + '</div>',
                    '<div class="text-xs text-ink-soft/60">' + (sku ? sku + ' · ' : '') + priceLabel + '</div>',
                '</div>',
                '<button type="button" class="btn-remove-row ml-2 text-ink-soft/40 hover:text-coral text-xl leading-none" title="Quitar">×</button>',
            '</div>'
        ].join(''));
    }

    /* ── Abrir/cerrar modal ─────────────────────────────── */
    $('#btn-add-product').on('click', function () {
        syncPickerBadges();
        $pickerSearch.val('').trigger('input');
        $modal.css('display', 'flex');
        setTimeout(function () { $pickerSearch.focus(); }, 80);
    });

    function closeModal() {
        closeImageModal();
        $modal.css('display', 'none');
    }
    function closeImageModal() {
        $imageModal.css('display', 'none');
        $imageFull.attr('src', '');
        $imageCaption.text('');
    }
    function openImageModal(src, name) {
        if (!src) return;
        $imageFull.attr({ src: src, alt: name || '' });
        $imageCaption.text(name || '');
        $imageModal.css('display', 'flex');
    }

    function imageModalOpen() {
        return $imageModal.css('display') !== 'none';
    }

    $('#combo-picker-close, #combo-picker-close2').on('click', closeModal);
    $modal.on('click', function (e) { if ($(e.target).is($modal)) closeModal(); });
    $('#combo-picker-image-close').on('click', closeImageModal);
    $imageModal.on('click', function (e) {
        if ($(e.target).is($imageModal) || $(e.target).is('#combo-picker-image-close')) closeImageModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (imageModalOpen()) {
            closeImageModal();
            return;
        }
        if ($('#combo-product-photos-modal').is(':visible')) {
            closeProductPhotosModal();
            return;
        }
        if ($modal.is(':visible')) closeModal();
    });

    /* ── Imagen ampliada ─────────────────────────────────── */
    $pickerList.on('click', '.combo-picker-thumb', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openImageModal($(this).data('thumb'), $(this).data('name'));
    });

    $pickerList.on('click', '.combo-picker-view', function (e) {
        e.stopPropagation();
    });

    /* ── Búsqueda en modal ──────────────────────────────── */
    $pickerSearch.on('input', function () {
        var q = String($(this).val() || '').toLowerCase().trim();
        $pickerList.find('.combo-picker-item').each(function () {
            var ok = !q || ($(this).data('search') || '').indexOf(q) !== -1;
            $(this).toggle(ok);
        });
    });

    /* ── Seleccionar / deseleccionar producto en el modal ─ */
    $pickerList.on('click', '.combo-picker-toggle', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.combo-picker-item');
        var id = parseInt($row.data('id'), 10);
        var $existing = $list.find('.combo-builder-row[data-id="' + id + '"]');

        if ($existing.length) {
            $existing.remove();
            syncEmpty();
            syncPickerBadges();
            return;
        }

        var row = buildRow(
            id,
            $row.data('name'),
            $row.data('price'),
            $row.data('sku'),
            $row.data('thumb'),
            1
        );
        $list.append(row);
        syncEmpty();
        syncPickerBadges();
    });

    /* ── Quitar fila ────────────────────────────────────── */
    $list.on('click', '.btn-remove-row', function () {
        $(this).closest('.combo-builder-row').remove();
        syncEmpty();
        syncPickerBadges();
    });

    /* ── Radio cards ────────────────────────────────────── */
    function refreshRadioCards(selector) {
        $(selector).each(function () {
            var c = $(this).find('input[type=radio]').is(':checked');
            $(this).toggleClass('border-teal bg-teal/5', c).toggleClass('border-line bg-white', !c);
        });
    }
    $(document).on('change', '.combo-rule-card input, .combo-disc-card input', function () {
        refreshRadioCards('.combo-rule-card');
        refreshRadioCards('.combo-disc-card');
        var isPct = $('[name=discount_type]:checked').val() === 'percent';
        $('#disc-prefix').text(isPct ? '%' : '$');
        $('#disc-suffix').text(isPct ? '(%)' : '(precio fijo)');
        refreshComboPriceSummary();
        refreshStrategyHint();
    });

    $(document).on('input change', '[name=discount_value], input[name=qty_min]', function () {
        refreshComboPriceSummary();
        persistBuilderSnapshot();
    });

    $('#combo-form').on('submit', function (e) {
        if (aiGenerating) {
            e.preventDefault();
            alert('Espera a que termine la operación con IA antes de guardar el combo.');
            return false;
        }
        formSubmitting = true;
        clearFormDrafts();
    });

    $('#combo-form').on('keydown', function (e) {
        if (!aiGenerating || e.key !== 'Enter') return;
        if ($(e.target).is('textarea')) return;
        e.preventDefault();
    });

    ensureBuilderRowsPersisted();
    syncEmpty();
    syncPickerBadges();
    refreshImagePreviews();
    refreshComboPriceSummary();

    if ($('#combo-images').val()) {
        setImageLines(parseImageLines());
    }

    $(window).on('beforeunload', function () {
        if (formSubmitting) return;
        persistBuilderSnapshot();
        try {
            var draft = snapshotFormDraft();
            if (draftHasContent(draft)) {
                sessionStorage.setItem(formDraftKey, JSON.stringify(draft));
            }
        } catch (e) {}
    });

    /* ── Imágenes del combo ─────────────────────────────── */
    var uploadUrl = @json(route('admin.store.combos.upload-image'));
    var csrf = $('meta[name="csrf-token"]').attr('content');

    function parseImageLines() {
        return String($('#combo-images').val() || '')
            .split(/\r\n|\r|\n/)
            .map(function (l) { return l.trim(); })
            .filter(Boolean);
    }

    function setImageLines(urls) {
        var unique = [];
        urls.forEach(function (u) {
            u = normalizeStorageUrl(String(u || '').trim());
            if (u && unique.indexOf(u) === -1) unique.push(u);
        });
        $('#combo-images').val(unique.join('\n'));
        refreshImagePreviews();
    }

    function prependImageUrls(urls) {
        var unique = [];
        urls.forEach(function (u) {
            u = String(u || '').trim();
            if (u && unique.indexOf(u) === -1) unique.push(u);
        });
        var current = parseImageLines().filter(function (u) {
            return unique.indexOf(u) === -1;
        });
        setImageLines(unique.concat(current));
    }

    function appendImageUrls(urls) {
        setImageLines(parseImageLines().concat(urls));
    }

    function removeImageUrl(src) {
        src = normalizeStorageUrl(String(src || '').trim());
        var next = parseImageLines().filter(function (u) {
            return normalizeStorageUrl(u) !== src;
        });
        $('#combo-images').val(next.join('\n'));
        refreshImagePreviews();
        persistFormDraft();
    }

    function refreshImagePreviews() {
        var $box = $('#combo-image-previews');
        $box.empty();
        var urls = parseImageLines();
        urls.forEach(function (url, i) {
            var src = normalizeStorageUrl(url);
            var isMain = i === 0;
            var $card = $('<div>', {
                class: 'combo-preview-item relative w-16 shrink-0',
                'data-src': src
            });
            var $btn = $('<button>', {
                type: 'button',
                class: 'combo-preview-thumb block rounded-lg overflow-hidden p-0 border-0 bg-transparent cursor-zoom-in hover:ring-2 hover:ring-teal/40',
                title: 'Ver imagen ampliada',
                'aria-label': 'Ver imagen ampliada',
                'data-src': src,
                'data-caption': isMain ? 'Imagen principal' : ('Imagen ' + (i + 1))
            });
            var $img = $('<img>', {
                src: src + (src.indexOf('?') === -1 ? ('?v=' + Date.now()) : ''),
                alt: '',
                class: 'pointer-events-none block h-16 w-16 rounded-lg border border-line object-cover bg-mist'
            });
            $img.on('error', function () {
                removeImageUrl(src);
            });
            $btn.append($img);
            var $badge = $('<span>', {
                class: 'pointer-events-none absolute top-0.5 left-0.5 rounded px-1 py-px text-[9px] font-bold leading-none ' +
                    (isMain ? 'bg-teal text-white' : 'bg-black/60 text-white')
            }).text(isMain ? '★' : String(i + 1));
            var $remove = $('<button>', {
                type: 'button',
                class: 'combo-preview-remove absolute -top-1.5 -right-1.5 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-ink text-white text-[10px] leading-none hover:bg-rose-600',
                title: 'Quitar imagen',
                'aria-label': 'Quitar imagen',
                'data-src': src
            }).html('&times;');
            var $actions = $('<div>', { class: 'mt-1 flex items-center justify-center gap-0.5' });
            var $up = $('<button>', {
                type: 'button',
                class: 'combo-preview-move inline-flex h-5 w-5 shrink-0 items-center justify-center rounded border border-line p-0 leading-none text-ink-soft hover:bg-mist disabled:opacity-30',
                title: 'Mover a la izquierda',
                'aria-label': 'Mover a la izquierda',
                'data-src': src,
                'data-dir': '-1'
            }).prop('disabled', i === 0).html('<i class="fa-solid fa-chevron-left text-[10px] leading-none"></i>');
            var $star = $('<button>', {
                type: 'button',
                class: 'combo-preview-principal inline-flex h-5 w-5 shrink-0 items-center justify-center rounded border border-line p-0 leading-none hover:bg-teal/10 ' + (isMain ? 'text-teal' : 'text-ink-soft/45'),
                title: isMain ? 'Imagen principal' : 'Hacer principal',
                'aria-label': isMain ? 'Imagen principal' : 'Hacer principal',
                'data-src': src
            }).prop('disabled', isMain).html('<i class="fa-solid fa-star text-[10px] leading-none"></i>');
            var $down = $('<button>', {
                type: 'button',
                class: 'combo-preview-move inline-flex h-5 w-5 shrink-0 items-center justify-center rounded border border-line p-0 leading-none text-ink-soft hover:bg-mist disabled:opacity-30',
                title: 'Mover a la derecha',
                'aria-label': 'Mover a la derecha',
                'data-src': src,
                'data-dir': '1'
            }).prop('disabled', i === urls.length - 1).html('<i class="fa-solid fa-chevron-right text-[10px] leading-none"></i>');
            $actions.append($up, $star, $down);
            $card.append($btn, $badge, $remove, $actions);
            $box.append($card);
        });
    }

    function moveImageUrl(src, dir) {
        src = normalizeStorageUrl(String(src || '').trim());
        var urls = parseImageLines();
        var idx = -1;
        urls.forEach(function (u, i) {
            if (idx === -1 && normalizeStorageUrl(u) === src) idx = i;
        });
        var next = idx + dir;
        if (idx < 0 || next < 0 || next >= urls.length) return;
        var tmp = urls[idx];
        urls[idx] = urls[next];
        urls[next] = tmp;
        $('#combo-images').val(urls.join('\n'));
        refreshImagePreviews();
        persistFormDraft();
    }

    function makeImagePrincipal(src) {
        src = normalizeStorageUrl(String(src || '').trim());
        var rest = parseImageLines().filter(function (u) {
            return normalizeStorageUrl(u) !== src;
        });
        $('#combo-images').val([src].concat(rest).join('\n'));
        refreshImagePreviews();
        persistFormDraft();
    }

    $('#combo-image-previews').on('click', '.combo-preview-remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        removeImageUrl($(this).attr('data-src'));
    });

    $('#combo-image-previews').on('click', '.combo-preview-move', function (e) {
        e.preventDefault();
        e.stopPropagation();
        moveImageUrl($(this).attr('data-src'), parseInt($(this).attr('data-dir'), 10) || 0);
    });

    $('#combo-image-previews').on('click', '.combo-preview-principal', function (e) {
        e.preventDefault();
        e.stopPropagation();
        makeImagePrincipal($(this).attr('data-src'));
    });

    $('#combo-image-previews').on('click', '.combo-preview-thumb', function (e) {
        e.preventDefault();
        openImageModal($(this).attr('data-src') || $(this).find('img').attr('src'), $(this).attr('data-caption') || 'Imagen del combo');
    });

    function selectedBuilderThumbs() {
        var urls = [];
        $list.find('.combo-builder-row img').each(function () {
            var src = $(this).attr('src');
            if (src) urls.push(src);
        });
        return urls;
    }

    function comboPayloadForAi() {
        var ids = addedIds();
        if (!ids.length) return null;
        return {
            product_ids: ids,
            strategy: $('[name=strategy]:checked').val() || 'qty',
            qty_min: parseInt($('input[name=qty_min]').first().val() || '1', 10) || 1,
            discount_type: $('[name=discount_type]:checked').val() || 'percent',
            discount_value: parseFloat($('[name=discount_value]').val() || '0') || 0
        };
    }

    $('#combo-use-product-images').on('click', function () {
        var thumbs = selectedBuilderThumbs();
        if (!thumbs.length) {
            alert('Agrega productos al combo primero.');
            return;
        }
        appendImageUrls(thumbs);
    });

    function comboProductGallery() {
        var groups = [];
        addedIds().forEach(function (id) {
            var product = productsById[String(id)] || productsById[id];
            if (!product) return;
            var urls = [];
            (product.images || []).concat(product.thumb ? [product.thumb] : []).forEach(function (u) {
                u = String(u || '').trim();
                if (u && urls.indexOf(u) === -1) urls.push(u);
            });
            if (urls.length) {
                groups.push({ id: id, name: product.name || ('Producto ' + id), urls: urls });
            }
        });
        return groups;
    }

    function updateProductPhotosCount() {
        var n = $('#combo-product-photos-list .combo-product-photo-check:checked').length;
        $('#combo-product-photos-count').text(n + ' seleccionada' + (n === 1 ? '' : 's'));
    }

    function openProductPhotosModal() {
        var groups = comboProductGallery();
        var $list = $('#combo-product-photos-list');
        $list.empty();
        if (!groups.length) {
            alert('Agrega productos al combo primero.');
            return;
        }
        var current = parseImageLines().map(normalizeStorageUrl);
        groups.forEach(function (group) {
            var $block = $('<div>');
            $block.append($('<p>', { class: 'text-xs font-semibold text-ink mb-2' }).text(group.name));
            var $grid = $('<div>', { class: 'flex flex-wrap gap-2' });
            group.urls.forEach(function (url) {
                var src = normalizeStorageUrl(url);
                var already = current.indexOf(src) !== -1;
                var $item = $('<label>', {
                    class: 'relative cursor-pointer rounded-lg overflow-hidden border ' + (already ? 'border-teal/40 opacity-60' : 'border-line hover:border-teal/50')
                });
                $item.append($('<img>', {
                    src: src,
                    alt: '',
                    class: 'block h-16 w-16 object-cover bg-mist'
                }));
                $item.append($('<input>', {
                    type: 'checkbox',
                    class: 'combo-product-photo-check absolute top-1 left-1 h-3.5 w-3.5 rounded border-line text-teal',
                    value: src,
                    disabled: already,
                    checked: already
                }));
                $grid.append($item);
            });
            $block.append($grid);
            $list.append($block);
        });
        updateProductPhotosCount();
        $('#combo-product-photos-modal').css('display', 'flex');
    }

    function closeProductPhotosModal() {
        $('#combo-product-photos-modal').hide();
    }

    $('#combo-pick-product-images').on('click', function () {
        openProductPhotosModal();
    });
    $('#combo-product-photos-close, #combo-product-photos-cancel').on('click', closeProductPhotosModal);
    $('#combo-product-photos-modal').on('click', function (e) {
        if (e.target === this) closeProductPhotosModal();
    });
    $('#combo-product-photos-list').on('change', '.combo-product-photo-check', updateProductPhotosCount);
    $('#combo-product-photos-add').on('click', function () {
        var picked = [];
        $('#combo-product-photos-list .combo-product-photo-check:checked:not(:disabled)').each(function () {
            picked.push($(this).val());
        });
        if (!picked.length) {
            alert('Marca al menos una foto nueva.');
            return;
        }
        appendImageUrls(picked);
        closeProductPhotosModal();
    });

    function allowedComboImageFile(file) {
        if (!file || !file.type) return false;
        return /image\/(jpeg|jpg|png|gif|webp)/i.test(file.type);
    }

    function namedClipboardImage(file) {
        if (file.name && file.name.indexOf('.') !== -1) return file;
        var ext = 'png';
        if (/jpeg|jpg/i.test(file.type)) ext = 'jpg';
        else if (/webp/i.test(file.type)) ext = 'webp';
        else if (/gif/i.test(file.type)) ext = 'gif';
        return new File([file], 'pegado-' + Date.now() + '.' + ext, { type: file.type || 'image/png' });
    }

    function uploadComboImageFiles(files) {
        var list = [];
        Array.prototype.forEach.call(files || [], function (file) {
            if (allowedComboImageFile(file)) list.push(namedClipboardImage(file));
        });
        if (!list.length) return;
        var $status = $('#combo-upload-status');
        $status.removeClass('hidden text-rose-600 text-ink-soft/60').text('Subiendo…');

        var pending = list.length;
        var uploaded = [];
        var failed = 0;

        list.forEach(function (file) {
            var fd = new FormData();
            fd.append('file', file);
            $.ajax({
                url: uploadUrl,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': csrf },
                success: function (res) {
                    if (res.success && res.url) uploaded.push(res.url);
                    else failed++;
                },
                error: function () { failed++; },
                complete: function () {
                    pending--;
                    if (pending === 0) {
                        if (uploaded.length) appendImageUrls(uploaded);
                        var msg = uploaded.length ? ('✓ ' + uploaded.length + ' imagen(es) subida(s).') : 'No se pudo subir.';
                        if (failed) msg += ' (' + failed + ' error(es))';
                        $status.text(msg).toggleClass('text-rose-600', !uploaded.length);
                        $('#combo-image-upload').val('');
                    }
                }
            });
        });
    }

    function clipboardImageFiles(evt) {
        var dt = (evt.originalEvent || evt).clipboardData;
        if (!dt) return [];
        var files = [];
        if (dt.files && dt.files.length) {
            Array.prototype.forEach.call(dt.files, function (file) {
                if (allowedComboImageFile(file)) files.push(file);
            });
        }
        if (!files.length && dt.items) {
            Array.prototype.forEach.call(dt.items, function (item) {
                if (item.kind === 'file' && allowedComboImageFile({ type: item.type })) {
                    var file = item.getAsFile();
                    if (file) files.push(file);
                }
            });
        }
        return files;
    }

    $('#combo-image-upload').on('change', function () {
        uploadComboImageFiles(this.files);
    });

    $('#combo-form').on('paste', function (e) {
        var files = clipboardImageFiles(e);
        if (!files.length) return;
        var $target = $(e.target);
        var inTextField = $target.is('textarea:visible, input[type=text], input[type=search], input:not([type])');
        var hasText = false;
        try {
            var dt = (e.originalEvent || e).clipboardData;
            hasText = !!(dt && dt.getData && String(dt.getData('text') || '').trim());
        } catch (err) {}
        if (inTextField && hasText) return;
        e.preventDefault();
        uploadComboImageFiles(files);
    });

    $('#combo-ai-copy-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var payload = comboPayloadForAi();
        if (!payload) {
            alert('Selecciona al menos un producto en «Productos del combo» antes de usar IA.');
            return;
        }

        var builderBackup = snapshotBuilderRows();
        savePreAiDraft();
        persistFormDraft();
        aiGenerating = true;

        var $status = $('#combo-ai-status');
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin text-teal"></i> Generando…');
        $status.removeClass('hidden border-rose-200 bg-rose-50 text-rose-700 border-teal/25 bg-teal/5 text-teal')
            .addClass('border-teal/25 bg-teal/5 text-teal').text('MIIA está generando nombre, descripción y prompt de imagen…');

        $.ajax({
            url: $btn.data('url'),
            method: 'POST',
            timeout: 120000,
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (data) {
                if (data.success) {
                    if (data.name) $('#combo-name').val(data.name);
                    if (data.slug) $('#combo-slug').val(data.slug);
                    if (data.description) $('#combo-description').val(data.description);
                    if (data.image_prompt) $('#combo-image-prompt').val(data.image_prompt);

                    var statusHtml = '✓ Copy generado. Revisa el prompt y genera las imágenes promocionales cuando quieras.';
                    if (data.partial_parse) {
                        statusHtml += ' (respuesta sanitizada; revisa los campos).';
                    }

                    $status.removeClass('border-rose-200 bg-rose-50 text-rose-700')
                        .addClass('border-teal/25 bg-teal/5 text-teal')
                        .html(statusHtml + ' <span class="text-ink-soft/70">· Si no te convence, usa «Deshacer cambios de IA».</span>');
                    document.getElementById('sec-combo-copy').scrollIntoView({ behavior: 'smooth', block: 'start' });
                    persistFormDraft();
                } else {
                    restoreBuilderRows(builderBackup);
                    $status.removeClass('border-teal/25 bg-teal/5 text-teal')
                        .addClass('border-rose-200 bg-rose-50 text-rose-700')
                        .text('Error: ' + (data.error || 'Inténtalo de nuevo.'));
                }
            },
            error: function (xhr) {
                restoreBuilderRows(builderBackup);
                var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Error de conexión.';
                $status.removeClass('border-teal/25 bg-teal/5 text-teal')
                    .addClass('border-rose-200 bg-rose-50 text-rose-700')
                    .text('Error: ' + msg);
            },
            complete: function () {
                aiGenerating = false;
                ensureBuilderRowsPersisted();
                persistFormDraft();
                updateDraftBar();
                $btn.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles text-teal"></i> Generar copy con IA');
            }
        });
    });

    function groupPromoSelectionsByStyle(selections) {
        var grouped = {};
        selections.forEach(function (sel) {
            if (!grouped[sel.style]) grouped[sel.style] = [];
            if (grouped[sel.style].indexOf(sel.file) === -1) {
                grouped[sel.style].push(sel.file);
            }
        });
        return grouped;
    }

    function generatePromoImagesByStyle($btn, prompt, ids, templateSelections, total, $status, $progress, $progressBar, $progressText, builderBackup) {
        var grouped = groupPromoSelectionsByStyle(templateSelections);
        var styleSlugs = Object.keys(grouped);
        var index = 0;
        var ok = 0;
        var allUrls = [];
        var allResults = [];

        function applyPromoAjaxResult(data, fallbackStyle) {
            data = data || {};
            var results = Array.isArray(data.results) ? data.results : [];
            var urls = Array.isArray(data.generated_image_urls) ? data.generated_image_urls : [];
            if (results.length) {
                results.forEach(function (r) { allResults.push(r); });
            } else {
                allResults.push({
                    style: fallbackStyle || 'estilo',
                    success: urls.length > 0,
                    error: urls.length ? null : (data.error || 'Error de conexión.'),
                    url: urls[0] || null
                });
            }
            if (urls.length) {
                ok += urls.length;
                allUrls = allUrls.concat(urls);
                persistFormDraft();
            }
        }

        function finish() {
            aiGenerating = false;
            ensureBuilderRowsPersisted();
            persistFormDraft();
            updateDraftBar();
            $btn.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles"></i> Generar imágenes');
            $progressBar.css('width', '100%');

            if (allUrls.length) {
                prependImageUrls(allUrls);
            }

            if (ok > 0) {
                var msg = '✓ ' + ok + ' imagen' + (ok === 1 ? '' : 'es') + ' promocional' + (ok === 1 ? '' : 'es') + ' generada' + (ok === 1 ? '' : 's') + '. Revisa las miniaturas y guarda el combo.';
                if (ok < total) msg += ' (' + (total - ok) + ' estilo(s) fallaron)';
                $status.removeClass('border-rose-200 bg-rose-50 text-rose-700')
                    .addClass('border-teal/25 bg-teal/5 text-teal')
                    .text(msg);
                $progressText.text('Completado: ' + ok + '/' + total + ' estilos');
                if ($('#combo-modify-landing').is(':checked')) {
                    applyComboLanding($status, allResults);
                }
            } else {
                var errMsg = 'No se pudo generar ninguna imagen.';
                var details = allResults.filter(function (r) { return !r.success && r.error; })
                    .map(function (r) { return (r.style || 'estilo') + ': ' + r.error; })
                    .slice(0, 3)
                    .join(' · ');
                if (details) errMsg += ' ' + details;
                $status.removeClass('border-teal/25 bg-teal/5 text-teal')
                    .addClass('border-rose-200 bg-rose-50 text-rose-700')
                    .text('Error: ' + errMsg);
                $progressText.text('Error en la generación');
            }
        }

        function runNext() {
            if (index >= styleSlugs.length) {
                finish();
                return;
            }

            var style = styleSlugs[index];
            var styleSelections = grouped[style].map(function (file) {
                return { style: style, file: file };
            });
            var current = index + 1;

            $progressBar.css('width', Math.max(8, Math.round(((current - 1) / total) * 100)) + '%');
            $progressText.text('Generando estilo ' + current + '/' + total + ' (' + style + ')…');
            $status.text('MIIA está generando el estilo «' + style + '» (' + current + '/' + total + ')…');

            $.ajax({
                url: $btn.data('url'),
                method: 'POST',
                timeout: 300000,
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                contentType: 'application/json',
                data: JSON.stringify({
                    image_prompt: prompt,
                    product_ids: ids,
                    template_selections: styleSelections,
                    strategy: $('[name=strategy]:checked').val() || 'qty',
                    qty_min: parseInt($('input[name=qty_min]').first().val() || '1', 10) || 1,
                    discount_type: $('[name=discount_type]:checked').val() || 'percent',
                    discount_value: parseFloat($('[name=discount_value]').val() || '0') || 0
                }),
                success: function (data) {
                    applyPromoAjaxResult(data);
                },
                error: function (xhr) {
                    applyPromoAjaxResult(xhr.responseJSON || {}, style);
                },
                complete: function () {
                    index++;
                    runNext();
                }
            });
        }

        runNext();
    }

    function comboLandingImagesFromResults(results) {
        var images = [];
        (results || []).forEach(function (r) {
            if (!r || !r.success || !r.url) return;
            images.push({ style: String(r.style || 'imagenes'), url: String(r.url) });
        });
        if (images.length) return images;
        return parseImageLines().map(function (url) {
            return { style: 'imagenes', url: url };
        });
    }

    function applyComboLanding($status, results) {
        var images = comboLandingImagesFromResults(results);
        if (!images.length) {
            alert('Genera o agrega imágenes promocionales antes de modificar la landing.');
            return;
        }
        var ids = addedIds();
        if (!ids.length) {
            alert('Selecciona al menos un producto en «Productos del combo».');
            return;
        }

        var $landingBtn = $('#combo-ai-landing-btn');
        aiGenerating = true;
        $landingBtn.prop('disabled', true);
        if ($status && $status.length) {
            $status.removeClass('hidden border-rose-200 bg-rose-50 text-rose-700')
                .addClass('border-teal/25 bg-teal/5 text-teal')
                .text('MIIA está aplicando las imágenes a la landing (móvil + sitio)…');
        }

        $.ajax({
            url: $landingBtn.data('url'),
            method: 'POST',
            timeout: 120000,
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            contentType: 'application/json',
            data: JSON.stringify({
                name: String($('#combo-name').val() || ''),
                slug: String($('#combo-slug').val() || ''),
                description: String($('#combo-description').val() || ''),
                product_ids: ids,
                images: images,
                combo_id: $('#combo-form').data('combo-id') || null
            }),
            success: function (data) {
                if (data.success) {
                    var msg = data.summary || data.message || 'Landing actualizada.';
                    if ($status && $status.length) {
                        $status.removeClass('border-rose-200 bg-rose-50 text-rose-700')
                            .addClass('border-teal/25 bg-teal/5 text-teal')
                            .text('✓ ' + msg);
                    } else {
                        alert('✓ ' + msg);
                    }
                } else {
                    var err = data.error || 'No se pudo modificar la landing.';
                    if ($status && $status.length) {
                        $status.removeClass('border-teal/25 bg-teal/5 text-teal')
                            .addClass('border-rose-200 bg-rose-50 text-rose-700')
                            .text('Landing: ' + err);
                    } else {
                        alert(err);
                    }
                }
            },
            error: function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Error de conexión al actualizar la landing.';
                if ($status && $status.length) {
                    $status.removeClass('border-teal/25 bg-teal/5 text-teal')
                        .addClass('border-rose-200 bg-rose-50 text-rose-700')
                        .text('Landing: ' + err);
                } else {
                    alert(err);
                }
            },
            complete: function () {
                aiGenerating = false;
                $landingBtn.prop('disabled', false);
            }
        });
    }

    /* ── Generador promocional (modal / multi-estilo) ───── */
    var promoStylesUrl = @json(route('admin.store.combos.promo-styles'));
    var promoTemplatesUrl = @json(route('admin.store.combos.promo-styles.templates', ['style' => '__STYLE__']));
    var promoStylesCache = [];
    var promoTemplatesCache = {};
    var promoSelectedTemplates = [];
    var promoExpandedStyles = {};

    function promoTemplatesEndpoint(style) {
        return promoTemplatesUrl.replace('__STYLE__', encodeURIComponent(style));
    }

    function promoTemplateKey(style, file) {
        return style + '::' + file;
    }

    function syncPromoSelectionFromDom() {
        promoSelectedTemplates = [];
        $('.combo-promo-template-check:checked').each(function () {
            promoSelectedTemplates.push({
                style: String($(this).data('style') || ''),
                file: String($(this).val() || ''),
                label: String($(this).data('label') || ''),
                thumb_url: String($(this).data('thumb') || '')
            });
        });
        updatePromoSelectionCount();
        updateAllPromoStyleBadges();
    }

    function promoSelectedStylesCount() {
        var styles = {};
        promoSelectedTemplates.forEach(function (sel) {
            if (sel.style) styles[sel.style] = true;
        });
        return Object.keys(styles).length;
    }

    function updatePromoSelectionCount() {
        var templates = promoSelectedTemplates.length;
        var styles = promoSelectedStylesCount();
        var text = templates + ' plantilla' + (templates === 1 ? '' : 's') + ' en ' + styles + ' estilo' + (styles === 1 ? '' : 's');
        if (styles > 0) {
            text += ' → ' + styles + ' imagen' + (styles === 1 ? '' : 'es') + ' a generar (1 por estilo)';
        }
        $('#combo-promo-selection-summary').text(text);
        $('#combo-promo-modal-count').text(text);
    }

    function promoSelectedCountInStyle(style) {
        return promoSelectedTemplates.filter(function (sel) {
            return sel.style === style;
        }).length;
    }

    function updatePromoStyleSectionBadge(style) {
        var n = promoSelectedCountInStyle(style);
        var $badge = $('.combo-promo-style-section[data-style="' + style + '"] .combo-promo-style-selected-count');
        if (!$badge.length) return;
        if (n > 0) {
            $badge.removeClass('hidden').text(n + ' ref.');
        } else {
            $badge.addClass('hidden').text('');
        }
    }

    function updateAllPromoStyleBadges() {
        promoStylesCache.forEach(function (style) {
            updatePromoStyleSectionBadge(style.slug);
        });
    }

    function isPromoStyleExpanded(style) {
        return !!promoExpandedStyles[style];
    }

    function setPromoStyleExpanded(style, expanded) {
        var $section = $('.combo-promo-style-section[data-style="' + style + '"]');
        var $body = $section.find('[data-style-body="' + style + '"]');
        var $chevron = $section.find('.combo-promo-chevron');

        if (expanded) {
            promoExpandedStyles[style] = true;
            $body.removeClass('hidden');
            $chevron.addClass('rotate-90');
            $section.find('.combo-promo-style-toggle').attr('aria-expanded', 'true');
            $section.addClass('is-expanded');
        } else {
            delete promoExpandedStyles[style];
            $body.addClass('hidden');
            $chevron.removeClass('rotate-90');
            $section.find('.combo-promo-style-toggle').attr('aria-expanded', 'false');
            $section.removeClass('is-expanded');
        }
    }

    function expandPromoStyle(style) {
        var $body = $('[data-style-body="' + style + '"]');
        var needsLoad = !promoTemplatesCache[style];

        setPromoStyleExpanded(style, true);

        if (needsLoad && !$body.find('.combo-promo-template-item').length) {
            $body.removeClass('hidden').html('<p class="text-[11px] text-ink-soft/55 text-center py-3">Cargando plantillas…</p>');
        }

        loadPromoTemplatesForStyle(style);
    }

    function togglePromoStyle(style) {
        if (isPromoStyleExpanded(style)) {
            setPromoStyleExpanded(style, false);
        } else {
            expandPromoStyle(style);
        }
    }

    function restorePromoModalState() {
        var stylesToOpen = {};
        promoSelectedTemplates.forEach(function (sel) {
            if (sel.style) stylesToOpen[sel.style] = true;
        });
        Object.keys(promoExpandedStyles).forEach(function (style) {
            stylesToOpen[style] = true;
        });

        Object.keys(stylesToOpen).forEach(function (style) {
            expandPromoStyle(style);
        });
        updateAllPromoStyleBadges();
    }

    function openPromoModal() {
        $('#combo-promo-modal').css('display', 'flex');
        if (!promoStylesCache.length) {
            loadPromoStylesList(function () {
                restorePromoModalState();
            });
        } else {
            restorePromoModalState();
        }
    }

    function closePromoModal() {
        $('#combo-promo-modal').hide();
    }

    function renderPromoStylesList(styles) {
        var $list = $('#combo-promo-styles-list');
        $list.empty();

        if (!styles.length) {
            $list.html('<p class="text-xs text-ink-soft/60 text-center py-6">No hay estilos disponibles.</p>');
            return;
        }

        styles.forEach(function (style) {
            var slug = style.slug;
            var $section = $('<div>', {
                class: 'combo-promo-style-section rounded-xl border border-line bg-white overflow-hidden',
                'data-style': slug,
                'data-search': (style.label + ' ' + slug).toLowerCase()
            });

            var $header = $('<div>', {
                class: 'combo-promo-style-header flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-mist/30 select-none'
            });

            var $toggle = $('<button>', {
                type: 'button',
                class: 'combo-promo-style-toggle shrink-0 w-7 h-7 flex items-center justify-center rounded-lg text-ink-soft/60 hover:bg-mist hover:text-ink',
                'data-style': slug,
                'aria-expanded': 'false',
                title: 'Expandir / comprimir'
            }).append(
                $('<i>', { class: 'fa-solid fa-chevron-right text-xs transition-transform duration-200 combo-promo-chevron' })
            );

            var $meta = $('<div>', { class: 'flex-1 min-w-0' }).append(
                $('<span>', { class: 'block text-sm font-medium text-ink truncate', text: style.label }),
                $('<span>', { class: 'block text-[11px] text-ink-soft/55', text: style.template_count + ' plantilla' + (style.template_count === 1 ? '' : 's') })
            );

            var $badge = $('<span>', {
                class: 'combo-promo-style-selected-count hidden shrink-0 rounded-full bg-teal/10 text-teal text-[10px] font-medium px-2 py-0.5'
            });

            $header.append($toggle, $meta, $badge);

            var $body = $('<div>', {
                class: 'combo-promo-style-body hidden px-3 py-3 bg-mist/10 border-t border-line/40',
                'data-style-body': slug
            });

            $section.append($header, $body);
            $list.append($section);
        });
    }

    function renderPromoTemplateGrid(style, templates) {
        var $body = $('[data-style-body="' + style + '"]');
        $body.empty();

        if (!templates.length) {
            if (isPromoStyleExpanded(style)) {
                $body.removeClass('hidden').html('<p class="text-[11px] text-ink-soft/55 text-center py-3">Sin plantillas en este estilo.</p>');
            }
            return;
        }

        var styleMeta = promoStylesCache.find(function (s) { return s.slug === style; });
        var styleLabel = styleMeta ? styleMeta.label : style;
        var $grid = $('<div>', { class: 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3' });

        templates.forEach(function (tpl) {
            var key = promoTemplateKey(style, tpl.file);
            var isChecked = promoSelectedTemplates.some(function (sel) {
                return promoTemplateKey(sel.style, sel.file) === key;
            });

            var $item = $('<label>', {
                class: 'combo-promo-template-item relative flex flex-col items-center gap-1.5 cursor-pointer rounded-xl border border-line/80 bg-white p-1.5 hover:border-teal/40 has-[:checked]:border-teal has-[:checked]:bg-teal/5'
            });

            $item.append(
                $('<input>', {
                    type: 'checkbox',
                    class: 'combo-promo-template-check absolute top-2 left-2 h-4 w-4 rounded border-line text-teal z-10',
                    value: tpl.file,
                    'data-style': style,
                    'data-label': tpl.label,
                    'data-thumb': tpl.thumb_url,
                    checked: isChecked
                }),
                $('<span>', {
                    class: 'block w-full rounded-lg overflow-hidden bg-mist'
                }).append(
                    $('<img>', {
                        src: tpl.thumb_url,
                        alt: tpl.label,
                        class: 'pointer-events-none w-full h-[250px] object-cover',
                        loading: 'lazy'
                    })
                ),
                $('<button>', {
                    type: 'button',
                    class: 'combo-promo-template-thumb absolute top-2 right-2 z-10 h-7 w-7 rounded-lg bg-white/90 border border-line text-ink-soft hover:text-ink',
                    'data-thumb': tpl.thumb_url,
                    'data-label': styleLabel + ' · ' + tpl.label,
                    title: 'Ver ampliada',
                    'aria-label': 'Ver ampliada'
                }).html('<i class="fa-solid fa-magnifying-glass text-[10px]"></i>'),
                $('<span>', {
                    class: 'block w-full text-[10px] leading-tight text-ink-soft/70 truncate text-center px-1',
                    text: tpl.label
                })
            );

            $grid.append($item);
        });

        $body.empty().append($grid);
        if (isPromoStyleExpanded(style)) {
            $body.removeClass('hidden');
        } else {
            $body.addClass('hidden');
        }
        updatePromoStyleSectionBadge(style);
    }

    function loadPromoStylesList(done) {
        $.getJSON(promoStylesUrl)
            .done(function (data) {
                promoStylesCache = (data.success && data.styles) ? data.styles : [];
                renderPromoStylesList(promoStylesCache);
                if (typeof done === 'function') done();
            })
            .fail(function () {
                $('#combo-promo-styles-list').html('<p class="text-xs text-rose-600 text-center py-6">Error al cargar estilos.</p>');
            });
    }

    function loadPromoTemplatesForStyle(style) {
        if (promoTemplatesCache[style]) {
            renderPromoTemplateGrid(style, promoTemplatesCache[style]);
            return;
        }

        $.getJSON(promoTemplatesEndpoint(style))
            .done(function (data) {
                var templates = (data.success && data.templates) ? data.templates : [];
                promoTemplatesCache[style] = templates;
                renderPromoTemplateGrid(style, templates);
            })
            .fail(function () {
                renderPromoTemplateGrid(style, []);
            });
    }

    $('#combo-promo-open-modal').on('click', function (e) {
        e.preventDefault();
        openPromoModal();
    });

    $('#combo-promo-modal-close, #combo-promo-modal-cancel').on('click', function (e) {
        e.preventDefault();
        closePromoModal();
    });

    $('#combo-promo-modal').on('click', function (e) {
        if (e.target === this) closePromoModal();
    });

    $(document).on('click', '.combo-promo-style-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var style = String($(this).closest('.combo-promo-style-section').data('style') || '');
        if (!style) return;
        togglePromoStyle(style);
    });

    $(document).on('click', '.combo-promo-style-header', function (e) {
        e.preventDefault();
        var style = String($(this).closest('.combo-promo-style-section').data('style') || '');
        if (!style) return;
        togglePromoStyle(style);
    });

    $('#combo-promo-expand-all').on('click', function (e) {
        e.preventDefault();
        promoStylesCache.forEach(function (style) {
            expandPromoStyle(style.slug);
        });
    });

    $('#combo-promo-collapse-all').on('click', function (e) {
        e.preventDefault();
        Object.keys(promoExpandedStyles).slice().forEach(function (style) {
            setPromoStyleExpanded(style, false);
        });
    });

    $(document).on('change', '.combo-promo-template-check', function () {
        syncPromoSelectionFromDom();
        schedulePersistFormDraft();
    });

    $('#combo-ai-landing-btn').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (! $('#combo-modify-landing').is(':checked')) {
            $('#combo-modify-landing').prop('checked', true);
        }
        applyComboLanding($('#combo-ai-image-status').removeClass('hidden'), []);
    });

    $(document).on('click', '.combo-promo-template-thumb', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var src = String($(this).data('thumb') || '');
        var label = String($(this).data('label') || '');
        $('#combo-promo-preview-img').attr('src', src);
        $('#combo-promo-preview-label').text(label);
        $('#combo-promo-preview-modal').css('display', 'flex');
    });

    $('#combo-promo-preview-close, #combo-promo-preview-modal').on('click', function (e) {
        if (e.target === this) {
            $('#combo-promo-preview-modal').hide();
        }
    });

    $('#combo-promo-style-search').on('input', function () {
        var q = String($(this).val() || '').toLowerCase().trim();
        $('.combo-promo-style-section').each(function () {
            var hay = String($(this).data('search') || '');
            $(this).toggle(q === '' || hay.indexOf(q) !== -1);
        });
    });

    function showPromoModalError(msg) {
        $('#combo-promo-modal-error').removeClass('hidden').text(msg);
    }

    function clearPromoModalError() {
        $('#combo-promo-modal-error').addClass('hidden').text('');
    }

    $(document).on('click', '#combo-ai-promo-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        clearPromoModalError();

        if (aiGenerating) {
            showPromoModalError('Ya hay una generación en curso. Espera a que termine.');
            return;
        }
        if ($btn.prop('disabled')) {
            showPromoModalError('Configura la API Key de MIIA en General para generar imágenes.');
            return;
        }

        var prompt = String($('#combo-image-prompt').val() || '').trim();
        if (!prompt) {
            showPromoModalError('Escribe o genera primero el prompt de imagen (en Copy del combo).');
            return;
        }

        var ids = addedIds();
        if (!ids.length) {
            showPromoModalError('Selecciona al menos un producto en «Productos del combo».');
            return;
        }

        syncPromoSelectionFromDom();
        var styleCount = promoSelectedStylesCount();
        if (!styleCount) {
            showPromoModalError('Marca al menos una plantilla de referencia (clic en la foto del estilo).');
            return;
        }

        try {
            var builderBackup = snapshotBuilderRows();
            savePreAiDraft();
            persistFormDraft();
            aiGenerating = true;

            var templateSelections = promoSelectedTemplates.map(function (t) {
                return { style: t.style, file: t.file };
            });
            var total = styleCount;
            var $status = $('#combo-ai-image-status');
            var $progress = $('#combo-promo-progress');
            var $progressBar = $('#combo-promo-progress-bar');
            var $progressText = $('#combo-promo-progress-text');

            closePromoModal();

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Generando…');
            $status.removeClass('hidden border-rose-200 bg-rose-50 text-rose-700 border-teal/25 bg-teal/5 text-teal')
                .addClass('border-teal/25 bg-teal/5 text-teal')
                .text('MIIA está creando ' + total + ' imagen' + (total === 1 ? '' : 'es') + ' promocional' + (total === 1 ? '' : 'es') + ' (1 por estilo). Puede tardar varios minutos…');
            $progress.removeClass('hidden');
            $progressBar.css('width', '5%');
            $progressText.text('Generando estilos 0/' + total + '…');

            generatePromoImagesByStyle(
                $btn, prompt, ids, templateSelections, total,
                $status, $progress, $progressBar, $progressText, builderBackup
            );
        } catch (err) {
            aiGenerating = false;
            $btn.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles"></i> Generar imágenes');
            showPromoModalError('No se pudo iniciar la generación. Recarga e inténtalo de nuevo.');
        }
    });

    updatePromoSelectionCount();
    restoreFormDraftOnLoad();
    schedulePersistFormDraft();

    $('#combo-images').on('input', refreshImagePreviews);
})(jQuery);
</script>
@endpush
