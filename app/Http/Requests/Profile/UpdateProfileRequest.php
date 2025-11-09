<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
            'photo' => 'sometimes|string|max:255',
            'vereda' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'user_id' => 'sometimes|int|exists:users,id',
            'role_id' => 'sometimes|int|exists:roles,id'
        ];
    }
}
