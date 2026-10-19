<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'installments' => ['required', 'integer', 'min:1', 'max:48'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:30'], // % ao mês
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
