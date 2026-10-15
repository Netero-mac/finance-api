<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A categoria precisa existir E pertencer ao usuário logado.
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Regra de negócio: o tipo da transação deve ser igual ao tipo da categoria.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $category = $this->user()->categories()->find($this->input('category_id'));

                if ($category && $category->type->value !== $this->input('type')) {
                    $validator->errors()->add(
                        'type',
                        'O tipo da transação deve ser igual ao tipo da categoria.'
                    );
                }
            },
        ];
    }
}
