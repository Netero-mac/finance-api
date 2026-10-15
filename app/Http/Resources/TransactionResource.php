<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => $this->amount,
            'type' => $this->type->value,
            'date' => $this->date->toDateString(),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at,
        ];
    }
}
