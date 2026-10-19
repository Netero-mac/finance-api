<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'amount' => $this->amount,
            'due_date' => $this->due_date->toDateString(),
            'invoice_month' => $this->invoice_month,
            'status' => $this->isPaid() ? 'paid' : 'open',
            'paid_amount' => $this->paid_amount,
            'paid_at' => $this->paid_at?->toDateString(),
        ];
    }
}
