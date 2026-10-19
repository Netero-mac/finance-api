<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreditCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'credit_limit' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            // Limitamos a 28 para o dia existir em todos os meses.
            'closing_day' => ['required', 'integer', 'between:1,28'],
            'due_day' => ['required', 'integer', 'between:1,28'],
        ];
    }
}
