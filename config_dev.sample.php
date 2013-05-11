<?php
return array(
    'debug' => true,
    'monolog.logfile' => __DIR__.'/logs/dev.log',
    'db.options' => array(
        'driver'   => 'pdo_mysql',
        'dbname'   => 'CHANGEME',
        'user'     => 'CHANGEME',
        'password' => 'CHANGEME',
    ),
);