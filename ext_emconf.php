<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'mst contentfallback',
    'description' => 'try to load translated content on fallback rules',
    'state' => 'stable',
    'version' => '0.0.8',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99'
        ],
    ],
];
