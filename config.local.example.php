<?php

return [
    'users' => [
        'halotec' => '$ganti_password_login_di_sini',
    ],
    'telegram' => [
        'bot_token' => 'ISI_BOT_TOKEN_ASLI',
        'chat_ids' => ['-1002149116231'],
        'webhook_key' => 'GANTI_DENGAN_KEY_PANJANG_DAN_UNIK',
        'include_responder_identity' => true,
    ],
    'runtime' => [
        'responder_label' => 'SERVER_CA_PRODUKSI',
        'responder_ip' => '172.27.x.x',
    ],
    'disk' => [
        'drive' => 'C:',
        'threshold_percent' => 5,
    ],
    'images' => [
        'roots' => [
            '\\\\10.15.42.141\\autogate_ca_server\\GatePict\\%gate%\\%date%\\',
            '\\\\10.15.42.141\\autogate_ca_server\\AutogatePictures\\%gate%\\%date%\\',
        ],
    ],
    'devices' => [
        'auth_profiles' => [
            'axis_root' => ['username' => 'root', 'password' => 'root'],
            'sony_blank' => ['username' => '', 'password' => ''],
            'hikvision_admin' => ['username' => 'admin', 'password' => 'Ipclogistic'],
        ],
    ],
];
