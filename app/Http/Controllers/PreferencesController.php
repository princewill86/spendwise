<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreferencesController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $user = $request->user();
        $preferences = $this->preferences($user->id)->first();
        $sessions = config('session.driver') === 'database' ? $this->sessions($user->id)->latest('last_activity')->limit(25)->get(['id', 'last_activity', 'ip_address'])->map(fn ($row) => [
            'id' => $row->id === $request->session()->getId() ? null : $row->id,
            'last_activity' => $row->last_activity, 'ip_address' => $row->ip_address,
        ]) : [];

        return Inertia::render('Preferences', [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'pending_deletion' => (bool) $user->deletion_requested_at, 'avatar' => $preferences?->avatar_path ? route('avatar') : null],
            'preferences' => $preferences ? collect((array) $preferences)->except(['avatar_path', 'user_id', 'created_at', 'updated_at']) : null,
            'defaults' => collect($settings->get('general', []))->only(['currency', 'theme', 'timezone']),
            'currencies' => DB::table('currencies')->where('enabled', true)->orderBy('code')->pluck('code'),
            'accounts' => DB::table('financial_accounts')->where('user_id', $user->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'currency']),
            'timezones' => timezone_identifiers_list(),
            'sessions' => $sessions,
            'mfa' => ['available' => $settings->enabled('mfa'), 'enabled' => $user->hasEnabledTwoFactorAuthentication(), 'pending' => (bool) $user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication()],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf($request->input('email') !== $user->email), 'nullable', 'current_password'],
            'default_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('user_id', $user->id)->whereNull('archived_at')],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')->where('enabled', true)],
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])], 'timezone' => ['required', 'timezone'],
            'locale' => ['required', Rule::in(['en'])], 'date_format' => ['required', Rule::in(['Y-m-d', 'd/m/Y', 'm/d/Y'])],
            'number_format' => ['required', Rule::in(['en-US', 'en-GB', 'de-DE', 'fr-FR', 'en-NG'])],
            'reporting_period' => ['required', Rule::in(['week', 'month', 'previous', 'year'])],
            'hide_balances' => ['required', 'boolean'], 'budget_alerts' => ['required', 'boolean'],
            'recurring_alerts' => ['required', 'boolean'], 'goal_alerts' => ['required', 'boolean'], 'email_alerts' => ['required', 'boolean'],
        ]);
        $changed = $data['email'] !== $user->email;
        $oldEmail = $user->email;
        DB::transaction(function () use ($request, $user, $data, $changed, $oldEmail): void {
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            if ($changed) {
                $user->forceFill(['email_verified_at' => null, 'remember_token' => null]);
                DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $data['email']])->delete();
                if (config('session.driver') === 'database') {
                    $this->sessions($user->id)->where('id', '!=', $request->session()->getId())->delete();
                }
            }
            $user->save();
            $this->store($user->id, collect($data)->except(['name', 'email', 'current_password'])->all());
        });
        if ($changed) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Preferences saved.');
    }

    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['required', Rule::in(['system', 'light', 'dark'])]]);
        $this->store($request->user()->id, $data);

        return back();
    }

    public function avatar(Request $request): RedirectResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048']]);
        $id = $request->user()->id;
        $path = $request->file('image')->store('avatars', 'local');
        abort_unless($path, 500);
        try {
            DB::transaction(function () use ($id, $path): void {
                DB::table('users')->where('id', $id)->lockForUpdate()->firstOrFail();
                $old = $this->preferences($id)->value('avatar_path');
                $this->store($id, ['avatar_path' => $path]);
                if ($old) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                }
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('status', 'Profile image updated.');
    }

    public function image(Request $request): StreamedResponse
    {
        $path = $this->preferences($request->user()->id)->value('avatar_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function session(Request $request): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database', 404);
        $data = $request->validate(['session' => ['required', 'string', 'max:100'], 'password' => ['required', 'current_password']]);
        if ($data['session'] !== $request->session()->getId()) {
            $this->sessions($request->user()->id)->where('id', $data['session'])->delete();
        }

        return back()->with('status', 'Session ended.');
    }

    public function deletion(Request $request): RedirectResponse
    {
        $data = $request->validate(['request_deletion' => ['required', 'boolean'], 'password' => ['required', 'current_password']]);
        $user = $request->user();
        abort_if($user->role === 'admin', 403, 'Transfer platform administration before requesting deletion.');
        $user->forceFill(['deletion_requested_at' => $data['request_deletion'] ? now() : null])->save();

        return back()->with('status', $data['request_deletion'] ? 'Account deletion requested.' : 'Account deletion request withdrawn.');
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $id = $user->id;

        return response()->streamDownload(function () use ($user, $id): void {
            echo '{"profile":'.json_encode($user->only('id', 'name', 'email', 'created_at', 'email_verified_at', 'deletion_requested_at'), JSON_THROW_ON_ERROR);
            echo ',"preferences":'.json_encode(collect((array) $this->preferences($id)->first())->except('avatar_path'), JSON_THROW_ON_ERROR);
            $queries = [];
            foreach (['financial_accounts', 'transactions', 'ledger_postings', 'categories', 'budgets', 'savings_goals', 'recurring_rules', 'user_alerts', 'transaction_imports'] as $table) {
                $queries[$table] = DB::table($table)->where('user_id', $id);
            }
            $queries['savings_contributions'] = DB::table('savings_contributions')->whereIn('savings_goal_id', DB::table('savings_goals')->select('id')->where('user_id', $id));
            $queries['transaction_attachments'] = DB::table('transaction_attachments')->select('id', 'transaction_id', 'filename', 'created_at', 'updated_at')
                ->whereIn('transaction_id', DB::table('transactions')->select('id')->where('user_id', $id));
            foreach ($queries as $key => $query) {
                echo ','.json_encode($key, JSON_THROW_ON_ERROR).':[';
                $separator = '';
                foreach ($query->lazyById(500) as $row) {
                    echo $separator.json_encode($row, JSON_THROW_ON_ERROR);
                    $separator = ',';
                }
                echo ']';
            }
            echo '}';
        }, 'spendwise-personal-data.json', ['Content-Type' => 'application/json']);
    }

    private function store(int $id, array $data): void
    {
        DB::table('user_preferences')->upsert([
            $data + ['user_id' => $id, 'updated_at' => now(), 'created_at' => now()],
        ], ['user_id'], [...array_keys($data), 'updated_at']);
    }

    private function preferences(int $id): Builder
    {
        return DB::table('user_preferences')->where('user_id', $id);
    }

    private function sessions(int $id): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $id);
    }
}
