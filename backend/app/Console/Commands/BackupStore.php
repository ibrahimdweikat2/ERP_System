<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class BackupStore extends Command {
    protected $signature='erp:backup {--destination=}';protected $description='Create a consistent MySQL dump and retained attachment archive';
    public function handle():int{
        $id=DB::table('backup_runs')->insertGetId(['status'=>'running','started_at'=>now()]);$credentials=null;
        try{
            $root=$this->option('destination')?:config('erp.backup_directory');if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new \RuntimeException('Backup destination unavailable');
            $folder=rtrim($root,'/\\').DIRECTORY_SEPARATOR.now()->format('Ymd-His').'-'.$id;if(!mkdir($folder,0700))throw new \RuntimeException('Cannot create backup folder');
            $db=config('database.connections.mysql');$credentials=tempnam(sys_get_temp_dir(),'erp-backup-');chmod($credentials,0600);
            $quote=fn($v)=>'"'.str_replace(['\\','"',"\n","\r"],['\\\\','\\"','\\n','\\r'],(string)$v).'"';
            file_put_contents($credentials,"[client]\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\nhost=".$quote($db['host'])."\nport=".(int)$db['port']."\n");
            $dump=$folder.DIRECTORY_SEPARATOR.'database.sql';$process=new Process([config('erp.mysqldump_binary'),'--defaults-extra-file='.$credentials,'--single-transaction','--routines','--triggers','--events','--hex-blob','--no-tablespaces','--set-gtid-purged=OFF','--result-file='.$dump,$db['database']]);$process->setTimeout(1800);$process->mustRun();
            $zip=new \ZipArchive;if($zip->open($folder.DIRECTORY_SEPARATOR.'documents.zip',\ZipArchive::CREATE)!==true)throw new \RuntimeException('Cannot create document archive');$source=storage_path('app/financial-documents');
            if(is_dir($source))foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&!$file->isLink())$zip->addFile($file->getPathname(),substr($file->getPathname(),strlen($source)+1));$zip->addFromString('archive-info.txt','Private ERP document archive created '.now()->toIso8601String());$zip->close();
            $hash=hash_file('sha256',$dump);file_put_contents($folder.DIRECTORY_SEPARATOR.'manifest.json',json_encode(['created_at'=>now()->toIso8601String(),'database'=>$db['database'],'database_sha256'=>$hash,'documents_sha256'=>hash_file('sha256',$folder.DIRECTORY_SEPARATOR.'documents.zip')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            DB::table('backup_runs')->where('id',$id)->update(['status'=>'completed','path'=>$folder,'checksum'=>$hash,'completed_at'=>now()]);$this->info('Backup created: '.$folder);return self::SUCCESS;
        }catch(\Throwable $e){DB::table('backup_runs')->where('id',$id)->update(['status'=>'failed','error'=>'Backup failed; inspect server configuration and protected process logs.','completed_at'=>now()]);$this->error('Backup failed. Check dump executable, permissions, database access and available disk space.');report($e);return self::FAILURE;}finally{if($credentials&&is_file($credentials))unlink($credentials);}
    }
}
