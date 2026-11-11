<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImprovementController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PilotController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\VisibilityController;
use App\Models\Organization;
use App\Services\CreditLedger;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});
Route::middleware('auth')->group(function () {
    Route::get('/verify-email', fn () => view('auth.verify-email'))->middleware('tenant')->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('dashboard')->with('status', 'Email address verified.');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request) {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Verification link requested. Check your email.');
    })->middleware('throttle:3,1')->name('verification.send');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::post('/organization/switch', [OrganizationController::class, 'switchOrganization'])->name('organizations.switch');
});
Route::middleware(['auth', 'tenant', 'admin'])->group(function () {
    Route::get('/pilot', [PilotController::class, 'index'])->name('pilot.index');
    Route::post('/pilot/prepare', [PilotController::class, 'prepare'])->name('pilot.prepare');
    Route::post('/pilot/results/{testRun}/review', [PilotController::class, 'review'])->name('pilot.review');
    Route::post('/pilot/improvements', [PilotController::class, 'improvements'])->name('pilot.improvements');

    Route::put('/organization/plan', function (Request $request) {
        $data = $request->validate(['plan_key' => ['required', Rule::in(array_keys(config('plans.definitions')))]]);
        app(CreditLedger::class)->assign($data['plan_key'], $request->user());

        return back()->with('status', 'Plan assignment saved. No payment was collected.');
    })->name('organizations.plan');
    Route::get('/platform/organizations', function () {
        abort_unless(auth()->user()->is_platform_admin, 403);

        return view('organizations.platform', ['organizations' => Organization::orderBy('name')->get()]);
    })->name('platform.organizations');
    Route::get('/organization', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:3,60')->name('organizations.store');
    Route::put('/organization', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::post('/organization/members', [OrganizationController::class, 'memberStore'])->middleware('throttle:5,60')->name('organizations.members.store');
    Route::put('/organization/members/{member}', [OrganizationController::class, 'memberUpdate'])->whereNumber('member')->name('organizations.members.update');
    Route::post('/organization/transfer', [OrganizationController::class, 'transfer'])->name('organizations.transfer');
    Route::post('/organization/brands', [OrganizationController::class, 'brandStore'])->name('brands.store');
    Route::put('/organization/brands/{brand}', [OrganizationController::class, 'brandUpdate'])->name('brands.update');
    Route::post('/brand/switch', [OrganizationController::class, 'switchBrand'])->name('brands.switch');

    Route::get('/improvements', [ImprovementController::class, 'index'])->name('improvements.index');
    Route::post('/improvements/refresh', [ImprovementController::class, 'refresh'])->name('improvements.refresh');
    Route::get('/improvements/{improvement}', [ImprovementController::class, 'show'])->whereNumber('improvement')->name('improvements.show');
    Route::put('/improvements/{improvement}', [ImprovementController::class, 'update'])->whereNumber('improvement')->name('improvements.update');
    Route::post('/improvements/{improvement}/explain', [ImprovementController::class, 'explain'])->whereNumber('improvement')->middleware('throttle:2,1')->name('improvements.explain');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('prompts', PromptController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::get('/tests', [TestController::class, 'create'])->name('tests.create');
    Route::post('/tests', [TestController::class, 'store'])->middleware('throttle:2,1')->name('tests.store');
    Route::get('/history', [TestController::class, 'index'])->name('tests.index');
    Route::get('/tests/{testRun}', [TestController::class, 'show'])->name('tests.show');
    Route::get('/settings', SettingsController::class)->name('settings');
    Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::get('/batches/create', [BatchController::class, 'create'])->name('batches.create');
    Route::post('/batches/preview', [BatchController::class, 'preview'])->name('batches.preview');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    Route::post('/batches/{batch}/start', [BatchController::class, 'start'])->name('batches.start');
    Route::post('/batches/{batch}/process', [BatchController::class, 'process'])->name('batches.process');
    Route::post('/batches/{batch}/cancel', [BatchController::class, 'cancel'])->name('batches.cancel');

    Route::get('/competitors', [VisibilityController::class, 'competitors'])->name('visibility.competitors');
    Route::get('/opportunities', [VisibilityController::class, 'opportunities'])->name('visibility.opportunities');
    Route::get('/hotels', [VisibilityController::class, 'hotels'])->name('visibility.hotels');
    Route::post('/hotels', [VisibilityController::class, 'saveHotel'])->name('visibility.hotels.store');
    Route::put('/hotels/{hotel}', [VisibilityController::class, 'saveHotel'])->name('visibility.hotels.update');
    Route::get('/tests/{testRun}/analysis', [VisibilityController::class, 'review'])->name('visibility.review');
    Route::put('/tests/{testRun}/analysis', [VisibilityController::class, 'correct'])->name('visibility.correct');
    Route::put('/tests/{testRun}/citations/{citation}', [VisibilityController::class, 'correctCitation'])->name('visibility.citations');

});
