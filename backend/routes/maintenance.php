<?php
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Domains\Notifications\Jobs\RefreshOperationalAlerts;
use App\Support\Tenancy\CompanyContext;
Schedule::job((new RefreshOperationalAlerts)->onQueue('notifications'))->hourly()->withoutOverlapping();
Schedule::job(new \App\Domains\Reporting\Jobs\CreateBackup)->dailyAt('02:00')->withoutOverlapping(120);
// Expiry is by date for every company at once; rows are addressed by id.
Schedule::call(fn()=>app(CompanyContext::class)->bypass(function(){DB::table('report_exports')->where('status','completed')->where('expires_at','<',now())->orderBy('id')->chunkById(100,function($rows){foreach($rows as $row){if($row->stored_path&&preg_match('#^(companies/\d+/)?exports/#',$row->stored_path))Storage::disk('documents')->delete($row->stored_path);DB::table('report_exports')->where('id',$row->id)->update(['status'=>'expired','stored_path'=>null,'updated_at'=>now()]);}});}))->dailyAt('03:00')->name('expire-generated-exports')->withoutOverlapping();
