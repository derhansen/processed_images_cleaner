<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Processed Images Cleaner',
    'description' => 'Symfony console command to clean up processed images by several conditions',
    'category' => 'be',
    'author' => 'Torben Hansen',
    'author_email' => 'torben@derhansen.com',
    'state' => 'stable',
    'version' => '1.1.3',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.3.99',
        ],
    ],
];
