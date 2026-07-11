<?php
declare(strict_types=1);

// 本文件与 KWX 的 /api/review/_config.php 中 shared_secret 必须保持一致。
// 部署包已自动生成随机密钥，不要把它发给无关人员。
return [
    'shared_secret' => 'efa7b1040817b3717a0e49030cb814ef9976b915e06d692909323cfafafb9c19',
    'cookie_name' => 'review_session',
    'cookie_domain' => '.rcwulian.cn',
    'session_days' => 30,
    'kwx_sso_url' => 'https://audit-kwx.rcwulian.cn/api/review/sso.php',
    'kwx_proxy_origin' => 'https://audit-kwx.rcwulian.cn',
    'allowed_admin_roles' => [1, 2],
    'modules' => [
        'image' => [
            'label' => '图文审核',
            'rc' => '/res/peddingMain.html',
            'kwx' => '/res/pidtrueAndtextPedding.html',
        ],
        'reports' => [
            'label' => '投诉+举报',
            'rc' => '/res/reporthand.html',
            'kwx' => '/res/reporthand.html',
        ],
        'ai' => [
            'label' => 'AI巡查',
            'rc' => '/res/0607.html',
            'kwx' => '/res/ai_pratol.html',
        ],
        'manual' => [
            'label' => '人工巡查',
            'rc' => '/res/Patrol.html',
            'kwx' => '/res/pop_back.html',
        ],
        'review' => [
            'label' => '复审',
            'rc' => '/res/device_violation_review.html',
            'kwx' => '/res/device_violation_review.html',
        ],
        'records' => [
            'label' => '违规记录查询',
            'rc' => '/res/ban_Record.html',
            'kwx' => '/res/ban_Record.html',
        ],
    ],
];
