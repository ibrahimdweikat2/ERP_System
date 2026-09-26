<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::create('report_exports',function(Blueprint $t){$t->id();$t->string('report',60);$t->json('filters');$t->string('status',20)->default('queued');$t->unsignedBigInteger('row_count')->default(0);$t->string('stored_path',255)->nullable();$t->string('error',500)->nullable();$t->foreignId('requested_by')->constrained('users')->restrictOnDelete();$t->timestamp('expires_at')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();$t->index(['requested_by','status']);});
        Schema::create('operational_alerts',function(Blueprint $t){$t->id();$t->string('dedup_key',160)->unique();$t->string('permission',80);$t->string('title',250);$t->string('message',1000);$t->string('path',250);$t->string('severity',20);$t->timestamp('created_at');$t->timestamp('expires_at')->nullable();});
        Schema::create('alert_reads',function(Blueprint $t){$t->id();$t->foreignId('alert_id')->constrained('operational_alerts')->cascadeOnDelete();$t->foreignId('user_id')->constrained()->restrictOnDelete();$t->timestamp('read_at');$t->unique(['alert_id','user_id']);});
        Schema::create('backup_runs',function(Blueprint $t){$t->id();$t->string('status',20);$t->string('path',1000)->nullable();$t->char('checksum',64)->nullable();$t->string('error',500)->nullable();$t->timestamp('started_at');$t->timestamp('completed_at')->nullable();});
    }
    public function down():void{foreach(['backup_runs','alert_reads','operational_alerts','report_exports'] as $t)Schema::dropIfExists($t);}
};
