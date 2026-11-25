<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** Owners and admins invite people and delete shared data. */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
