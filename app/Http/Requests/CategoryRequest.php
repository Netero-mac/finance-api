<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Na criação não existe categoria na rota; na edição, ignoramos a própria categoria.
        $category = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories')
                    ->where(fn ($query) => $query
                        ->where('user_id', $this->user()->id)
                        ->where('type', $this->input('type')))
                    ->ignore($category?->id),
            ],
            'type' => ['required', Rule::enum(TransactionType::class)],
        ];
    }
}
