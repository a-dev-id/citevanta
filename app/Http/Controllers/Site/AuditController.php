<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Rules\HumanVerified;
use App\Services\Audits\AuditNotAllowed;
use App\Services\Audits\AuditService;
use App\Services\Audits\AuditWaitlistNotifier;
use App\Services\Site\PublicPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

/**
 * The free audit form (home page and /free-audit). Validation, the honeypot
 * and Turnstile here; limits, reuse and the run itself in AuditService.
 */
class AuditController extends Controller
{
    public function store(Request $request, AuditService $audits): RedirectResponse
    {
        $locale = $this->locale($request);

        // A bot filled the hidden field: send it home without running anything.
        if (filled($request->input('fax'))) {
            return redirect(PublicPages::url('home', $locale));
        }

        $data = $request->validateWithBag('audit', [
            'hotel_name' => ['required', 'string', 'min:2', 'max:120'],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'country' => ['required', 'string', Rule::in(array_values(config('countries')))],
            'website' => ['nullable', 'url:http,https', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'consent' => ['nullable', 'boolean'],
        ] + HumanVerified::rules());

        try {
            $audit = $audits->request($data + ['locale' => $locale], (string) $request->ip());
        } catch (AuditNotAllowed $e) {
            return back()->withInput()->withErrors(['audit' => $e->getMessage()], 'audit')->with('audit_full', $e->fullyBooked);
        }

        return to_route('audit.show', $audit->token);
    }

    /**
     * "Email me when slots open" after the audits were fully booked.
     */
    public function waitlist(Request $request, AuditWaitlistNotifier $waitlist): RedirectResponse
    {
        $locale = $this->locale($request);

        if (filled($request->input('fax'))) {
            return redirect(PublicPages::url('home', $locale));
        }

        $data = $request->validateWithBag('audit', ['email' => ['required', 'email', 'max:190']]);
        $waitlist->join($data['email'], $locale);

        return back()->with('audit_waitlisted', __('audit.errors.waitlisted'));
    }

    private function locale(Request $request): string
    {
        $locale = in_array($request->input('locale'), PublicPages::locales(), true) ? $request->input('locale') : PublicPages::DEFAULT_LOCALE;
        App::setLocale($locale);

        return $locale;
    }
}
