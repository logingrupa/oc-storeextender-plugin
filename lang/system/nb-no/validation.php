<?php return [
    // October answers validation.* from system::validation, so Laravel's Password rule
    // messages override that namespace.
    'password' => [
        'letters' => ':attribute må inneholde minst én bokstav.',
        'mixed' => ':attribute må inneholde minst én stor og én liten bokstav.',
        'numbers' => ':attribute må inneholde minst ett tall.',
        'symbols' => ':attribute må inneholde minst ett spesialtegn, for eksempel ! eller #.',
        'uncompromised' => 'Dette passordet finnes i en kjent datalekkasje. Velg et annet passord.',
    ],
];
