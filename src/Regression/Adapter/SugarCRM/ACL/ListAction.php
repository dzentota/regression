<?php
declare(strict_types = 1);

namespace Regression\Adapter\SugarCRM\ACL;

enum ListAction: int implements Action
{
    case ALL = 90;
    case OWNER = 75;
    case NOT_SET = 0;
    case NONE = -99;

    public function getName(): string
    {
        return 'list';
    }
}