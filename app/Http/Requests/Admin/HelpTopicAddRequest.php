<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class HelpTopicAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => 'required',
            'answer' => 'required',
            'ranking' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => translate('the_question_field_cannot_be_empty'),
            'answer.required' => translate('the_answer_field_cannot_be_empty'),
            'ranking.required' => translate('the_ranking_field_cannot_be_empty'),
        ];
    }

}
