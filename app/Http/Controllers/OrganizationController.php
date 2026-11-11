<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\User;
use App\Services\CitationAnalysis;
use App\Services\CreditLedger;
use App\Services\OrganizationQuota;
use App\Services\TenantContext;
use Database\Seeders\BatchSetupSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function __construct(private TenantContext $context, private OrganizationQuota $quota) {}

    public function index(Request $request): View
    {
        $section = $request->validate(['section' => ['nullable', Rule::in(['overview', 'brands', 'team', 'usage', 'costs'])]])['section'] ?? 'overview';
        $organization = $this->context->organization();
        Gate::authorize('view', $organization);
        if ($section === 'costs') {
            $section = 'usage';
        }

        return view('organizations.index', ['section' => $section, 'organization' => $organization, 'members' => $organization->members()->orderBy('name')->get(), 'brands' => MonitoredBrand::orderBy('name')->get(), 'plan' => $this->quota->plan()]);
    }

    private function brandData(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:180', 'official_website' => 'required|url:http,https|max:2048', 'description' => 'nullable|string|max:4000', 'industry' => 'required|string|max:100', 'country' => 'required|string|size:2|regex:/^[A-Z]{2}$/', 'aliases' => 'nullable|string|max:4000', 'verified' => 'accepted', 'active' => 'sometimes|boolean']);
        if (! app(CitationAnalysis::class)->normalize($data['official_website'])) {
            throw ValidationException::withMessages(['official_website' => 'Use a complete HTTP(S) website without credentials.']);
        }
        $aliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $data['aliases'] ?? '')))));
        if (collect([$data['name'], ...$aliases])->contains(fn ($name) => mb_strlen($name) < 2)) {
            throw ValidationException::withMessages(['aliases' => 'Use verified, unambiguous names of at least two characters.']);
        }

        return ['name' => $data['name'], 'official_website' => $data['official_website'], 'description' => $data['description'] ?? null, 'industry' => $data['industry'], 'country' => $data['country'], 'aliases' => $aliases, 'verified_at' => now(), 'active' => (bool) ($data['active'] ?? true)];
    }

    private function createBrand(Organization $organization, array $data): int
    {
        $slug = Str::slug($data['name']).'-'.Str::lower(Str::random(6));
        $id = DB::table('monitored_brands')->insertGetId([...$data, 'organization_id' => $organization->id, 'slug' => $slug, 'short_name' => $data['name'], 'aliases' => json_encode($data['aliases']), 'verified_pages' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $this->context->run($organization->id, $id, function () use ($data, $slug) {
            Hotel::create(['identifier' => $slug, 'name' => $data['name'], 'aliases' => $data['aliases'], 'official_websites' => [$data['official_website']], 'verification_url' => $data['official_website'], 'verified_at' => now()]);
            app(BatchSetupSeeder::class)->run();
        });

        return $id;
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Organization::class);

        return view('organizations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Organization::class);
        $request->validate(['organization_name' => 'required|string|max:180']);
        $data = $this->brandData($request);
        $data['active'] = true;
        [$organization,$brand] = DB::transaction(function () use ($request, $data) {
            $org = Organization::create(['name' => $request->string('organization_name')->toString(), 'slug' => Str::slug($request->string('organization_name')).'-'.Str::lower(Str::random(6))]);
            $org->forceFill(['status' => 'active', 'owner_id' => $request->user()->id, 'plan_key' => config('plans.default'), 'monthly_limit_micros' => 0])->save();
            $org->members()->attach($request->user()->id, ['role' => 'owner']);
            $brand = $this->createBrand($org, $data);

            return [$org, $brand];
        });
        $this->switchSession($request, $organization->id, $brand);

        return redirect()->route('organizations.index')->with('status', 'Organization and initial brand created. API spending remains disabled.');
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->context->organization();
        Gate::authorize('update', $organization);
        if (! $request->user()->is_platform_admin && ($request->has('plan_key') || $request->has('status') || $request->has('monthly_limit'))) {
            abort(403);
        }
        $data = $request->validate(['name' => 'required|string|max:180', 'monthly_limit' => 'prohibited', 'status' => ['sometimes', Rule::in(['active', 'suspended'])], 'plan_key' => ['sometimes', Rule::in(array_keys(config('plans.definitions')))]]);
        if ((isset($data['plan_key']) || isset($data['status'])) && ! $request->user()->is_platform_admin) {
            abort(403);
        }
        DB::transaction(function () use ($organization, $data) {
            $current = Organization::lockForUpdate()->findOrFail($organization->id);
            Gate::authorize('update', $current);
            $current->forceFill(['name' => $data['name'], ...(isset($data['status']) ? ['status' => $data['status']] : [])])->save();
            if (isset($data['plan_key'])) {
                app(CreditLedger::class)->assign($data['plan_key'], auth()->user());
            }
        });

        return back()->with('status', 'Organization settings saved. Subscription allowances apply to monitoring.');
    }

    public function brandStore(Request $request): RedirectResponse
    {
        $org = $this->context->organization();
        Gate::authorize('manageBrands', $org);
        $data = $this->brandData($request);
        $data['active'] = true;
        DB::transaction(function () use ($org, $data) {
            $this->quota->assertCapacity('brands');
            Gate::authorize('manageBrands', $org);
            $this->createBrand($org, $data);
        });

        return back()->with('status', 'Brand created. Select it in the workspace selector.');
    }

    public function brandUpdate(Request $request, MonitoredBrand $brand): RedirectResponse
    {
        Gate::authorize('manageBrands', $this->context->organization());
        $data = $this->brandData($request);
        DB::transaction(function () use ($brand, $data) {
            $locked = Organization::lockForUpdate()->findOrFail($this->context->organizationId);
            Gate::authorize('manageBrands', $locked);
            if (! $data['active'] && ! MonitoredBrand::where('id', '!=', $brand->id)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['active' => 'Keep at least one active brand.']);
            }
            if ($data['active'] && ! $brand->active) {
                $this->quota->assertCapacity('brands');
            }
            // Each brand has a private identity registry; edits never touch another brand's corrections.
            $this->context->run($brand->organization_id, $brand->id, function () use ($brand, $data) {
                $identity = Hotel::where('identifier', $brand->slug)->firstOrFail();
                $identity->update(['name' => $data['name'], 'aliases' => $data['aliases'], 'official_websites' => [$data['official_website']], 'verification_url' => $data['official_website'], 'verified_at' => $data['verified_at']]);
                if ($brand->name !== $data['name']) {
                    $brand->short_name = $data['name'];
                }
                $brand->fill($data);
                $brand->save();
            }, null, true);
        });
        if (! $data['active'] && $this->context->brandId === $brand->id) {
            $next = MonitoredBrand::where('active', true)->firstOrFail();
            $this->switchSession($request, $brand->organization_id, $next->id);
        }

        return back()->with('status', 'Brand updated. Original responses and manual corrections are preserved.');
    }

    public function memberStore(Request $request): RedirectResponse
    {
        $org = $this->context->organization();
        Gate::authorize('update', $org);
        $data = $request->validate(['email' => 'required|email|max:254', 'name' => 'required|string|max:180', 'password' => 'nullable|string|min:12|max:255', 'role' => 'required|in:administrator,viewer']);
        DB::transaction(function () use ($org, $data) {
            $locked = Organization::lockForUpdate()->findOrFail($org->id);
            Gate::authorize('update', $locked);
            $this->quota->assertCapacity('members');
            $user = User::where('email', $data['email'])->first();
            if (! $user) {
                if (empty($data['password'])) {
                    throw ValidationException::withMessages(['password' => 'Supply an initial password of at least 12 characters for a new account.']);
                }
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            }
            if ($org->members()->where('users.id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This user is already a member.']);
            }
            $org->members()->attach($user->id, ['role' => $data['role']]);
        });

        return back()->with('status', 'Member added. Deliver credentials privately; no email was sent.');
    }

    public function memberUpdate(Request $request, int $member): RedirectResponse
    {
        $org = $this->context->organization();
        Gate::authorize('update', $org);
        $data = $request->validate(['role' => 'required|in:administrator,viewer,remove']);
        DB::transaction(function () use ($org, $member, $data) {
            $current = Organization::lockForUpdate()->findOrFail($org->id);
            Gate::authorize('update', $current);
            abort_unless($current->members()->where('users.id', $member)->exists(), 404);
            abort_if($current->owner_id === $member, 422, 'Transfer ownership before changing the owner membership.');
            if ($data['role'] === 'remove') {
                $current->members()->detach($member);
            } else {
                $current->members()->updateExistingPivot($member, ['role' => $data['role']]);
            }
        });

        return back()->with('status', 'Membership updated. Access is checked again on every request and cron dispatch.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $org = $this->context->organization();
        Gate::authorize('update', $org);
        $data = $request->validate(['owner_id' => 'required|integer']);
        DB::transaction(function () use ($org, $data) {
            $current = Organization::lockForUpdate()->findOrFail($org->id);
            Gate::authorize('update', $current);
            abort_unless($current->owner_id === auth()->id(), 403);
            $new = $current->members()->where('users.id', $data['owner_id'])->firstOrFail();
            $current->members()->updateExistingPivot($current->owner_id, ['role' => 'administrator']);
            $current->members()->updateExistingPivot($new->id, ['role' => 'owner']);
            $current->forceFill(['owner_id' => $new->id])->save();
        });

        return back()->with('status', 'Ownership transferred to the selected member.');
    }

    private function switchSession(Request $request, int $organization, int $brand): void
    {
        $request->session()->put(['organization_id' => $organization, 'monitored_brand_id' => $brand]);
        $request->session()->forget(['test_submissions', 'batch_creations', 'improvement_previews']);
        $request->session()->regenerateToken();
    }

    public function switchOrganization(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization_id' => 'required|integer']);
        $org = ($request->user()->is_platform_admin ? Organization::query() : $request->user()->organizations()->where('status', 'active'))->where('organizations.id', $data['organization_id'])->firstOrFail();
        $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->where('active', true)->orderBy('id')->first();
        abort_unless($brand, 422);
        $this->switchSession($request, $org->id, $brand->id);

        return redirect()->route('dashboard');
    }

    public function switchBrand(Request $request): RedirectResponse
    {
        $data = $request->validate(['monitored_brand_id' => 'required|integer']);
        $brand = MonitoredBrand::where('active', true)->findOrFail($data['monitored_brand_id']);
        $this->switchSession($request, $brand->organization_id, $brand->id);

        return redirect()->route('dashboard');
    }
}
