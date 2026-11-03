<?php

namespace BookStackMcp\OAuth;

use BookStack\Access\LoginService;
use BookStack\Permissions\Permission;
use BookStack\Users\Models\User;

/**
 * The same account-level rules BookStack's own API token guard applies.
 * Content-level permissions are applied separately by BookStack's
 * visibility queries, as the user is set as the active user.
 */
class UserAccess
{
    public function __construct(
        protected LoginService $loginService,
    ) {
    }

    /**
     * Returns a reason the user may not use MCP, or null if they can.
     */
    public function denialReason(?User $user): ?string
    {
        if (!$user || $user->isGuest()) {
            return 'You must be logged in to a BookStack user account.';
        }

        if (!$user->can(Permission::AccessApi)) {
            return 'Your BookStack role does not have the "Access system API" permission, which is required for MCP access. Ask a BookStack admin to grant it.';
        }

        if ($this->loginService->awaitingEmailConfirmation($user)) {
            return 'Your email address must be confirmed first.';
        }

        return null;
    }
}
