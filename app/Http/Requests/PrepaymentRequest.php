<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Números das parcelas a pagar, ex.: [10, 11, 12].
            'numbers' => ['required', 'array', 'min:1', 'max:48'],
            'numbers.*' => ['integer', 'min:1', 'distinct'],
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
