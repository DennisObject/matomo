<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

enum SiteAccessRole: string
{
    case View = 'view';
    case Write = 'write';
    case Admin = 'admin';
}
