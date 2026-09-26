<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('users',function(Blueprint $t){$t->text('mfa_secret')->nullable();$t->text('mfa_recovery_codes')->nullable();$t->timestamp('mfa_enabled_at')->nullable();$t->unsignedBigInteger('mfa_last_step')->nullable();});}
    public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['mfa_secret','mfa_recovery_codes','mfa_enabled_at','mfa_last_step']));}
};
