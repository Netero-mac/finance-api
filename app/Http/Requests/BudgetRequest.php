<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];

        // Na edição só o valor muda; a categoria do orçamento é fixa.
        if ($this->route('budget')) {
            return $rules;
        }

        $rules['category_id'] = [
            'required',
            'integer',
            // Só categorias de despesa do próprio usuário.
            Rule::exists('categories', 'id')->where(fn ($query) => $query
                ->where('user_id', $this->user()->id)
                ->where('type', 'expense')),
            // Um orçamento por categoria.
            Rule::unique('budgets', 'category_id')->where('user_id', $this->user()->id),
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Escolha uma categoria de despesa sua.',
            'category_id.unique' => 'Já existe um orçamento para esta categoria.',
        ];
    }
}
