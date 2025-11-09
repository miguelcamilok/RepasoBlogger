<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'sometimes|text',
            'is_read' => 'sometimes|boolean',
            'sendes_profile_id' => 'sometimes|int|exists:profiles,id',
            'receiver_profile_id' => 'sometimes|int|exists:profiles,id',
        ];
    }
}
