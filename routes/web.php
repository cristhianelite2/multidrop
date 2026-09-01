<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\LabController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\Store\ComboController;
use App\Http\Controllers\Admin\Store\CrossSellController;
use App\Http\Controllers\Admin\Store\CustomerController;
use App\Http\Controllers\Admin\Store\DesignController;
use App\Http\Controllers\Admin\Store\OrderController;
use App\Http\Controllers\Admin\Store\ProductController;
use App\Http\Controllers\Admin\Store\PromotionController;
use App\Http\Controllers\Admin\Store\RouletteController;
use App\Http\Controllers\Admin\Store\CookieConsentController;
use App\Http\Controllers\Admin\Store\Marketing\CampaignController as MarketingCampaignController;
use App\Http\Controllers\Admin\Store\Marketing\CreatifyController as MarketingCreatifyController;
use App\Http\Controllers\Admin\Store\Marketing\MarketingController;
use App\Http\Controllers\Admin\Store\Marketing\PromptController as MarketingPromptController;
use App\Http\Controllers\Admin\Store\Marketing\VideoController as MarketingVideoController;
use App\Http\Controllers\Admin\Store\NewsletterController;
use App\Http\Controllers\Admin\Store\SocialProofController;
use App\Http\Controllers\Admin\Store\StoreGeneralController;
use App\Http\Controllers\Admin\Store\UrgencyController;
use App\Http\Controllers\Admin\Store\UpsellController;
use App\Http\Controllers\Admin\StoreContextController;
use App\Http\Controllers\Admin\StoreManageController;
use App\Http\Controllers\Admin\SandboxOrderController;
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Buyer\BuyerAuthController;
use App\Http\Controllers\Buyer\BuyerPortalController;
use App\Http\Controllers\Admin\Store\OrderClaimController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\Storefront\CjMediaController;
use App\Http\Controllers\Storefront\CustomDesignController;
use App\Http\Controllers\Storefront\NewsletterController as StorefrontNewsletterController;
use App\Http\Controllers\Storefront\OrderTrackController;
use App\Http\Controllers\Storefront\PaymentWebhookController;
use App\Http\Controllers\Storefront\StoreController;
use App\Http\Controllers\Storefront\ThemeSandboxBuyerController;
use App\Http\Controllers\Storefront\ThemeSandboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'home'])->name('store.home');
Route::get('/p/{slug}', [StoreController::class, 'show'])->name('store.product');
Route::post('/coupon/validate', [StoreController::class, 'validateCoupon'])->name('store.coupon');

Route::get('/media/cj-video', [CjMediaController::class, 'video'])->name('store.media.cj-video');
Route::get('/f/{path}', [MediaFileController::class, 'show'])->where('path', '.*')->name('media.file');
Route::get('/s/{slug}', [CustomDesignController::class, 'show'])->name('store.design.show');
Route::get('/s/{slug}/pages/{handle}', [CustomDesignController::class, 'page'])->name('store.design.page');
Route::get('/s/{slug}/products.json', [CustomDesignController::class, 'products'])->name('store.design.products');

Route::get('/s/{slug}/cart.json', [CartController::class, 'show'])->name('store.cart.show');
Route::post('/s/{slug}/cart/items', [CartController::class, 'add'])->name('store.cart.add');
Route::match(['patch', 'put'], '/s/{slug}/cart/items/{product}', [CartController::class, 'update'])->name('store.cart.update');
Route::delete('/s/{slug}/cart/items/{product}', [CartController::class, 'remove'])->name('store.cart.remove');
Route::post('/s/{slug}/cart/coupon', [CartController::class, 'applyCoupon'])->name('store.cart.coupon');
Route::delete('/s/{slug}/cart/coupon', [CartController::class, 'clearCoupon'])->name('store.cart.coupon.clear');
Route::post('/s/{slug}/cart/shipping', [CartController::class, 'setShipping'])->name('store.cart.shipping');
Route::post('/s/{slug}/cart/cross-sell', [CartController::class, 'addCrossSell'])->name('store.cart.cross-sell');
Route::post('/s/{slug}/cart/upsell', [CartController::class, 'addUpsell'])->name('store.cart.upsell');
Route::post('/s/{slug}/newsletter/subscribe', [StorefrontNewsletterController::class, 'subscribe'])->name('store.newsletter.subscribe');
Route::get('/s/{slug}/newsletter/confirm/{token}', [StorefrontNewsletterController::class, 'confirm'])->name('store.newsletter.confirm');
Route::post('/s/{slug}/checkout', [CheckoutController::class, 'place'])
    ->middleware('throttle:20,1')
    ->name('store.checkout.place');
Route::get('/s/{slug}/checkout/return/{status}', [CheckoutController::class, 'returned'])->name('store.checkout.return');
Route::get('/s/{slug}/pedido', [OrderTrackController::class, 'show'])->name('store.order.track');
Route::post('/s/{slug}/pedido', [OrderTrackController::class, 'lookup'])
    ->middleware('throttle:30,1')
    ->name('store.order.track.lookup');
Route::get('/s/{slug}/cuenta/entrar', [BuyerAuthController::class, 'enterFromTrack'])
    ->middleware('throttle:20,1')
    ->name('buyer.track.enter');

Route::prefix('cuenta')->name('buyer.')->group(function () {
    Route::middleware('guest:buyer')->group(function () {
        Route::get('/login', [BuyerAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [BuyerAuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('login.attempt');
    });
    Route::post('/logout', [BuyerAuthController::class, 'logout'])->name('logout');

    Route::middleware('auth:buyer')->group(function () {
        Route::get('/', [BuyerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/perfil', [BuyerPortalController::class, 'profile'])->name('profile');
        Route::put('/perfil', [BuyerPortalController::class, 'updateProfile'])->name('profile.update');
        Route::get('/seguridad', [BuyerPortalController::class, 'security'])->name('security');
        Route::put('/seguridad/password', [BuyerPortalController::class, 'updatePassword'])->name('security.password');
        Route::get('/compras', [BuyerPortalController::class, 'orders'])->name('orders.index');
        Route::get('/compras/{order}', [BuyerPortalController::class, 'showOrder'])->name('orders.show');
        Route::get('/seguimiento', [BuyerPortalController::class, 'tracking'])->name('tracking');
        Route::get('/reclamos', [BuyerPortalController::class, 'claims'])->name('claims.index');
        Route::post('/reclamos', [BuyerPortalController::class, 'storeClaim'])->name('claims.store');
        Route::get('/reclamos/{claim}', [BuyerPortalController::class, 'showClaim'])->name('claims.show');
    });
});

Route::prefix('t/{theme:slug}')->name('theme.sandbox.')->group(function () {
    Route::get('/', [ThemeSandboxController::class, 'show'])->name('show');
    Route::get('/pages/{handle}', [ThemeSandboxController::class, 'page'])->name('page');
    Route::get('/products.json', [ThemeSandboxController::class, 'products'])->name('products');
    Route::get('/cart.json', [ThemeSandboxController::class, 'cartShow'])->name('cart.show');
    Route::post('/cart/items', [ThemeSandboxController::class, 'cartAdd'])->name('cart.add');
    Route::match(['patch', 'put'], '/cart/items/{product}', [ThemeSandboxController::class, 'cartUpdate'])->name('cart.update');
    Route::delete('/cart/items/{product}', [ThemeSandboxController::class, 'cartRemove'])->name('cart.remove');
    Route::post('/cart/coupon', [ThemeSandboxController::class, 'cartCoupon'])->name('cart.coupon');
    Route::delete('/cart/coupon', [ThemeSandboxController::class, 'cartClearCoupon'])->name('cart.coupon.clear');
    Route::post('/cart/shipping', [ThemeSandboxController::class, 'cartShipping'])->name('cart.shipping');
    Route::post('/cart/cross-sell', [ThemeSandboxController::class, 'cartCrossSell'])->name('cart.cross-sell');
    Route::post('/cart/upsell', [ThemeSandboxController::class, 'cartUpsell'])->name('cart.upsell');
    Route::post('/newsletter/subscribe', [ThemeSandboxController::class, 'newsletterSubscribe'])->name('newsletter.subscribe');
    Route::get('/newsletter/confirm/{token}', [ThemeSandboxController::class, 'newsletterConfirm'])->name('newsletter.confirm');
    Route::post('/checkout', [ThemeSandboxController::class, 'checkout'])->name('checkout');
    Route::get('/gracias', [ThemeSandboxController::class, 'confirm'])->name('confirm');
    Route::get('/pedido', [ThemeSandboxController::class, 'track'])->name('track');
    Route::post('/pedido', [ThemeSandboxController::class, 'trackLookup'])->name('track.lookup');
    Route::post('/pedido/logout', [ThemeSandboxController::class, 'trackLogout'])->name('track.logout');

    Route::get('/cuenta/entrar', [ThemeSandboxBuyerController::class, 'enter'])
        ->middleware('throttle:20,1')
        ->name('cuenta.enter');
    Route::post('/cuenta/salir', [ThemeSandboxBuyerController::class, 'logout'])->name('cuenta.logout');

    Route::middleware('sandbox.buyer')->prefix('cuenta')->name('cuenta.')->group(function () {
        Route::get('/', [ThemeSandboxBuyerController::class, 'dashboard'])->name('dashboard');
        Route::get('/perfil', [ThemeSandboxBuyerController::class, 'profile'])->name('profile');
        Route::put('/perfil', [ThemeSandboxBuyerController::class, 'updateProfile'])->name('profile.update');
        Route::get('/seguridad', [ThemeSandboxBuyerController::class, 'security'])->name('security');
        Route::put('/seguridad/password', [ThemeSandboxBuyerController::class, 'updatePassword'])->name('security.password');
        Route::get('/compras', [ThemeSandboxBuyerController::class, 'orders'])->name('orders');
        Route::get('/compras/{order}', [ThemeSandboxBuyerController::class, 'showOrder'])->name('orders.show');
        Route::get('/seguimiento', [ThemeSandboxBuyerController::class, 'tracking'])->name('tracking');
        Route::post('/seguimiento/avisos', [ThemeSandboxBuyerController::class, 'updateTrackingNotify'])->name('tracking.notify');
        Route::get('/reclamos', [ThemeSandboxBuyerController::class, 'claims'])->name('claims');
        Route::post('/reclamos', [ThemeSandboxBuyerController::class, 'storeClaim'])->name('claims.store');
        Route::get('/reclamos/{claim}', [ThemeSandboxBuyerController::class, 'showClaim'])->name('claims.show');
    });
});

Route::post('/webhooks/mercadopago', [PaymentWebhookController::class, 'mercadopago'])->name('webhooks.mercadopago');
Route::post('/webhooks/paypal', [PaymentWebhookController::class, 'paypal'])->name('webhooks.paypal');
Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'stripe'])->name('webhooks.stripe');

Route::get('/mega', function () {
    $miniStores = \Illuminate\Support\Facades\DB::table('stores')
        ->where('store_type', 'mini')
        ->where('status', '!=', 'archived')
        ->orderBy('sector')
        ->get();

    return view('storefront.mega', compact('miniStores'));
})->name('mega');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    });

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');

    Route::match(['POST', 'OPTIONS'], '/lab/cj/plugin-capture', [LabController::class, 'pluginCapture'])
        ->name('lab.cj.plugin-capture');
    Route::match(['POST', 'OPTIONS'], '/lab/cj/plugin-bootstrap', [LabController::class, 'pluginBootstrap'])
        ->name('lab.cj.plugin-bootstrap');

    Route::middleware(['cloudflare.access', 'auth', 'admin.active', 'admin.store'])->group(function () {
        Route::middleware('permission:admin.access,lab.dashboard')->group(function () {
            Route::get('/', fn () => redirect()->route('admin.dashboard'));
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/lab', fn () => redirect()->route('admin.dashboard'))->name('lab.dashboard');
        });

        Route::post('/context/store', [StoreContextController::class, 'switch'])
            ->name('context.store');

        Route::middleware('permission:store.manage')->group(function () {
            Route::get('/stores/{store}/manage', [StoreManageController::class, 'enter'])->name('stores.manage');
            Route::post('/stores/switch-manage', [StoreManageController::class, 'switchAndStay'])->name('stores.switch-manage');

            Route::prefix('store')->name('store.')->group(function () {
                Route::get('/', [StoreManageController::class, 'hub'])->name('hub');
                Route::get('/stats', [StoreManageController::class, 'stats'])->name('stats');

                Route::prefix('marketing')->name('marketing.')->group(function () {
                    Route::get('/', [MarketingController::class, 'index'])->name('index');
                    Route::get('campaigns', [MarketingCampaignController::class, 'index'])->name('campaigns.index');
                    Route::get('campaigns/create', [MarketingCampaignController::class, 'create'])->name('campaigns.create');
                    Route::post('campaigns', [MarketingCampaignController::class, 'store'])->name('campaigns.store');
                    Route::get('campaigns/{campaign}', [MarketingCampaignController::class, 'edit'])->name('campaigns.edit');
                    Route::put('campaigns/{campaign}', [MarketingCampaignController::class, 'update'])->name('campaigns.update');
                    Route::delete('campaigns/{campaign}', [MarketingCampaignController::class, 'destroy'])->name('campaigns.destroy');
                    Route::post('campaigns/{campaign}/duplicate', [MarketingCampaignController::class, 'duplicate'])->name('campaigns.duplicate');
                    Route::post('campaigns/{campaign}/draft', [MarketingCampaignController::class, 'draft'])->name('campaigns.draft');
                    Route::post('campaigns/{campaign}/insights', [MarketingCampaignController::class, 'insights'])->name('campaigns.insights');
                    Route::post('campaigns/{campaign}/targets', [MarketingCampaignController::class, 'targets'])->name('campaigns.targets');
                    Route::post('campaigns/{campaign}/optimize', [MarketingCampaignController::class, 'optimize'])->name('campaigns.optimize');
                    Route::get('campaigns/{campaign}/brief.json', [MarketingCampaignController::class, 'brief'])->name('campaigns.brief');
                    Route::get('prompts', [MarketingPromptController::class, 'index'])->name('prompts.index');
                    Route::get('prompts/create', [MarketingPromptController::class, 'create'])->name('prompts.create');
                    Route::post('prompts', [MarketingPromptController::class, 'store'])->name('prompts.store');
                    Route::get('prompts/{prompt}', [MarketingPromptController::class, 'edit'])->name('prompts.edit');
                    Route::put('prompts/{prompt}', [MarketingPromptController::class, 'update'])->name('prompts.update');
                    Route::delete('prompts/{prompt}', [MarketingPromptController::class, 'destroy'])->name('prompts.destroy');
                    Route::get('videos', [MarketingVideoController::class, 'index'])->name('videos.index');
                    Route::post('videos', [MarketingVideoController::class, 'store'])->name('videos.store');
                    Route::put('videos/{video}', [MarketingVideoController::class, 'update'])->name('videos.update');
                    Route::delete('videos/{video}', [MarketingVideoController::class, 'destroy'])->name('videos.destroy');
                    Route::get('videos/{video}/download', [MarketingVideoController::class, 'download'])->name('videos.download');
                    Route::post('creatify/generate', [MarketingCreatifyController::class, 'generate'])->name('creatify.generate');
                    Route::post('creatify/poll', [MarketingCreatifyController::class, 'poll'])->name('creatify.poll');
                });

                Route::get('general', [StoreGeneralController::class, 'edit'])->name('general.edit');
                Route::put('general', [StoreGeneralController::class, 'update'])->name('general.update');
                Route::post('general/ai-seo', [StoreGeneralController::class, 'aiSeo'])->name('general.ai-seo');
                Route::get('design', [DesignController::class, 'edit'])->name('design.edit');
                Route::put('design', [DesignController::class, 'update'])->name('design.update');
                Route::get('design/preview', [DesignController::class, 'preview'])->name('design.preview');
                Route::get('design/inspect', [DesignController::class, 'inspect'])->name('design.inspect');
                Route::post('design/ai-fix', [DesignController::class, 'aiFix'])->name('design.ai-fix');
                Route::post('design/translate', [DesignController::class, 'translate'])->name('design.translate');
                Route::post('design/seed', [DesignController::class, 'seedDefaults'])->name('design.seed');
                Route::post('design/pages', [DesignController::class, 'storePage'])->name('design.pages.store');
                Route::get('design/pages/{page}', [DesignController::class, 'editPage'])->name('design.pages.edit');
                Route::put('design/pages/{page}', [DesignController::class, 'updatePage'])->name('design.pages.update');
                Route::delete('design/pages/{page}', [DesignController::class, 'destroyPage'])->name('design.pages.destroy');
                Route::post('design/assets', [DesignController::class, 'uploadAsset'])->name('design.assets.upload');
                Route::post('design/zip', [DesignController::class, 'uploadZip'])->name('design.zip.upload');
                Route::delete('design/assets/{asset}', [DesignController::class, 'destroyAsset'])->name('design.assets.destroy');
                Route::get('design/editor/{page}', [DesignController::class, 'editor'])->name('design.editor');
                Route::post('design/editor/{page}', [DesignController::class, 'saveEditor'])->name('design.editor.save');
                Route::get('products.json', [DesignController::class, 'productsJson'])->name('products.json');
                Route::post('designs/{storeDesign}/activate', [DesignController::class, 'activateDesign'])->name('designs.activate');
                Route::post('designs/{storeDesign}/duplicate', [DesignController::class, 'duplicateDesign'])->name('designs.duplicate');
                Route::post('designs/{storeDesign}/reset', [DesignController::class, 'resetDesignFromLibrary'])->name('designs.reset');
                Route::post('designs/{storeDesign}/library', [DesignController::class, 'saveDesignToLibrary'])->name('designs.library');
                Route::delete('designs/{storeDesign}', [DesignController::class, 'destroyDesign'])->name('designs.destroy');
                Route::post('themes/{theme}/apply', [DesignController::class, 'applyTheme'])->name('themes.apply');
                Route::get('payments', fn () => redirect()->route('admin.store.general.edit'));

                Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
                Route::post('products/suggest-prices', [ProductController::class, 'suggestPrices'])->name('products.suggest-prices');
                Route::post('products/compress-name', [ProductController::class, 'compressName'])->name('products.compress-name');
                Route::post('products/{product}/upload-image', [ProductController::class, 'uploadImage'])->name('products.upload-image');
                Route::post('products/{product}/upload-video', [ProductController::class, 'uploadVideo'])->name('products.upload-video');
                Route::post('products/{product}/similar-import/preview', [ProductController::class, 'previewSimilarImport'])->name('products.similar-import.preview');
                Route::post('products/{product}/similar-import', [ProductController::class, 'importSimilar'])->name('products.similar-import');
                Route::post('products/recalculate-prices', [ProductController::class, 'recalculatePrices'])->name('products.recalculate-prices');
                Route::resource('products', ProductController::class)->except(['show']);
                Route::post('products/{product}/sync-cj', [ProductController::class, 'syncCj'])->name('products.sync-cj');
                Route::post('products/{product}/translate', [ProductController::class, 'translate'])->name('products.translate');
                Route::delete('products/{product}/variants/{variant}', [ProductController::class, 'destroyVariant'])->name('products.variants.destroy');
                Route::delete('products/{product}/variants', [ProductController::class, 'bulkDestroyVariants'])->name('products.variants.bulk-destroy');

                Route::middleware('store.service:commerce')->group(function () {
                    Route::resource('promotions', PromotionController::class)
                        ->parameters(['promotions' => 'coupon'])
                        ->except(['show']);
                    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
                    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
                    Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
                    Route::post('orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');
                    Route::get('claims', [OrderClaimController::class, 'index'])->name('claims.index');
                    Route::get('claims/{claim}', [OrderClaimController::class, 'show'])->name('claims.show');
                    Route::put('claims/{claim}', [OrderClaimController::class, 'update'])->name('claims.update');
                    Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
                    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
                });

                Route::middleware('store.plugin:upsell')->group(function () {
                    Route::resource('upsells', UpsellController::class)
                        ->parameters(['upsells' => 'upsell'])
                        ->except(['show']);
                });
                Route::middleware('store.plugin:cross_sell')->group(function () {
                    Route::put('cross-sells/offer', [CrossSellController::class, 'updateOffer'])->name('cross-sells.offer');
                    Route::resource('cross-sells', CrossSellController::class)
                        ->parameters(['cross-sells' => 'cross_sell'])
                        ->except(['show']);
                });
                Route::middleware('store.plugin:urgency')->group(function () {
                    Route::get('urgency', [UrgencyController::class, 'edit'])->name('urgency.edit');
                    Route::put('urgency', [UrgencyController::class, 'update'])->name('urgency.update');
                });
                Route::middleware('store.plugin:roulette')->group(function () {
                    Route::get('roulette/wheel', fn () => redirect()->route('admin.store.roulette.index'));
                    Route::put('roulette/wheel', [RouletteController::class, 'updateWheel'])->name('roulette.wheel.update');
                    Route::resource('roulette', RouletteController::class)
                        ->parameters(['roulette' => 'roulette'])
                        ->except(['show'])
                        ->whereNumber('roulette');
                });
                Route::middleware('store.plugin:social_proof')->group(function () {
                    Route::get('social-proof', [SocialProofController::class, 'edit'])->name('social-proof.edit');
                    Route::put('social-proof', [SocialProofController::class, 'update'])->name('social-proof.update');
                });
                Route::middleware('store.plugin:combos')->group(function () {
                    Route::get('combos/promo-styles', [ComboController::class, 'promoStyles'])->name('combos.promo-styles');
                    Route::get('combos/promo-styles/{style}/templates', [ComboController::class, 'promoStyleTemplates'])->name('combos.promo-styles.templates');
                    Route::get('combos/promo-styles/{style}/thumb/{file}', [ComboController::class, 'promoStyleThumb'])->where('file', '[A-Za-z0-9_-]+')->name('combos.promo-styles.thumb');
                    Route::post('combos/ai-copy', [ComboController::class, 'aiCopy'])->name('combos.ai-copy');
                    Route::post('combos/ai-image', [ComboController::class, 'aiGenerateImage'])->name('combos.ai-image');
                    Route::post('combos/ai-landing', [ComboController::class, 'aiLanding'])->name('combos.ai-landing');
                    Route::post('combos/upload-image', [ComboController::class, 'uploadImage'])->name('combos.upload-image');
                    Route::resource('combos', ComboController::class)->except(['show']);
                });
                Route::middleware('store.plugin:newsletter')->group(function () {
                    Route::get('newsletter', [NewsletterController::class, 'edit'])->name('newsletter.edit');
                    Route::put('newsletter', [NewsletterController::class, 'update'])->name('newsletter.update');
                    Route::get('newsletter/export', [NewsletterController::class, 'export'])->name('newsletter.export');
                });
                Route::middleware('store.plugin:cookies')->group(function () {
                    Route::get('cookies', [CookieConsentController::class, 'edit'])->name('cookies.edit');
                    Route::put('cookies', [CookieConsentController::class, 'update'])->name('cookies.update');
                });
            });
        });

        Route::middleware('permission:store.manage')->prefix('sandbox/orders')->name('sandbox.orders.')->group(function () {
            Route::get('/', [SandboxOrderController::class, 'index'])->name('index');
            Route::get('{sandboxOrder}', [SandboxOrderController::class, 'show'])->name('show');
            Route::post('{sandboxOrder}/refresh', [SandboxOrderController::class, 'refresh'])->name('refresh');
            Route::post('{sandboxOrder}/resubmit', [SandboxOrderController::class, 'resubmit'])->name('resubmit');
        });

        Route::middleware('permission:store.manage')->prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [TemplateController::class, 'index'])->name('index');
            Route::post('/', [TemplateController::class, 'store'])->name('store');
            Route::get('{theme}/download.zip', [TemplateController::class, 'downloadZip'])->name('download');
            Route::get('{theme}', [TemplateController::class, 'edit'])->name('edit');
            Route::put('{theme}', [TemplateController::class, 'update'])->name('update');
            Route::delete('{theme}', [TemplateController::class, 'destroy'])->name('destroy');
            Route::post('{theme}/apply', [TemplateController::class, 'apply'])->name('apply');
            Route::post('{theme}/translate', [TemplateController::class, 'translate'])->name('translate');
            Route::post('{theme}/sandbox', [TemplateController::class, 'launchSandbox'])->name('sandbox');
            Route::post('{theme}/seed', [TemplateController::class, 'seed'])->name('seed');
            Route::post('{theme}/pages', [TemplateController::class, 'storePage'])->name('pages.store');
            Route::get('{theme}/pages/{page}', [TemplateController::class, 'editPage'])->name('pages.edit');
            Route::put('{theme}/pages/{page}', [TemplateController::class, 'updatePage'])->name('pages.update');
            Route::delete('{theme}/pages/{page}', [TemplateController::class, 'destroyPage'])->name('pages.destroy');
            Route::get('{theme}/editor/{page}', [TemplateController::class, 'editor'])->name('editor');
            Route::post('{theme}/editor/{page}', [TemplateController::class, 'saveEditor'])->name('editor.save');
            Route::get('{theme}/products.json', [TemplateController::class, 'productsJson'])->name('products.json');
            Route::post('{theme}/assets', [TemplateController::class, 'uploadAsset'])->name('assets.upload');
            Route::delete('{theme}/assets/{asset}', [TemplateController::class, 'destroyAsset'])->name('assets.destroy');
        });

        Route::middleware('permission:settings.general')->group(function () {
            Route::get('/settings/general', [GeneralSettingsController::class, 'edit'])->name('settings.general');
            Route::put('/settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');
            Route::post('/settings/general/currency/fetch', [GeneralSettingsController::class, 'fetchCurrencyRates'])->name('settings.general.currency.fetch');
            Route::post('/settings/general/cj/authorize', [GeneralSettingsController::class, 'authorizeCj'])->name('settings.general.cj.authorize');
            Route::post('/settings/general/cj/test', [GeneralSettingsController::class, 'testCj'])->name('settings.general.cj.test');
            Route::post('/settings/general/aliexpress', [GeneralSettingsController::class, 'saveAliExpress'])->name('settings.general.aliexpress.save');
            Route::post('/settings/general/cloudflare-browser', [GeneralSettingsController::class, 'saveCloudflareBrowser'])->name('settings.general.cloudflare-browser.save');
            Route::post('/settings/general/r2', [GeneralSettingsController::class, 'saveR2'])->name('settings.general.r2.save');
            Route::post('/settings/general/r2/refresh-stats', [GeneralSettingsController::class, 'refreshR2StoreStats'])->name('settings.general.r2.refresh-stats');
            Route::post('/settings/general/api/test', [GeneralSettingsController::class, 'testApi'])->name('settings.general.api.test');
            Route::get('/settings/general/ai/engines', [GeneralSettingsController::class, 'aiEngines'])->name('settings.general.ai.engines');
        });

        Route::middleware('permission:lab.discovery')->group(function () {
            Route::get('/lab/discovery', fn () => redirect()->route('admin.lab.cj'))->name('lab.discovery');
            Route::post('/lab/discovery', fn () => redirect()->route('admin.lab.cj'))->name('lab.discovery.run');
        });

        Route::middleware('permission:lab.cj')->group(function () {
            Route::get('/lab/cj', [LabController::class, 'cjSearch'])->name('lab.cj');
            Route::post('/lab/cj', [LabController::class, 'cjSearch'])->name('lab.cj.run');
            Route::post('/lab/cj/import', [LabController::class, 'importCjProduct'])->name('lab.cj.import');
            Route::post('/lab/cj/improve-prompt', [LabController::class, 'improvePrompt'])->name('lab.cj.improve-prompt');
            Route::post('/lab/cj/crawl', [LabController::class, 'crawlProduct'])->name('lab.cj.crawl');
            Route::post('/lab/cj/hunt', [LabController::class, 'huntFromUrl'])->name('lab.cj.hunt');
            Route::post('/lab/cj/hunt-html', [LabController::class, 'huntFromHtml'])->name('lab.cj.hunt-html');
            Route::get('/lab/cj/capture/{id}', [LabController::class, 'captureResult'])->name('lab.cj.capture');
            Route::get('/lab/cj/chrome-extension', [LabController::class, 'downloadChromeExtension'])->name('lab.cj.extension');
            Route::post('/lab/cj/plugin-token', [LabController::class, 'regeneratePluginToken'])->name('lab.cj.plugin-token');
            Route::post('/lab/cj/import-aliexpress', [LabController::class, 'importAliExpressProduct'])->name('lab.cj.import-aliexpress');
            Route::get('/lab/cj/videos/{pid}', [LabController::class, 'productVideos'])->name('lab.cj.videos');
            Route::get('/lab/cj/images/{pid}', [LabController::class, 'productImages'])->name('lab.cj.images');
            Route::get('/lab/cj/video-proxy', [LabController::class, 'videoProxy'])->name('lab.cj.video-proxy');
        });

        Route::middleware('permission:lab.score')->group(function () {
            Route::get('/lab/score', fn () => redirect()->route('admin.lab.cj'))->name('lab.score');
        });

        Route::middleware('permission:profile.update')->group(function () {
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        });

        Route::middleware('permission:users.view')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        });
        Route::middleware('permission:users.create')->group(function () {
            Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        });
        Route::middleware('permission:users.update')->group(function () {
            Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        });
        Route::middleware('permission:users.delete')->group(function () {
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission:roles.view')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        });
        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });
    });
});
