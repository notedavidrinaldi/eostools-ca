<?php

return [
    'app_name' => 'EOS Tools',
    'timezone' => 'Asia/Jakarta',
    'session_key' => 'eos_tools_user',
    'users' => [
        'halotec' => 'halotec',
    ],
    'telegram' => [
        'bot_token' => '6924587019:AAGAL14FraFfWSA4kR_lIwWY5T6rlk1UagE',
        'chat_ids' => ['-1002149116231', '-1125589160', '-4166697858'],
        'poll_state_file' => __DIR__ . '/storage/state/telegram_offset.json',
        'webhook_key' => 'eos-tools-secure-key',
        'bot_name' => 'EOS Tools',
        'aliases' => ['eos', 'eos tools', 'eostools', 'bot eos'],
    ],
    'disk' => [
        'drive' => 'C:',
        'threshold_percent' => 5,
        'state_file' => __DIR__ . '/storage/state/disk_monitor.json',
    ],
    'network' => [
        'timeout_seconds' => 2,
        'state_file' => __DIR__ . '/storage/state/network_monitor.json',
        'notify_on_change' => true,
        'targets' => [
            [
                'key' => 'pulau_payung',
                'label' => 'Server Pulau Payung',
                'type' => 'ping',
                'host' => '10.15.42.34',
            ],
            [
                'key' => 'server_cloud',
                'label' => 'Server Cloud',
                'type' => 'ping',
                'host' => '172.27.0.21',
            ],
            [
                'key' => 'lb_server',
                'label' => 'LB Server',
                'type' => 'ping',
                'host' => '172.27.0.26',
            ],
            [
                'key' => 'cusmod_domain',
                'label' => 'CUSMOD Domain',
                'type' => 'http',
                'url' => 'https://cusmod-ca.multiterminal.co.id/',
            ],
            [
                'key' => 'ca_cam_3in',
                'label' => 'CA Cam 3IN',
                'type' => 'ping',
                'host' => '10.116.224.48',
            ],
            [
                'key' => 'ca_cam_3out',
                'label' => 'CA Cam 3OUT',
                'type' => 'ping',
                'host' => '10.116.224.48',
            ],
        ],
    ],
    'controller' => [
        'state_file' => __DIR__ . '/storage/state/controller_state.json',
        'auto_disarm_after_fire' => true,
        'commands' => [
            'restart_pool',
            'restart_group',
            'restart_iis',
            'disk_report',
            'telegram_ping',
            'image_fetch',
        ],
    ],
    'iis' => [
        'app_pools' => [
            'AMS',
            'AMSGCP',
            'CGSIN',
            'CGSIN01',
            'CGSIN02',
            'CGSINGCP',
            'CGSINUAT',
            'CGSOUT',
            'CGSOUT01',
            'CGSOUTGCP',
            'CGSOUTUAT',
            'CGSMANUAL',
            'CMSERVICE',
            'CustomsRepo',
            'CustomsRepoGCP',
            'Monitoring',
        ],
        'restart_groups' => [
            'CGSIN_STACK' => ['CGSIN', 'CGSIN01', 'CGSIN02', 'CGSINGCP', 'CGSINUAT'],
            'CGSOUT_STACK' => ['CGSOUT', 'CGSOUT01', 'CGSOUTGCP', 'CGSOUTUAT'],
            'CORE_SERVICES' => ['AMS', 'AMSGCP', 'CMSERVICE', 'CustomsRepo', 'CustomsRepoGCP', 'Monitoring'],
        ],
    ],
    'images' => [
        'cache_dir' => __DIR__ . '/storage/cache',
        'cache_url_base' => 'storage/cache',
        'roots' => [
            '\\\\10.15.42.141\\autogate_ca_server\\GatePict\\%gate%\\%date%\\',
            '\\\\10.15.42.141\\autogate_ca_server\\AutogatePictures\\%gate%\\%date%\\',
        ],
        'gates' => [
            'GATE02I', 'GATE03I', 'GATE04I', 'GATE05I', 'GATE06I', 'GATE07I',
            'GATE01O', 'GATE02O', 'GATE03O', 'GATE04O', 'GATE05O', 'GATE06O',
        ],
        'max_results' => 24,
    ],
    'paths' => [
        'storage' => __DIR__ . '/storage',
        'logs' => __DIR__ . '/storage/logs',
        'app_log' => __DIR__ . '/storage/logs/activity.log',
        'telegram_log' => __DIR__ . '/storage/logs/telegram.log',
        'network_log' => __DIR__ . '/storage/logs/network.log',
    ],
];
