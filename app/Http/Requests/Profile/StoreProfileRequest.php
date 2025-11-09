<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileRequest extends FormRequest
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
            'photo' => 'required|string|max:255',
            'vereda' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'user_id' => 'required|int|exists:users,id',
            'role_id' => 'required|int|exists:roles,id'
        ];
    }
}
