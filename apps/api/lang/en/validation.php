<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'email' => 'The :attribute field must be a valid email address.',
    'boolean' => 'The :attribute field must be true or false.',
    'integer' => 'The :attribute field must be an integer.',
    'date' => 'The :attribute field must be a valid date.',
    'array' => 'The :attribute field must be an array.',
    'in' => 'The selected :attribute is invalid.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute does not exist.',
    'unique' => 'The :attribute has already been taken.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'max' => [
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'attributes' => [
        'preference' => 'appearance',
        'locale' => 'language',
    ],
];
