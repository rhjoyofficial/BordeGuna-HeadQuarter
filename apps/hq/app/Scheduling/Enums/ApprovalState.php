<?php

namespace App\Scheduling\Enums;

enum ApprovalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
