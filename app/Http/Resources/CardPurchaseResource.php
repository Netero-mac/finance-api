<?php

namespace App\Http\Resources;

use App\Services\CardPurchasePlanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $interestCents = CardPurchasePlanner::toCents($this->total_with_interest)
            - CardPurchasePlanner::toCents($this->total_amount);

        return [
            'id' => $this->id,
            'description' => $this->description,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'total_amount' => $this->total_amount,
            'installments_count' => $this->installments_count,
            'interest_rate' => $this->interest_rate,
            'total_with_interest' => $this->total_with_interest,
            'interest_total' => CardPurchasePlanner::format($interestCents),
            'purchase_date' => $this->purchase_date->toDateString(),
            'installments' => CardInstallmentResource::collection($this->whenLoaded('installments')),
        ];
    }
}
