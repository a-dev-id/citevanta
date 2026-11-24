<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\GoogleIntegrationController;
use App\Http\Controllers\Admin\PrivatePlanController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\ContentDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Settings\AiKeyController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TeamSettingsController;
use App\Http\Controllers\Site\AuditController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\WeeklyReportController;
use App\Http\Middleware\SetSiteLocale;
use App\Livewire\Hotels\AccuracyPage;
use App\Livewire\Hotels\HotelDashboard;
use App\Livewire\Hotels\RecommendationsPage;
use App\Livewire\Hotels\SetupWizard;
use App\Livewire\Settings\ConnectGoogle;
use App\Livewire\Site\AuditReport;
use App\Services\Site\PublicPages;
use Illuminate\Support\Facades\Route;

// Public website: English at the root, Indonesian under /id (see PublicPages).
foreach (PublicPages::locales() as $locale) {
    $isDefault = $locale === PublicPages::DEFAULT_LOCALE;

    Route::middleware(SetSiteLocale::class)
        ->prefix($isDefault ? '' : $locale)
        ->name($isDefault ? '' : "{$locale}.")
        ->group(function () use ($locale): void {
            // Signed-in users opening the home page go to their dashboard.
            Route::get('/', HomeController::class)->name('home')->defaults('locale', $locale);
            Route::get('pricing', [PageController::class, 'pricing'])->name('pricing')->defaults('locale', $locale);
            Route::get('free-audit', [PageController::class, 'freeAudit'])->name('free-audit')->defaults('locale', $locale);
            Route::get('privacy', [PageController::class, 'privacy'])->name('privacy')->defaults('locale', $locale);
            Route::get('terms', [PageController::class, 'terms'])->name('terms')->defaults('locale', $locale);
            Route::get('contact', [PageController::class, 'contact'])->name('contact')->defaults('locale', $locale);
            Route::post('contact', [PageController::class, 'sendContact'])->name('contact.send')->defaults('locale', $locale);
        });
}

Route::post('free-audit', [AuditController::class, 'store'])->name('audit.store');
Route::post('free-audit/waitlist', [AuditController::class, 'waitlist'])->middleware('throttle:10,60')->name('audit.waitlist');
Route::livewire('audit/{token}', AuditReport::class)->name('audit.show')->where('token', '[A-Za-z0-9]{48}');

// Weekly email report: one-click unsubscribe from the link in the email (signed, no login).
Route::get('email/weekly-report/unsubscribe/{user}', WeeklyReportController::class)->middleware('signed')->name('weekly-report.unsubscribe');

Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('llms.txt', [SeoController::class, 'llms'])->name('llms');
Route::get('site.webmanifest', [SeoController::class, 'manifest'])->name('manifest');

// Serves public storage files when the host does not allow a storage symlink
// (see DeployTasks). With the symlink in place the web server serves them directly.
Route::get('storage/{path}', StorageFileController::class)->where('path', '.*')->name('storage.fallback');

if (app()->environment('local', 'testing')) {
    Route::view('/design-preview', 'design-preview')->name('design-preview');
}

Route::middleware(['auth', 'verified', 'team'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Hotels and the setup wizard. "create" must come before "{hotel}".
    Route::get('hotels', [HotelController::class, 'index'])->name('hotels.index');
    Route::livewire('hotels/create', SetupWizard::class)->name('hotels.create');
    Route::livewire('hotels/{hotel}/setup', SetupWizard::class)->name('hotels.setup')->can('update', 'hotel');
    Route::livewire('hotels/{hotel}', HotelDashboard::class)->name('hotels.show')->can('view', 'hotel');
    Route::livewire('hotels/{hotel}/scans/{scanRun}', HotelDashboard::class)->name('hotels.scans.show')->can('view', 'hotel')->scopeBindings();
    Route::get('hotels/{hotel}/switch', [HotelController::class, 'switch'])->name('hotels.switch')->can('view', 'hotel');
    Route::get('hotels/{hotel}/edit', [HotelController::class, 'edit'])->name('hotels.edit')->can('update', 'hotel');
    Route::get('hotels/{hotel}/prompts', [HotelController::class, 'prompts'])->name('hotels.prompts')->can('update', 'hotel');
    Route::get('hotels/{hotel}/competitors', [HotelController::class, 'competitors'])->name('hotels.competitors')->can('update', 'hotel');
    Route::get('hotels/{hotel}/scans', [HotelController::class, 'scans'])->name('hotels.scans')->can('view', 'hotel');
    Route::get('hotels/{hotel}/citations', [HotelController::class, 'citations'])->name('hotels.citations')->can('view', 'hotel');
    Route::get('hotels/{hotel}/citations/{domain}', [HotelController::class, 'citationDomain'])->name('hotels.citations.domain')->can('view', 'hotel');
    Route::livewire('hotels/{hotel}/accuracy', AccuracyPage::class)->name('hotels.accuracy')->can('view', 'hotel');
    Route::livewire('hotels/{hotel}/recommendations', RecommendationsPage::class)->name('hotels.recommendations')->can('view', 'hotel');
    // Private plan: reports from synced GA4 and Search Console data.
    Route::get('hotels/{hotel}/reports', [ReportController::class, 'show'])->name('hotels.reports')->can('view', 'hotel');
    Route::get('hotels/{hotel}/reports/export/{table}', [ReportController::class, 'export'])->name('hotels.reports.export')->can('view', 'hotel')->where('table', '[a-z-]+');

    Route::get('hotels/{hotel}/content/{contentPiece}/download', ContentDownloadController::class)->name('hotels.content.download')->can('view', 'hotel')->scopeBindings();

    // Menu shortcuts: open the current hotel's page.
    Route::get('prompts', [HotelController::class, 'current'])->defaults('section', 'prompts')->name('prompts.index');
    Route::get('competitors', [HotelController::class, 'current'])->defaults('section', 'competitors')->name('competitors.index');
    Route::get('scans', [HotelController::class, 'current'])->defaults('section', 'scans')->name('scans.index');
    Route::get('citations', [HotelController::class, 'current'])->defaults('section', 'citations')->name('citations.index');
    Route::get('accuracy', [HotelController::class, 'current'])->defaults('section', 'accuracy')->name('accuracy.index');
    Route::get('recommendations', [HotelController::class, 'current'])->defaults('section', 'recommendations')->name('recommendations.index');
    Route::get('reports', [HotelController::class, 'current'])->defaults('section', 'reports')->name('reports.index');

    Route::get('settings/profile', [ProfileController::class, 'show'])->name('settings.profile');
    Route::get('settings/team', [TeamSettingsController::class, 'show'])->name('settings.team');
    Route::put('settings/team', [TeamSettingsController::class, 'update'])->name('settings.team.update');
    Route::put('settings/notifications', [ProfileController::class, 'updateNotifications'])->name('settings.notifications');

    // Private plan: the team's own OpenAI key (owners only).
    Route::get('settings/ai-key', [AiKeyController::class, 'show'])->name('settings.ai-key');
    Route::get('settings/ai-key/guide', [AiKeyController::class, 'guide'])->name('settings.ai-key.guide');
    Route::post('settings/ai-key', [AiKeyController::class, 'save'])->name('settings.ai-key.save');
    Route::post('settings/ai-key/test', [AiKeyController::class, 'test'])->name('settings.ai-key.test');
    Route::delete('settings/ai-key', [AiKeyController::class, 'remove'])->name('settings.ai-key.remove');
    Route::put('settings/ai-key/budget', [AiKeyController::class, 'budget'])->name('settings.ai-key.budget');

    // Private plan: GA4 and Search Console through the agent email (owners only).
    Route::livewire('settings/google', ConnectGoogle::class)->name('settings.google');

    // Billing (owners only; see TeamPolicy::manageBilling and InvoicePolicy).
    Route::get('settings/billing', [BillingController::class, 'show'])->name('settings.billing');
    Route::post('settings/billing/plan', [BillingController::class, 'choosePlan'])->name('billing.plan');
    Route::post('settings/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('settings/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::get('billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show')->can('view', 'invoice');
    Route::get('billing/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('billing.invoices.pay')->can('pay', 'invoice');
    Route::post('billing/invoices/{invoice}/paid', [InvoiceController::class, 'submitPayment'])->name('billing.invoices.paid')->can('pay', 'invoice');
    Route::get('billing/invoices/{invoice}/proof', [InvoiceController::class, 'proof'])->name('billing.invoices.proof')->can('view', 'invoice');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('teams', [AdminController::class, 'teams'])->name('teams');
        Route::get('hotels', [AdminController::class, 'hotels'])->name('hotels');
        Route::get('scan-runs', [AdminController::class, 'scanRuns'])->name('scan-runs');
        Route::get('audits', [AdminController::class, 'audits'])->name('audits');
        Route::get('audits/export', [AdminController::class, 'exportAudits'])->name('audits.export');
        Route::get('billing', [AdminBillingController::class, 'index'])->name('billing');
        Route::post('billing/invoices/{invoice}/paid', [AdminBillingController::class, 'markPaid'])->name('billing.paid');
        Route::post('billing/invoices/{invoice}/reject', [AdminBillingController::class, 'reject'])->name('billing.reject');
        Route::post('teams/{team}/extend-trial', [AdminBillingController::class, 'extendTrial'])->name('teams.extend-trial');
        Route::get('teams/{team}/private-plan', [PrivatePlanController::class, 'edit'])->name('teams.private-plan');
        Route::put('teams/{team}/private-plan', [PrivatePlanController::class, 'update'])->name('teams.private-plan.update');
        Route::post('teams/{team}/comp', [AdminBillingController::class, 'comp'])->name('teams.comp');
        Route::post('scan-runs/{scanRun}/retry', [AdminController::class, 'retryScanRun'])->name('scan-runs.retry');
        Route::get('failed-jobs', [AdminController::class, 'failedJobs'])->name('failed-jobs');
        Route::post('failed-jobs/{uuid}/retry', [AdminController::class, 'retryFailedJob'])->name('failed-jobs.retry');
        Route::delete('failed-jobs/{uuid}', [AdminController::class, 'deleteFailedJob'])->name('failed-jobs.delete');
        Route::get('system', [AdminController::class, 'system'])->name('system');
        Route::get('integrations/google', [GoogleIntegrationController::class, 'show'])->name('integrations.google');
        Route::post('integrations/google/test', [GoogleIntegrationController::class, 'test'])->name('integrations.google.test');
        Route::post('integrations/google/diagnose', [GoogleIntegrationController::class, 'diagnose'])->name('integrations.google.diagnose');
        Route::post('integrations/google/resync', [GoogleIntegrationController::class, 'resync'])->name('integrations.google.resync');
        Route::post('system/deploy-tasks', [AdminController::class, 'runDeployTasks'])->name('system.deploy-tasks');
        Route::post('system/clear-caches', [AdminController::class, 'clearCaches'])->name('system.clear-caches');
        Route::post('system/resume-paused', [AdminController::class, 'resumePaused'])->name('system.resume-paused');
        Route::post('teams/{team}/approve-trial', [AdminController::class, 'approveTrial'])->name('teams.approve-trial');
    });
});
