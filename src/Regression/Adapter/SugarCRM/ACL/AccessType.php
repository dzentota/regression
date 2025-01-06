<?php
declare(strict_types = 1);

namespace Regression\Adapter\SugarCRM\ACL;

enum AccessType: int
{
    case NORMAL = 1;
    case NOT_SET = 0;
    case ADMIN = 99;
    case DEVELOPER = 95;
    case ADMIN_DEVELOPER = 100;
}