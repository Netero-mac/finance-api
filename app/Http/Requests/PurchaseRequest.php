<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PurchaseRequest extends PurchaseSimulationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'description' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required',
                'integer',
                // Só categorias de despesa do próprio usuário.
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->where('type', 'expense')),
            ],
        ]);
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Escolha uma categoria de despesa sua.',
        ];
    }
}
