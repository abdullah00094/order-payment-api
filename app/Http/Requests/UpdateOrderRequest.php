<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOrderItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    use ValidatesOrderItems;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'required', 'email', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['pending', 'confirmed', 'cancelled'])],
            'items' => ['sometimes', 'required', 'array', 'min:1', ...$this->itemsArrayRules()],
            ...$this->orderItemRules(),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->orderItemMessages();
    }
}
