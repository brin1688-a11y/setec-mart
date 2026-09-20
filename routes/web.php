<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CutLuyWebhookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReorderController;
use App\Http\Controllers\SearchSuggestController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// What the search box asks as you type. Public, read-only, and throttled so it
// cannot be used to trawl the catalogue.
// The shop assistant. On the web stack on purpose: it answers using the
// signed-in customer's cart and orders, which live in the session.
Route::post('/api/chat', [ChatController::class, 'handle'])
    ->name('chat.handle');

Route::get('/search/suggest', SearchSuggestController::class)
    ->middleware('throttle:60,1')
    ->name('search.suggest');

// CutLuy webhook. Server-to-server: no session, no auth, and CSRF is
// excluded in bootstrap/app.php — the HMAC signature is the auth.
Route::post('/webhooks/cutluy', CutLuyWebhookController::class)
    ->name('webhooks.cutluy');

Route::get('/products', [ProductController::class, 'index'])
    ->name('products.index');

Route::get('/products/{product}', [ProductController::class, 'show'])
    ->name('products.show');

Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');

// Guest-only routes (register/login)
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    // Credential submissions are rate limited so a password cannot be
    // brute forced: 5 attempts a minute from one IP.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Forgotten passwords. Throttled the same as the credential forms: the
    // request form is also a way to probe which addresses have accounts.
    Route::middleware('throttle:5,1')->group(function () {
        Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    });

    // Sign in with Google
    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
        Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
    });
});

// Confirming an email address. Signed in but not yet confirmed still reaches
// these — that is the whole point of them.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('verification.send');
});

// Authenticated customer routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Buying is for customers. Staff can browse the storefront, but an order
    // placed on an admin account would move real stock and land in the shop's
    // own takings.
    Route::middleware('shopper')->group(function () {
        // The customer's own area. Staff have /admin instead.
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/add/{product}', [CartController::class, 'add'])->name('cart.add');
        Route::patch('/cart/update/{cartItem}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/remove/{cartItem}', [CartController::class, 'remove'])->name('cart.remove');

        // Only the till waits for a confirmed address. Browsing, the cart and
        // the account pages stay open, so an unconfirmed customer is never
        // stuck wondering what went wrong — they find out when it matters,
        // with the resend button in front of them. It also means junk orders
        // need a working inbox first.
        Route::get('/checkout', [CheckoutController::class, 'index'])
            ->middleware('verified')
            ->name('checkout.index');
        // Placing an order takes the stock off the shelf, so a script that
        // hammers this empties the shop even if every order is cancelled a
        // moment later. Eight a minute is far more than anyone shopping.
        Route::post('/checkout', [CheckoutController::class, 'store'])
            ->middleware(['verified', 'throttle:8,1'])
            ->name('checkout.store');
        Route::post('/checkout/coupon', [CouponController::class, 'apply'])->name('coupon.apply');
        Route::delete('/checkout/coupon', [CouponController::class, 'remove'])->name('coupon.remove');

        Route::post('/orders/{order}/reorder', ReorderController::class)->name('orders.reorder');

        Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::patch('/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');
    });

    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::delete('/orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('orders.items.remove');
    Route::post('/orders/{order}/hide', [OrderController::class, 'hide'])->name('orders.hide');

    Route::get('/orders/{order}/pay', [PaymentController::class, 'khqr'])->name('payments.khqr');
    Route::get('/orders/{order}/pay/status', [PaymentController::class, 'status'])->name('payments.status');
    Route::post('/orders/{order}/pay/refresh', [PaymentController::class, 'refresh'])->name('payments.refresh');
    Route::post('/orders/{order}/pay/new', [PaymentController::class, 'renew'])->name('payments.renew');

    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile/account', [ProfileController::class, 'updateAccount'])->name('profile.account');
    Route::put('/profile/delivery', [ProfileController::class, 'updateDelivery'])->name('profile.delivery');
    Route::post('/profile/picture', [ProfileController::class, 'updatePicture'])->name('profile.picture');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

// Admin-only routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::resource('products', AdminProductController::class)->except(['show']);
    Route::resource('categories', AdminCategoryController::class)->except(['show']);

    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
    Route::delete('/orders/cancelled/purge', [AdminOrderController::class, 'purgeCancelled'])->name('orders.purge-cancelled');
    Route::delete('/orders/{order}', [AdminOrderController::class, 'destroy'])->name('orders.destroy');

    Route::resource('coupons', AdminCouponController::class)->except(['show']);
    Route::patch('/coupons/{coupon}/toggle', [AdminCouponController::class, 'toggle'])->name('coupons.toggle');

    // What delivery costs, editable instead of living in a config file.
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/delivery', [DeliveryController::class, 'edit'])->name('delivery.edit');
    Route::put('/delivery', [DeliveryController::class, 'update'])->name('delivery.update');
    Route::delete('/delivery', [DeliveryController::class, 'reset'])->name('delivery.reset');

    Route::patch('/products/{product}/toggle', [AdminProductController::class, 'toggle'])->name('products.toggle');

    Route::get('/customers', [AdminCustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [AdminCustomerController::class, 'show'])->name('customers.show');

    Route::get('/inventory', [AdminInventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/{product}/adjust', [AdminInventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::get('/inventory/{product}/history', [AdminInventoryController::class, 'history'])->name('inventory.history');
});
