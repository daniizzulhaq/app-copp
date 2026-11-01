<?php

return [
    'plans' => [
        [100000, 500, 21000],
        [200000, 500, 25000],
        [500000, 700, 30000],
        [1000000, 700, 35000],
        [2000000, 1000, 35000],
        [5000000, 1000, 40000],
    ],
    'purchase_limit' => 3,
    'referral_rates_bps' => [1 => 1500, 2 => 300, 3 => 200, 4 => 200, 5 => 200,
        6 => 100, 7 => 100, 8 => 100, 9 => 100, 10 => 100, 11 => 100, 12 => 100, 13 => 100, 14 => 100, 15 => 100],
    'missions' => [
        'daily_registration_3' => ['label' => 'Undang 3 akun baru', 'metric' => 'registrations', 'target' => 3, 'reward' => 5000, 'daily' => true],
        'daily_registration_15' => ['label' => 'Undang 15 akun baru', 'metric' => 'registrations', 'target' => 15, 'reward' => 15000, 'daily' => true],
        'daily_topup_5' => ['label' => '5 referral top up', 'metric' => 'topups', 'target' => 5, 'reward' => 25000, 'daily' => true],
        'daily_topup_15' => ['label' => '15 referral top up', 'metric' => 'topups', 'target' => 15, 'reward' => 100000, 'daily' => true],
        'turnover_100000' => ['label' => 'Omset referral Rp100.000', 'metric' => 'turnover', 'target' => 100000, 'reward' => 2000, 'daily' => false],
        'turnover_5000000' => ['label' => 'Omset referral Rp5.000.000', 'metric' => 'turnover', 'target' => 5000000, 'reward' => 20000, 'daily' => false],
        'turnover_25000000' => ['label' => 'Omset referral Rp25.000.000', 'metric' => 'turnover', 'target' => 25000000, 'reward' => 150000, 'daily' => false],
    ],
];
