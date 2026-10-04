<?php

require __DIR__.'/maintenance.php';

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Notifications\Jobs\QueueProbe;
use App\Domains\Platform\Actions\CreateCompanyOwner;
use App\Domains\Platform\Models\Company;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Models\User;
use App\Rules\GloballyUniqueEmail;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('erp:queue-probe {--queue=maintenance}', function () {
    $queue = $this->option('queue');
    if (! in_array($queue, ['default', 'reports', 'exports', 'notifications', 'maintenance'], true)) {
        $this->error('Invalid queue');

        return 1;
    }
    $id = (string) Str::uuid();
    DB::transaction(function () use ($id, $queue) {
        DB::table('queue_probe_runs')->insert(['id' => $id, 'queue' => $queue, 'dispatched_at' => now()]);
        QueueProbe::dispatch($id)->onQueue($queue)->afterCommit();
    });
    $this->info($id);

    return 0;
})->purpose('Dispatch an idempotent database queue health probe');
Artisan::command('erp:sequence-next {type} {date} {--company=1}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Diagnostic command is available locally only.');

        return 1;
    }
    $this->line(app(CompanyContext::class)->run((int) $this->option('company'), fn () => app(NextDocumentNumber::class)->execute($this->argument('type'), $this->argument('date'))));
});
Artisan::command('erp:create-owner {email} {--company=1 : Company the owner belongs to} {--name=مالك المتجر}', function () {
    $company = Company::find((int) $this->option('company'));
    if (! $company) {
        $this->error('Unknown company.');

        return 1;
    }
    $password = $this->secret('Owner password (12+ characters, mixed case and numbers)');
    $data = ['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password];
    $validator = Validator::make($data, ['name' => ['required', 'max:120'], 'email' => ['required', 'email', new GloballyUniqueEmail], 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
    if ($validator->fails()) {
        $this->error($validator->errors()->toJson());

        return 1;
    }
    $hasOwner = app(CompanyContext::class)->run($company->id, fn () => User::whereHas('roles', fn ($q) => $q->where('name', RoleTemplates::OWNER))->exists());
    if ($hasOwner) {
        $this->error('This company already has an owner; use user administration.');

        return 1;
    }
    app(CreateCompanyOwner::class)->execute($company, $data);
    $this->info('Owner created for '.$company->name.'.');

    return 0;
})->purpose('Provision a company\'s first owner interactively; no default password');
Artisan::command('erp:create-superadmin {email} {--name=مدير المنصة} {--without-mfa : Password-only sign-in (two-factor can be enabled later from account security)}', function () {
    // ERP_SUPERADMIN_PASSWORD allows unattended provisioning without putting the password on the command line.
    $password = env('ERP_SUPERADMIN_PASSWORD') ?: $this->secret('Superadmin password (12+ characters, mixed case and numbers)');
    $data = ['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password];
    $validator = Validator::make($data, ['name' => ['required', 'max:120'], 'email' => ['required', 'email', 'max:255', new GloballyUniqueEmail], 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
    if ($validator->fails()) {
        $this->error($validator->errors()->toJson());

        return 1;
    }
    $mfa = ! $this->option('without-mfa');
    app(CompanyContext::class)->platform(function () use ($data, $mfa) {
        $user = new User;
        // Two-factor sign-in is the default for the account that manages every company.
        $user->forceFill([...$data, 'status' => 'active', 'is_platform_admin' => true, 'company_id' => null, 'mfa_required' => $mfa])->save();
        app(RecordAudit::class)->execute('platform.superadmin_provisioned', 'user', $user->id, after: ['email' => $user->email, 'mfa_required' => $mfa], actorId: $user->id);
    });
    $this->info($mfa ? 'Superadmin created. Two-factor setup is requested at first sign-in.' : 'Superadmin created (password-only sign-in).');

    return 0;
})->purpose('Provision a platform superadmin interactively; no default password');
