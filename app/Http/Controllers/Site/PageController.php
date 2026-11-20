<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessage;
use App\Models\User;
use App\Rules\HumanVerified;
use App\Services\Plans\PlanCatalog;
use App\Services\Site\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public website pages (English and Indonesian; see SetSiteLocale).
 */
class PageController extends Controller
{
    public function __construct(private SiteContent $content, private PlanCatalog $plans) {}

    public function home(): View
    {
        $faq = $this->content->faq('home');

        return view('site.home', [
            'faq' => $faq,
            'plans' => $this->plans,
            'jsonLd' => SiteContent::jsonLd([
                $this->content->organization(),
                $this->content->softwareApplication(),
                $this->content->faqPage($faq),
            ]),
        ]);
    }

    public function pricing(): View
    {
        $faq = $this->content->faq('pricing');

        return view('site.pricing', [
            'faq' => $faq,
            'plans' => $this->plans,
            'jsonLd' => SiteContent::jsonLd([
                $this->content->organization(),
                $this->content->softwareApplication(),
                $this->content->faqPage($faq),
            ]),
        ]);
    }

    public function freeAudit(): View
    {
        return view('site.free-audit', [
            'jsonLd' => SiteContent::jsonLd([$this->content->organization()]),
        ]);
    }

    public function privacy(): View
    {
        return view('site.legal', ['page' => 'privacy']);
    }

    public function terms(): View
    {
        return view('site.legal', ['page' => 'terms']);
    }

    public function contact(): View
    {
        return view('site.contact');
    }

    /**
     * Contact form: honeypot ("fax" must stay empty) and 5 messages per hour per IP.
     */
    public function sendContact(Request $request): RedirectResponse
    {
        // A bot filled the hidden field: pretend it worked.
        if (filled($request->input('fax'))) {
            return back()->with('sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $request->validate(HumanVerified::rules());

        $key = 'contact:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput()->withErrors(['message' => __('site.contact.too_many')]);
        }
        RateLimiter::hit($key, 3600);

        $to = config('citevanta.site.contact_email') ?: User::query()->where('is_admin', true)->pluck('email')->all();

        if ($to !== [] && $to !== null) {
            Mail::to($to)->queue(new ContactMessage($data['name'], $data['email'], (string) ($data['company'] ?? ''), $data['message']));
        }

        return back()->with('sent', true);
    }
}
