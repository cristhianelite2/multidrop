<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Admin\StoreContext;

class MarketingController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $this->currentStoreOrFail($storeContext);

        return redirect()->route('admin.store.marketing.campaigns.index');
    }
}
