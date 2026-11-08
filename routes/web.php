<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CitizenController;
use App\Http\Controllers\CollectorController;
use App\Http\Controllers\Cgso;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

// ---------- Public (no login) ----------
Route::controller(PublicController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/schedule', 'schedule')->name('schedule');
    Route::get('/trucks-routes', 'trucksRoutes')->name('trucks-routes');
    Route::get('/announcements', 'announcements')->name('announcements');
});

// ---------- Authentication ----------
Route::middleware('guest')->controller(AuthController::class)->group(function () {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')->name('login.submit');
    Route::get('/register', 'showRegister')->name('register');
    Route::post('/register', 'register')->name('register.submit');

    // Forgot password: Laravel's password broker sends the link (see README for the mail setup).
    Route::get('/forgot-password', 'showForgotPassword')->name('password.request');
    Route::post('/forgot-password', 'sendResetLink')->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', 'showResetPassword')->name('password.reset');
    Route::post('/reset-password', 'resetPassword')->name('password.update');
});

// ---------- My Account (every signed-in user) ----------
Route::prefix('account')->name('account.')->middleware(['auth', 'active'])->controller(AccountController::class)->group(function () {
    Route::get('/', 'edit')->name('edit');
    Route::put('/profile', 'updateProfile')->name('profile');
    Route::put('/password', 'updatePassword')->name('password');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ---------- Resident + Barangay Official (same screens, separate role guards) ----------
foreach (['resident', 'official'] as $role) {
    Route::prefix($role)->name("$role.")->middleware(['auth', 'active', "role:$role"])->controller(CitizenController::class)->group(function () {
        Route::get('/dashboard', 'dashboard')->name('dashboard');
        Route::get('/reports', 'reports')->name('reports');
        Route::get('/reports/create', 'create')->name('reports.create');
        Route::post('/reports', 'store')->name('reports.store');
        Route::post('/reports/{report}/feedback', 'feedback')->name('reports.feedback');
        Route::get('/route-map', 'routeMap')->name('route-map');
    });
}

// ---------- Collector ----------
Route::prefix('collector')->name('collector.')->middleware(['auth', 'active', 'role:collector'])->controller(CollectorController::class)->group(function () {
    Route::get('/dashboard', 'dashboard')->name('dashboard');
    Route::post('/reports/{report}/start', 'startCleanup')->name('reports.start');
    Route::post('/reports/{report}/evidence', 'submitEvidence')->name('reports.evidence');
    Route::post('/areas/{area}', 'updateArea')->name('areas.update');
    Route::post('/notes', 'addNote')->name('notes.store');
});

// ---------- CGSO (City General Services Office) ----------
Route::prefix('cgso')->name('cgso.')->middleware(['auth', 'active', 'role:cgso'])->group(function () {
    Route::get('/dashboard', [Cgso\DashboardController::class, 'index'])->name('dashboard');

    Route::controller(Cgso\RouteAssignmentController::class)->group(function () {
        Route::get('/routes', 'index')->name('routes');
        Route::get('/routes/{route}/edit', 'index')->name('routes.edit');
        Route::post('/routes', 'store')->name('routes.store');
        Route::put('/routes/{route}', 'update')->name('routes.update');
        Route::delete('/routes/{route}', 'destroy')->name('routes.destroy');
    });

    Route::controller(Cgso\TruckController::class)->group(function () {
        Route::get('/trucks', 'index')->name('trucks');
        Route::post('/trucks', 'store')->name('trucks.store');
        Route::put('/trucks/{truck}', 'update')->name('trucks.update');
        Route::patch('/trucks/{truck}/status', 'status')->name('trucks.status');
        Route::delete('/trucks/{truck}', 'destroy')->name('trucks.destroy');
    });

    Route::controller(Cgso\ScheduleController::class)->group(function () {
        Route::get('/schedules', 'index')->name('schedules');
        Route::post('/schedules', 'store')->name('schedules.store');
        Route::put('/schedules/{schedule}', 'update')->name('schedules.update');
        Route::patch('/schedules/{schedule}/toggle', 'toggle')->name('schedules.toggle');
        Route::delete('/schedules/{schedule}', 'destroy')->name('schedules.destroy');
    });

    Route::controller(Cgso\ReportController::class)->group(function () {
        Route::get('/reports', 'index')->name('reports');
        Route::post('/reports/{report}/assign', 'assign')->name('reports.assign');
        Route::post('/reports/{report}/approve', 'approve')->name('reports.approve');
        Route::post('/reports/{report}/reject', 'reject')->name('reports.reject');
        Route::post('/reports/{report}/reopen', 'reopen')->name('reports.reopen');
        Route::post('/reports/{report}/feedback-review', 'reviewFeedback')->name('reports.feedback-review');
    });

    Route::get('/collections', [Cgso\CollectionController::class, 'index'])->name('collections');

    Route::controller(Cgso\AnnouncementController::class)->group(function () {
        Route::get('/announcements', 'index')->name('announcements');
        Route::post('/announcements', 'store')->name('announcements.store');
        Route::put('/announcements/{announcement}', 'update')->name('announcements.update');
        Route::delete('/announcements/{announcement}', 'destroy')->name('announcements.destroy');
    });

    Route::controller(Cgso\UserController::class)->group(function () {
        Route::get('/users', 'index')->name('users');
        Route::post('/barangays', 'storeBarangay')->name('barangays.store');
        Route::put('/barangays/{barangay}', 'updateBarangay')->name('barangays.update');
        Route::delete('/barangays/{barangay}', 'destroyBarangay')->name('barangays.destroy');
        Route::post('/officials', 'storeOfficial')->name('officials.store');
        Route::post('/collectors', 'storeCollector')->name('collectors.store');
        Route::put('/users/{user}', 'update')->name('users.update');
        Route::post('/users/{user}/reset-password', 'resetPassword')->name('users.reset-password');
        Route::patch('/users/{user}/deactivate', 'deactivate')->name('users.deactivate');
        Route::patch('/users/{user}/activate', 'activate')->name('users.activate');
        Route::delete('/users/{user}', 'destroy')->name('users.destroy');
    });
});
