<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\RouletteSlide;
use App\Services\Admin\StoreContext;
use App\Services\Commerce\RouletteWheelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouletteController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext, RouletteWheelService $wheel)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $slides = RouletteSlide::query()
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $wheelCfg = $wheel->forStore($store);

        return view('admin.store.roulette.index', compact('store', 'slides', 'wheelCfg'));
    }

    public function updateWheel(Request $request, StoreContext $storeContext, RouletteWheelService $wheel)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:80'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'auto_open' => ['nullable', 'boolean'],
            'auto_open_delay_ms' => ['nullable', 'integer', 'min:500', 'max:30000'],
            'spin_ms' => ['nullable', 'integer', 'min:2500', 'max:12000'],
            'prizes' => ['nullable', 'array', 'max:12'],
            'prizes.*.label' => ['nullable', 'string', 'max:40'],
            'prizes.*.color' => ['nullable', 'string', 'max:20'],
            'prizes.*.weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'prizes.*.code' => ['nullable', 'string', 'max:40'],
        ]);

        $normalized = $wheel->normalize([
            'headline' => $data['headline'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'auto_open' => $request->boolean('auto_open'),
            'auto_open_delay_ms' => $data['auto_open_delay_ms'] ?? 1800,
            'spin_ms' => $data['spin_ms'] ?? 4800,
            'prizes' => $data['prizes'] ?? [],
        ]);

        $toStore = $normalized;
        $toStore['prizes'] = array_map(static function ($p) {
            return [
                'label' => $p['label'],
                'color' => $p['color'],
                'weight' => $p['weight'],
                'code' => $p['code'],
            ];
        }, $normalized['prizes']);

        $settings = $store->settings ?? [];
        $settings['roulette_wheel'] = $toStore;
        $store->settings = $settings;
        $store->save();

        return back()->with('success', 'Ruleta de premios guardada.');
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.roulette.form', [
            'store' => $store,
            'slide' => new RouletteSlide([
                'theme_class' => 's1',
                'cta_label' => 'Ver ofertas',
                'cta_url' => '#shop',
                'sort_order' => 0,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request);
        $data['store_id'] = $store->id;
        $data['is_active'] = $request->boolean('is_active');
        RouletteSlide::create($data);

        return redirect()->route('admin.store.roulette.index')->with('success', 'Slide creado.');
    }

    public function edit(RouletteSlide $roulette, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $roulette->store_id === (int) $store->id, 404);

        return view('admin.store.roulette.form', [
            'store' => $store,
            'slide' => $roulette,
        ]);
    }

    public function update(Request $request, RouletteSlide $roulette, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $roulette->store_id === (int) $store->id, 404);
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');
        $roulette->update($data);

        return redirect()->route('admin.store.roulette.index')->with('success', 'Slide actualizado.');
    }

    public function destroy(RouletteSlide $roulette, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $roulette->store_id === (int) $store->id, 404);
        $roulette->delete();

        return redirect()->route('admin.store.roulette.index')->with('success', 'Slide eliminado.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'kicker' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:190'],
            'text' => ['nullable', 'string', 'max:1000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'theme_class' => ['required', Rule::in(['s1', 's2', 's3', 's4', 's5'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }
}
