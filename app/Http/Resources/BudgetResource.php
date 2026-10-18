<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // "spent_amount" é preenchido pelo controller com o gasto do mês.
        $progress = $this->resource->progress($this->resource->getAttribute('spent_amount') ?? 0);

        return [
            'id' => $this->id,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'amount' => $this->amount,
            'spent' => $progress['spent'],
            'remaining' => $progress['remaining'],
            'percent' => $progress['percent'],
            'status' => $progress['status'],
        ];
    }
}
