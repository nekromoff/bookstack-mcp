<?php

namespace BookStackMcp\Controllers;

use BookStack\Http\Controller;
use BookStackMcp\OAuth\Tokens;
use BookStackMcp\Schema;

/**
 * Lets users review and disconnect the AI apps connected to their account.
 */
class ConnectionsController extends Controller
{
    public function __construct(
        protected Tokens $tokens,
    ) {
    }

    public function index()
    {
        $this->preventGuestAccess();
        Schema::ensure();

        $this->setPageTitle('Connected AI apps');

        return view('bookstack-mcp-connections', [
            'connections' => $this->tokens->connectionsForUser(user()->id),
            'mcpUrl'      => url('/mcp'),
        ]);
    }

    public function revoke(string $clientId)
    {
        $this->preventGuestAccess();
        Schema::ensure();

        $this->tokens->revokeForUserAndClient(user()->id, $clientId);
        $this->logActivity('mcp_client_revoke', $clientId);
        $this->showSuccessNotification('The app has been disconnected.');

        return redirect(url('/mcp/connections'));
    }
}
