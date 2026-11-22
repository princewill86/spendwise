<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', [LedgerController::class, 'index'])->name('dashboard');
    Route::get('/accounts', [LedgerController::class, 'index'])->name('accounts');
    Route::get('/transactions', [LedgerController::class, 'index'])->name('transactions');
    Route::get('/categories', [LedgerController::class, 'index'])->name('categories');
    Route::get('/budgets', [PlansController::class, 'index'])->name('budgets');
    Route::post('/budgets', [PlansController::class, 'budget']);
    Route::patch('/budgets/{id}', [PlansController::class, 'budget'])->whereNumber('id');
    Route::get('/goals', [PlansController::class, 'index'])->name('goals')->middleware('feature:goals');
    Route::post('/goals', [PlansController::class, 'goal'])->middleware('feature:goals');
    Route::patch('/goals/{id}', [PlansController::class, 'goal'])->whereNumber('id')->middleware('feature:goals');
    Route::post('/goals/{id}/contributions', [PlansController::class, 'contribution'])->whereNumber('id')->middleware('feature:goals');
    Route::get('/recurring', [ActivityController::class, 'index'])->name('recurring')->middleware('feature:recurring');
    Route::post('/recurring', [ActivityController::class, 'rule'])->middleware('feature:recurring');
    Route::patch('/recurring/{id}', [ActivityController::class, 'rule'])->whereNumber('id')->middleware('feature:recurring');
    Route::get('/calendar', [ActivityController::class, 'index'])->name('calendar')->middleware('feature:recurring');
    Route::get('/notifications', [ActivityController::class, 'index'])->name('notifications');
    Route::patch('/notifications/{id?}', [ActivityController::class, 'notification'])->whereNumber('id');
    Route::get('/imports', [DataController::class, 'imports'])->name('imports')->middleware('feature:imports');
    Route::post('/imports', [DataController::class, 'preview'])->middleware('feature:imports');
    Route::post('/imports/{id}/confirm', [DataController::class, 'confirm'])->whereNumber('id')->middleware('feature:imports');
    Route::get('/reports/print', [DataController::class, 'printable'])->middleware(['feature:reports', 'feature:pdf_exports']);
    Route::get('/reports', [DataController::class, 'reports'])->name('reports')->middleware('feature:reports');
    Route::get('/exports/{kind}', [DataController::class, 'export'])->whereIn('kind', ['transactions', 'accounts', 'budgets', 'reports']);
    Route::get('/settings', [PreferencesController::class, 'index'])->name('settings');
    Route::patch('/settings/theme', [PreferencesController::class, 'theme']);
    Route::patch('/settings', [PreferencesController::class, 'update']);
    Route::post('/settings/avatar', [PreferencesController::class, 'avatar'])->middleware('throttle:10,1');
    Route::get('/settings/avatar', [PreferencesController::class, 'image'])->name('avatar');
    Route::delete('/settings/sessions', [PreferencesController::class, 'session'])->middleware('throttle:10,1');
    Route::patch('/settings/deletion', [PreferencesController::class, 'deletion']);
    Route::get('/settings/export', [PreferencesController::class, 'export'])->middleware('throttle:5,1');
    Route::post('/accounts', [LedgerController::class, 'account']);
    Route::post('/accounts/{account}/reconcile', [LedgerController::class, 'reconcile']);
    Route::post('/transactions/{transaction}/attachment', [AttachmentController::class, 'upload'])->middleware('throttle:10,1');
    Route::get('/transactions/{transaction}/attachment', [AttachmentController::class, 'download']);
    Route::delete('/transactions/{transaction}/attachment', [AttachmentController::class, 'remove']);
    Route::patch('/accounts/{account}', [LedgerController::class, 'account']);
    Route::post('/transactions', [LedgerController::class, 'transaction']);
    Route::patch('/transactions/{transaction}', [LedgerController::class, 'transaction']);
    Route::delete('/transactions/{transaction}', [LedgerController::class, 'delete']);
    Route::post('/categories', [LedgerController::class, 'category']);
    Route::patch('/categories/{category}', [LedgerController::class, 'category'])->whereNumber('category');
});

Route::prefix('admin')->middleware(['auth', 'verified', 'can:administer-platform'])->group(function (): void {
    Route::get('/{section?}', [AdminController::class, 'index'])->whereIn('section', ['overview', 'general', 'security', 'seo', 'features', 'mail', 'users', 'content', 'navigation', 'finance', 'currency', 'audit'])->name('admin');
    Route::patch('/settings/{section}', [AdminController::class, 'settings'])->whereIn('section', ['general', 'security', 'seo', 'features', 'mail', 'finance']);
    Route::patch('/users/{user}', [AdminController::class, 'member']);
    Route::post('/branding/{kind}', [BrandingController::class, 'update'])->whereIn('kind', ['logo', 'favicon', 'sharing']);
    Route::delete('/branding/{kind}', [BrandingController::class, 'remove'])->whereIn('kind', ['logo', 'favicon', 'sharing']);
    Route::patch('/navigation', [AdminController::class, 'navigation']);
    Route::post('/content', [AdminController::class, 'content']);
    Route::patch('/content/{slug}', [AdminController::class, 'content']);
    Route::post('/currencies', [AdminController::class, 'currency']);
    Route::patch('/exchange-provider', [AdminController::class, 'exchange']);
    Route::post('/exchange-rates/refresh', [AdminController::class, 'refreshRates'])->middleware('throttle:3,1');
    Route::post('/exchange-rates', [AdminController::class, 'rate']);
    Route::patch('/templates/{key}', [AdminController::class, 'template']);
    Route::post('/mail/{id}/retry', [AdminController::class, 'retryMail'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/mail/test', [AdminController::class, 'testMail'])->middleware('throttle:4,1');
});

Route::get('/branding/{kind}', [BrandingController::class, 'image'])->whereIn('kind', ['logo', 'favicon', 'sharing'])->name('branding');
Route::post('/contact', [PublicSiteController::class, 'contact'])->middleware('throttle:4,1');
Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap']);
Route::get('/robots.txt', [PublicSiteController::class, 'robots']);
Route::get('/pages/{slug}', [PublicSiteController::class, 'page'])->where('slug', PublicSiteController::SLUG_PATTERN)->name('custom-content');
Route::get('/{slug}', [PublicSiteController::class, 'page'])
    ->whereIn('slug', PublicSiteController::BUILT_IN)
    ->name('content');
