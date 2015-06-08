<?php

$jobs = array(
    '5284.taipei.gov.tw' => array(
        'script' => '5284.taipei.gov.tw/update.php',
    ),
    'data.cwb.gov.tw' => array(
        'script' => 'data.cwb.gov.tw/update.php',
    ),
);

putenv('SCRIPT_TIMEOUT=120');

while (true) {
    $now = microtime(true);
    foreach ($jobs as $id => $config) {
        if (array_key_exists('prev_time', $config) and $now - $config['prev_time'] < 60) {
            continue;
        }
        $config['prev_time'] = $now;

        system("php " . $config['script']);
    }
    usleep(1000);
}
