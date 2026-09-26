<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'entry_no' => $this->entry_no, 'entry_date' => $this->entry_date, 'description' => $this->description, 'status' => $this->status, 'journal_id' => $this->journal_id, 'journal' => $this->whenLoaded('journal'), 'currency' => $this->currency, 'exchange_rate' => $this->exchange_rate, 'exchange_rate_date' => $this->exchange_rate_date, 'exchange_rate_source' => $this->exchange_rate_source, 'posted_at' => $this->posted_at, 'reference_type' => $this->reference_type, 'reference_id' => $this->reference_id, 'reversal_of_id' => $this->reversal_of_id, 'reversal_reason' => $this->reversal_reason, 'reversal_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->id), 'lines' => $this->whenLoaded('lines')];
    }
}
