<?php
return array(
    'db.options' => array(
        'driver'   => 'pdo_mysql',
        'dbname'   => 'CHANGEME',
        'user'     => 'CHANGEME',
        'password' => 'CHANGEME',
    ),
    'msoc' => array(
        'timer_delta' => 5,
        'check_membership' => false,
        'purchase_membership_url' => 'https://www.imperialcollegeunion.org/shop/club-society-project-products/malaysian-products/312/malaysian-membership-12/13',
        'committee_members' => array(
            'imperial',
            'admin',
            'logins',
            'here',
        ),
    ),
    'timezone' => 'Europe/London',
    'session.storage.options' => array(
        'name' => 'MSOC_BOOKING_SESSION'
    ),
    'monolog.level' => Monolog\Logger::INFO,
    'monolog.logfile' => __DIR__.'/logs/prod.log',
    'twig.path' => __DIR__.'/views',
);