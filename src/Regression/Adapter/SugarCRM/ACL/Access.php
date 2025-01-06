<?php
declare(strict_types=1);

namespace Regression\Adapter\SugarCRM\ACL;

enum Access: int
{
    case ENABLED = 89;
    case NOT_SET = 0;
    case DISABLED = 1;
}