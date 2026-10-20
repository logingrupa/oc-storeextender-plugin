<?php return [
    // October answers validation.* from system::validation, so Laravel's Password rule
    // messages override that namespace. :attribute is the genitive 'Slaptažodžio'.
    'password' => [
        'letters' => ':attribute laukelyje turi būti bent viena raidė.',
        'mixed' => ':attribute laukelyje turi būti bent viena didžioji ir viena mažoji raidė.',
        'numbers' => ':attribute laukelyje turi būti bent vienas skaitmuo.',
        'symbols' => ':attribute laukelyje turi būti bent vienas simbolis, pavyzdžiui, ! arba #.',
        'uncompromised' => 'Šis slaptažodis rastas duomenų nutekėjime. Pasirinkite kitą slaptažodį.',
    ],
];
