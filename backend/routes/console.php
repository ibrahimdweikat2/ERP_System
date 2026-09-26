<?php

require __DIR__.'/maintenance.php';

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Models\Role;
use App\Domains\Notifications\Jobs\QueueProbe;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Models\User;
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
Artisan::command('erp:sequence-next {type} {date}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Diagnostic command is available locally only.');

        return 1;
    }
    $this->line(app(NextDocumentNumber::class)->execute($this->argument('type'), $this->argument('date')));
});
Artisan::command('erp:create-owner {email} {--name=مالك المتجر}', function () {
    $password = $this->secret('Owner password (12+ characters, mixed case and numbers)');
    $data = ['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password];
    $validator = Validator::make($data, ['name' => ['required', 'max:120'], 'email' => ['required', 'email', 'unique:users'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
    if ($validator->fails()) {
        $this->error($validator->errors()->toJson());

        return 1;
    }
    DB::transaction(function () use ($data) {
        $role = Role::where('name', 'owner')->lockForUpdate()->firstOrFail();
        if (User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->exists()) {
            throw new RuntimeException('An owner already exists; use user administration.');
        }
        $user = User::create([...$data, 'status' => 'active']);
        $user->roles()->attach($role);
        app(RecordAudit::class)->execute('users.owner_provisioned', 'user', $user->id, after: ['email' => $user->email], actorId: $user->id);
    });
    $this->info('Owner created.');

    return 0;
})->purpose('Provision the first owner interactively; no default password');
