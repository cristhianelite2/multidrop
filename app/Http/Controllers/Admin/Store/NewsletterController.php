<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Models\Store;
use App\Services\Admin\StoreContext;
use App\Services\Commerce\NewsletterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterController extends Controller
{
    use ResolvesCurrentStore;

    public function edit(Request $request, StoreContext $storeContext, NewsletterService $newsletter)
    {
        $store = $this->currentStoreOrFail($storeContext);
        [$query, $status, $q] = $this->filteredQuery($store, $request);
        $tab = $this->tab($request);

        if ($status === 'confirmed') {
            $query->orderByDesc('confirmed_at')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $counts = NewsletterSubscriber::query()
            ->where('store_id', $store->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('admin.store.newsletter.edit', [
            'store' => $store,
            'cfg' => $newsletter->forStore($store),
            'subscribers' => $query->paginate(30)->withQueryString(),
            'status' => $status,
            'q' => $q,
            'tab' => $tab,
            'confirmedCount' => (int) ($counts['confirmed'] ?? 0),
            'pendingCount' => (int) ($counts['pending'] ?? 0),
            'unsubscribedCount' => (int) ($counts['unsubscribed'] ?? 0),
            'totalCount' => (int) $counts->sum(),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext, NewsletterService $newsletter)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:80'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'cta' => ['required', 'string', 'max:40'],
            'success_message' => ['nullable', 'string', 'max:240'],
            'coupon_type' => ['required', 'in:percent,fixed'],
            'coupon_value' => ['required', 'numeric', 'min:1', 'max:10000'],
            'coupon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'coupon_prefix' => ['nullable', 'string', 'max:8'],
            'position' => ['required', 'in:bottom-left,bottom-right'],
            'auto_open' => ['nullable', 'boolean'],
            'auto_open_delay_ms' => ['nullable', 'integer', 'min:800', 'max:30000'],
            'checkout_enabled' => ['nullable', 'boolean'],
            'checkout_label' => ['nullable', 'string', 'max:220'],
        ]);

        $settings = $store->settings ?? [];
        $settings['newsletter'] = $newsletter->normalize([
            ...$data,
            'auto_open' => $request->boolean('auto_open'),
            'checkout_enabled' => $request->boolean('checkout_enabled'),
        ]);
        $store->settings = $settings;
        $store->save();

        return redirect()
            ->route('admin.store.newsletter.edit', ['tab' => 'config'])
            ->with('success', 'Newsletter guardado.');
    }

    public function export(Request $request, StoreContext $storeContext): StreamedResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        [$query, $status] = $this->filteredQuery($store, $request);
        $query->orderBy('email');
        $suffix = $status === 'all' ? 'todos' : $status;
        $filename = 'newsletter-'.$store->slug.'-'.$suffix.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'status', 'source', 'coupon_code', 'confirmed_at', 'created_at']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $s) {
                    fputcsv($out, [
                        $s->email,
                        $s->status,
                        $s->source,
                        $s->coupon_code,
                        optional($s->confirmed_at)?->toDateTimeString(),
                        optional($s->created_at)?->toDateTimeString(),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Builder, 1: string, 2: string}
     */
    protected function filteredQuery(Store $store, Request $request): array
    {
        $status = (string) $request->query('status', 'confirmed');
        if (! in_array($status, ['confirmed', 'pending', 'unsubscribed', 'all'], true)) {
            $status = 'confirmed';
        }
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }

        $query = NewsletterSubscriber::query()->where('store_id', $store->id);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $query->where('email', 'like', '%'.addcslashes($q, '%_\\').'%');
        }

        return [$query, $status, $q];
    }

    protected function tab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'list');

        return in_array($tab, ['list', 'config'], true) ? $tab : 'list';
    }
}
