<?php

use App\Http\Controllers\SubscriptionController;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$adminPrefix = 'admin-panel';
if (Schema::hasTable('settings')) {
    $adminPrefix = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';
}

Route::prefix($adminPrefix)->group(function () {
    require __DIR__.'/auth.php';
});
Route::middleware(['auth', 'verified'])->prefix($adminPrefix)->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');

    Route::livewire('/clients', 'clients.index')->name('clients.index');
    Route::livewire('/clients/create', 'clients.create')->name('clients.create');
    Route::livewire('/clients/edit/{clientId}', 'clients.edit')->name('clients.edit');

    Route::livewire('/clients/{clientId}/subscriptions', 'subscriptions.index')->name('subscriptions.index');
    Route::livewire('/clients/{clientId}/subscriptions/create', 'subscriptions.create')->name('subscriptions.create');
    Route::livewire('/clients/{clientId}/subscriptions/edit/{subId}', 'subscriptions.edit')->name('subscriptions.edit');

    Route::livewire('/clients/{clientId}/subs/{subId}/configs', 'configs.index')->name('configs.index');
    Route::livewire('/clients/{clientId}/subs/{subId}/configs/create', 'configs.create')->name('configs.create');
    Route::livewire('/clients/{clientId}/subs/{subId}/configs/edit/{configId}', 'configs.edit')->name('configs.edit');

    Route::livewire('/settings', 'setting-manager')->name('setting-manager');

    Route::livewire('/database', 'database-manager')->name('database-manager');

});

Route::get('/s/{token}', [SubscriptionController::class, 'show'])->name('subscription.raw');


