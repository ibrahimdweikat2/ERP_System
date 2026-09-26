<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('store_settings',function(Blueprint $t){$t->string('platform_name',60)->nullable()->after('trade_name');$t->string('logo_path')->nullable()->after('platform_name');});}
    public function down():void{Schema::table('store_settings',fn(Blueprint $t)=>$t->dropColumn(['platform_name','logo_path']));}
};
