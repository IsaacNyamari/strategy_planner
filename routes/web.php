<?php

use App\Http\Controllers\DeploymentStatusController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\MeetingExportController;
use App\Http\Controllers\WahaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
})->name('home');

// Every page needs a login; what each person can open depends on their roles
// (see App\Access\Access). Own dashboard, tasks and contributions are for everyone.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('tasks', 'pages::task.index')->name('tasks');
    Route::get('my-contributions', function () {
        $member = auth()->user()->member;
        abort_if($member === null, 404, 'Your login is not linked to a team member yet. Ask an admin to link it under Admin access.');

        return redirect()->route('contributions.member', $member);
    })->name('contributions.mine');

    Route::livewire('strategies', 'pages::strategies.index')->middleware('can:strategies.view')->name('strategies');
    Route::livewire('strategies/{strategy}', 'pages::strategies.show')->middleware('can:strategies.view')->name('strategies.show');
    Route::livewire('roster', 'pages::roster.index')->middleware('can:roster.view')->name('roster');
    Route::livewire('members', 'pages::members.index')->middleware('can:members.view')->name('members');
    Route::livewire('uniforms', 'pages::uniforms.index')->middleware('can:uniforms.view')->name('uniforms');
    Route::livewire('songs', 'pages::songs.index')->middleware('can:songs.view')->name('songs');
    Route::livewire('contributions', 'pages::contributions.index')->middleware('can:contributions.view')->name('contributions');
    Route::livewire('contributions/members/{member}', 'pages::contributions.member')->middleware('can:view-statement,member')->name('contributions.member');
    Route::livewire('balance-sheet', 'pages::finance.index')->middleware('can:finance.view')->name('finance');
    Route::livewire('meetings', 'pages::meetings.index')->middleware('can:meetings.view')->name('meetings');
    Route::livewire('meetings/{meeting}', 'pages::meetings.show')->middleware('can:meetings.view')->name('meetings.show');

    // Admin access: users, roles & permissions, audit trail.
    Route::livewire('access', 'pages::access.index')->middleware('can:access.manage')->name('access');
    Route::redirect('users', 'access')->name('users');
    Route::livewire('audit', 'pages::audit.index')->middleware('can:audit.view')->name('audit');

    Route::livewire('posts', 'pages::posts.index')->middleware('can:schedule-posts')->name('posts');

    // WhatsApp (WAHA): link the team number, pick groups/channels, member chats.
    Route::livewire('whatsapp', 'pages::whatsapp.index')->middleware('can:manage-whatsapp')->name('whatsapp');
    Route::livewire('whatsapp/chats', 'pages::whatsapp.chats')->middleware('can:manage-whatsapp')->name('whatsapp.chats');

    // Deploy the latest GitHub code from the app.
    Route::middleware('can:deploy')->group(function () {
        Route::livewire('deployments', 'pages::deployments.index')->middleware('password.confirm')->name('deployments');
        Route::get('deployments/status', DeploymentStatusController::class)->name('deployments.status');
    });

    // PDF / Excel downloads (each one is added to the audit trail)
    Route::middleware('log-download')->prefix('exports')->name('exports.')->group(function () {
        Route::controller(ExportController::class)->whereIn('format', ['pdf', 'xlsx'])->group(function () {
            Route::get('roster.{format}', 'roster')->middleware('can:roster.view')->name('roster');
            Route::get('uniforms.{format}', 'uniforms')->middleware('can:uniforms.view')->name('uniforms');
            Route::get('songs.{format}', 'songs')->middleware('can:songs.view')->name('songs');
            Route::get('members.{format}', 'members')->middleware('can:members.view')->name('members');
            Route::get('contributions.{format}', 'contributions')->middleware('can:contributions.view')->name('contributions');
            Route::get('payments.{format}', 'payments')->middleware('can:contributions.view')->name('payments');
            Route::get('arising/{special}.{format}', 'special')->middleware('can:contributions.view')->name('special');
            Route::get('statements/{member}.pdf', 'statement')->middleware('can:view-statement,member')->name('statement');
        });

        // Balance sheet, receipts and the "download all receipts" ZIP
        Route::controller(FinanceExportController::class)->group(function () {
            Route::get('balance-sheet.{format}', 'balance')->whereIn('format', ['pdf', 'xlsx'])->middleware('can:finance.view')->name('balance');
            Route::get('receipts/{payment}.pdf', 'receipt')->middleware('can:view-receipt,payment')->name('receipt');
            Route::get('receipts.zip', 'zip')->middleware('can:finance.view')->name('receipts');
            Route::get('cash-receipts/{entry}', 'attachment')->middleware('can:finance.view')->name('attachment');
        });

        Route::get('meetings/{meeting}.pdf', MeetingExportController::class)->middleware('can:meetings.view')->name('meeting');
    });
});

require __DIR__.'/settings.php';

// WAHA delivery receipts / session status (signed with an HMAC, no login).
Route::post('webhooks/waha', WahaWebhookController::class)->name('webhooks.waha');
