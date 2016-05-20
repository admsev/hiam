<?php

/*
 * Identity and Access Management server providing OAuth2, RBAC and logging
 *
 * @link      https://github.com/hiqdev/hiam-core
 * @package   hiam-core
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2014-2016, HiQDev (http://hiqdev.com/)
 */

return [
    'adminEmail'            => 'admin@hiqdev.com',
    'cookieValidationKey'   => '',
    'debug_allowed_ips'     => [],

    'db_name'               => 'mrdp',
    'db_user'               => 'sol',
    'db_password'           => '****',
    'poweredByName'         => 'HIAM',
    'poweredByUrl'          => 'https://github.com/hiqdev/hiam-core',

    'user.passwordResetTokenExpire' => 3600,
];
