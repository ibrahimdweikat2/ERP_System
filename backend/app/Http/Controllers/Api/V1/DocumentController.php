<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Documents\Models\DocumentAttachment;
use App\Domains\Audit\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class DocumentController extends Controller {
    private const PARENTS=['warranty_claim'=>['warranty_claims','inventory.view','inventory.transfer'],'customer'=>['customers','customers.view','customers.manage'],'installment_contract'=>['installment_contracts','installments.view','installments.create'],'customer_payment'=>['customer_payments','payments.view','payments.receive'],'supplier_payment'=>['supplier_payments','purchasing.view','purchasing.pay'],'check'=>['checks','checks.view','checks.receive'],'check_deposit'=>['check_deposit_batches','checks.view','checks.deposit'],'expense'=>['expenses','expenses.view','expenses.create'],'sales_invoice'=>['sales_invoices','sales.view','sales.create']];
    private function authorizeParent(Request $r,string $type,int $id,bool $write=false):string{$config=self::PARENTS[$type]??null;abort_unless($config,404);abort_unless($r->user()->hasPermission($config[$write?2:1]),403);abort_unless(DB::table($config[0])->where('id',$id)->exists(),404);return $config[0];}
    public function index(Request $r,string $type,int $id):JsonResponse{$this->authorizeParent($r,$type,$id);return response()->json(['data'=>DocumentAttachment::where(['entity_type'=>$type,'entity_id'=>$id])->orderByDesc('id')->get()]);}
    public function store(Request $r,string $type,int $id):JsonResponse{
        $table=$this->authorizeParent($r,$type,$id,true);$r->validate(['file'=>['required','file','mimes:pdf,jpg,jpeg,png','max:10240']]);$file=$r->file('file');$mime=$file->getMimeType();$extension=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime]??null;abort_unless($extension,422);$path='attachments/'.$type.'/'.$id.'/'.Str::uuid().'.'.$extension;$checksum=hash_file('sha256',$file->getRealPath());Storage::disk('documents')->putFileAs(dirname($path),$file,basename($path));
        try{$row=DB::transaction(function()use($table,$type,$id,$path,$checksum,$file,$mime,$r){DB::table($table)->where('id',$id)->lockForUpdate()->first();$old=DocumentAttachment::where(['entity_type'=>$type,'entity_id'=>$id,'checksum'=>$checksum])->lockForUpdate()->first();if($old)return $old;$name=mb_substr(str_replace(["\r","\n","/","\\"],'_',$file->getClientOriginalName()),0,255);$row=DocumentAttachment::create(['entity_type'=>$type,'entity_id'=>$id,'stored_path'=>$path,'original_name'=>$name,'mime_type'=>$mime,'size'=>$file->getSize(),'checksum'=>$checksum,'uploaded_by'=>$r->user()->id,'visibility_classification'=>'financial_private','created_at'=>now()]);app(RecordAudit::class)->execute('documents.attached',$type,$id,null,['attachment_id'=>$row->id,'name'=>$name],$r->user()->id);return $row;},5);}catch(\Throwable $e){try{if(!DocumentAttachment::where('stored_path',$path)->exists())Storage::disk('documents')->delete($path);}catch(\Throwable){}throw $e;}if($row->stored_path!==$path)Storage::disk('documents')->delete($path);return response()->json(['data'=>$row],201);
    }
    public function download(Request $r,string $type,int $id,int $attachment):\Symfony\Component\HttpFoundation\StreamedResponse{$this->authorizeParent($r,$type,$id);$row=DocumentAttachment::where(['entity_type'=>$type,'entity_id'=>$id])->findOrFail($attachment);abort_unless(Storage::disk('documents')->exists($row->stored_path),404);app(RecordAudit::class)->execute('documents.downloaded',$type,$id,null,['attachment_id'=>$attachment]);return Storage::disk('documents')->download($row->stored_path,$row->original_name,['Content-Type'=>$row->mime_type,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);}
}
