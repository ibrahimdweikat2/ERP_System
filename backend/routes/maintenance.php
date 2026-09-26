<?php
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Domains\Notifications\Jobs\RefreshOperationalAlerts;
Schedule::job((new RefreshOperationalAlerts)->onQueue('notifications'))->hourly()->withoutOverlapping();
Schedule::job(new \App\Domains\Reporting\Jobs\CreateBackup)->dailyAt('02:00')->withoutOverlapping(120);
Schedule::call(function(){DB::table('report_exports')->where('status','completed')->where('expires_at','<',now())->orderBy('id')->chunkById(100,function($rows){foreach($rows as $row){if($row->stored_path&&str_starts_with($row->stored_path,'exports/'))Storage::disk('documents')->delete($row->stored_path);DB::table('report_exports')->where('id',$row->id)->update(['status'=>'expired','stored_path'=>null,'updated_at'=>now()]);}});})->dailyAt('03:00')->name('expire-generated-exports')->withoutOverlapping();
