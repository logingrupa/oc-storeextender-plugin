<?php return [
    // October answers validation.* from system::validation, so Laravel's Password rule
    // messages override that namespace. :attribute is the genitive 'Paroles'.
    'password' => [
        'letters' => ':attribute laukā jābūt vismaz vienam burtam.',
        'mixed' => ':attribute laukā jābūt vismaz vienam lielajam un vienam mazajam burtam.',
        'numbers' => ':attribute laukā jābūt vismaz vienam ciparam.',
        'symbols' => ':attribute laukā jābūt vismaz vienam simbolam, piemēram, ! vai #.',
        'uncompromised' => 'Šī parole ir atrasta datu noplūdē. Lūdzu, izvēlieties citu paroli.',
    ],
];
