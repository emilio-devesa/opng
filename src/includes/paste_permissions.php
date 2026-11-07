<?php

declare(strict_types=1);

function paste_owner_can_manage(?int $currentUserId, string $role, ?int $ownerId): bool
{
    if ($role === 'admin') {
        return $currentUserId !== null;
    }

    return $role === 'user'
        && $currentUserId !== null
        && $ownerId !== null
        && $currentUserId === $ownerId;
}
