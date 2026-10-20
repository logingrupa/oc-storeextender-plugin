<?php return [
    // October answers validation.* from system::validation, so Laravel's Password rule
    // messages override that namespace.
    'password' => [
        'letters' => 'Поле :attribute должно содержать хотя бы одну букву.',
        'mixed' => 'Поле :attribute должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле :attribute должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле :attribute должно содержать хотя бы один символ, например ! или #.',
        'uncompromised' => 'Этот пароль найден в утечке данных. Пожалуйста, выберите другой пароль.',
    ],
];
