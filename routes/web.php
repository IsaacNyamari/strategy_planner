<?php

use App\Http\Controllers\DeploymentStatusController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\WahaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function(){
    return redirect('/login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('tasks', 'pages::task.index')->name('tasks');
    Route::livewire('strategies', 'pages::strategies.index')->name('strategies');
    Route::livewire('strategies/{strategy}', 'pages::strategies.show')->name('strategies.show');
    Route::livewire('roster', 'pages::roster.index')->name('roster');
    Route::livewire('members', 'pages::members.index')->name('members');
    Route::livewire('uniforms', 'pages::uniforms.index')->name('uniforms');
    Route::livewire('songs', 'pages::songs.index')->name('songs');
    Route::livewire('contributions', 'pages::contributions.index')->name('contributions');
    Route::livewire('contributions/members/{member}', 'pages::contributions.member')->name('contributions.member');

    Route::livewire('balance-sheet', 'pages::finance.index')->name('finance');

    Route::livewire('users', 'pages::users.index')->middleware('can:manage-users')->name('users');
    Route::livewire('posts', 'pages::posts.index')->middleware('can:schedule-posts')->name('posts');

    // WhatsApp (WAHA): link the team number, pick groups/channels, message log.
    Route::livewire('whatsapp', 'pages::whatsapp.index')->middleware('can:manage-whatsapp')->name('whatsapp');
    Route::livewire('whatsapp/chats', 'pages::whatsapp.chats')->middleware('can:manage-whatsapp')->name('whatsapp.chats');

    // Deploy the latest GitHub code from the app (admins only).
    Route::middleware('can:deploy')->group(function () {
        Route::livewire('deployments', 'pages::deployments.index')->middleware('password.confirm')->name('deployments');
        Route::get('deployments/status', DeploymentStatusController::class)->name('deployments.status');
    });

    // PDF / Excel downloads
    Route::controller(ExportController::class)
        ->prefix('exports')->name('exports.')
        ->whereIn('format', ['pdf', 'xlsx'])
        ->group(function () {
            Route::get('roster.{format}', 'roster')->name('roster');
            Route::get('uniforms.{format}', 'uniforms')->name('uniforms');
            Route::get('songs.{format}', 'songs')->name('songs');
            Route::get('members.{format}', 'members')->name('members');
            Route::get('contributions.{format}', 'contributions')->name('contributions');
            Route::get('payments.{format}', 'payments')->name('payments');
            Route::get('arising/{special}.{format}', 'special')->name('special');
            Route::get('statements/{member}.pdf', 'statement')->name('statement');
        });

    // Balance sheet, receipts and the "download all receipts" ZIP
    Route::controller(FinanceExportController::class)->prefix('exports')->name('exports.')->group(function () {
        Route::get('balance-sheet.{format}', 'balance')->whereIn('format', ['pdf', 'xlsx'])->name('balance');
        Route::get('receipts/{payment}.pdf', 'receipt')->name('receipt');
        Route::get('receipts.zip', 'zip')->name('receipts');
        Route::get('cash-receipts/{entry}', 'attachment')->name('attachment');
    });
});

require __DIR__.'/settings.php';

// WAHA delivery receipts / session status (signed with an HMAC, no login).
Route::post('webhooks/waha', WahaWebhookController::class)->name('webhooks.waha');
