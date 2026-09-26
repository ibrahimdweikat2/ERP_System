<?php
namespace App\Domains\Installments\Actions;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
class GenerateSchedule {
    public function execute(string $amount,array $input):array {
        if(Decimal::cmp($amount,'0')<=0)throw new BusinessException('FINANCED_AMOUNT_REQUIRED','مبلغ التمويل يجب أن يكون موجباً.');
        $custom=$input['schedule']??[]; $rows=[]; $sum='0';
        if(count($custom)){
            foreach($custom as $index=>$row){
                if(Decimal::cmp($row['amount'],'0')<=0 || ($index>0 && $row['due_date']<=$custom[$index-1]['due_date']))throw new BusinessException('SCHEDULE_INVALID','أدخل أقساطاً موجبة بتواريخ متزايدة.');
                $sum=Decimal::add($sum,$row['amount']);$rows[]=['sequence_no'=>$index+1,'due_date'=>$row['due_date'],'amount'=>Decimal::money($row['amount'])];
            }
            if(Decimal::cmp($sum,$amount)!==0)throw new BusinessException('SCHEDULE_TOTAL_MISMATCH','مجموع الأقساط يجب أن يساوي المبلغ الممول تماماً.');
            return $rows;
        }
        $count=(int)($input['installment_count']??0); if($count<1 || $count>360)throw new BusinessException('SCHEDULE_COUNT_INVALID','عدد الأقساط من 1 إلى 360.');
        if(empty($input['first_due_date']))throw new BusinessException('FIRST_DUE_DATE_REQUIRED','حدد تاريخ أول قسط.');
        $first=CarbonImmutable::parse($input['first_due_date']); $frequency=$input['frequency']??'monthly';
        $regular=(string)Decimal::of($amount)->dividedBy($count,4,RoundingMode::DOWN);
        if(Decimal::cmp($regular,'0')<=0)throw new BusinessException('INSTALLMENT_TOO_SMALL','قلّل عدد الأقساط للمبلغ المحدد.');
        for($i=0;$i<$count;$i++){
            $date=match($frequency){'weekly'=>$first->addWeeks($i),'fortnightly'=>$first->addWeeks($i*2),'monthly'=>$first->addMonthsNoOverflow($i),default=>throw new BusinessException('FREQUENCY_INVALID','دورية غير مدعومة.')};
            $value=$i===$count-1?Decimal::sub($amount,$sum):$regular;$sum=Decimal::add($sum,$value);
            $rows[]=['sequence_no'=>$i+1,'due_date'=>$date->toDateString(),'amount'=>$value];
        }
        return $rows;
    }
}
