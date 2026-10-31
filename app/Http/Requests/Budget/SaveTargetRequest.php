<?php

namespace App\Http\Requests\Budget;

use App\Enums\TargetType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTargetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TargetType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'weekday' => ['nullable', 'required_if:type,'.TargetType::Weekly->value, 'integer', 'between:1,7'],
            'due_date' => ['nullable', 'required_if:type,'.TargetType::ByDate->value, 'date'],
            'repeat' => ['nullable', 'prohibited_unless:type,'.TargetType::ByDate->value, Rule::in(['yearly'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('target type'),
            'amount' => __('amount'),
            'weekday' => __('day of the week'),
            'due_date' => __('due date'),
            'repeat' => __('repeat'),
        ];
    }
}
