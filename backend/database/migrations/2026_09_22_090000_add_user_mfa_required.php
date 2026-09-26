<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('users',fn(Blueprint $t)=>$t->boolean('mfa_required')->default(false)->after('mfa_last_step'));}
    public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('mfa_required'));}
};
