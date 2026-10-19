<?php

namespace App\Http\Resources;

use App\Services\CardPurchasePlanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'credit_limit' => $this->credit_limit,
            'closing_day' => $this->closing_day,
            'due_day' => $this->due_day,
            'used' => CardPurchasePlanner::format($this->usedCents()),
            'available' => CardPurchasePlanner::format($this->availableCents()),
        ];
    }
}
