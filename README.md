# BookStack MCP

A remote [MCP](https://modelcontextprotocol.io) server that runs **inside BookStack** as a theme module. It's PHP only: no extra service, container or Node runtime.
AI assistants (Claude, Claude Code, Cursor, …) connect to `https://your-bookstack/mcp`, users sign in with
their normal BookStack login, and every request runs as that user with their roles and permissions.

Read-only. Tools:

| Tool | What it does |
|---|---|
| `search` | Full BookStack search syntax (`"phrase"`, `[tag=value]`, `{type:page}`, …), optionally inside one book/chapter |
| `list_shelves`, `list_books`, `list_chapters`, `list_pages` | Paged listings with `count`, `offset`, `sort`, `filter` (same as the REST API) |
| `get_shelf`, `get_book`, `get_chapter` | Details plus contents (books on a shelf, book table of contents, chapter pages) |
| `get_page` | Page content as markdown (default), plain text or HTML, with metadata, tags and optional comments |
| `export_book`, `export_chapter` | Whole book/chapter content in one call (needs the *Export content* permission) |

## How auth & permissions work

- **OAuth 2.1 with PKCE**, as required by the MCP authorization spec, including discovery metadata
  (RFC 9728 / RFC 8414), dynamic client registration (RFC 7591), resource indicators (RFC 8707),
  refresh token rotation and revocation (RFC 7009).
- **Login is BookStack's own.** The authorize step is a normal BookStack page, so whatever login you've
  configured applies: email/password, LDAP, SAML2, OIDC (Entra, Google, …), social login, MFA.
  The user then sees a consent screen naming the app and where it will redirect.
- **Account rules match BookStack's API.** The user's role needs **"Access system API"**. Email confirmation is
  enforced. These are re-checked on every MCP request and token refresh, so removing the permission
  or the user cuts off access right away.
- **Content permissions are BookStack's own.** Tools call BookStack's own API controllers and visibility
  queries as the signed-in user. Results are exactly what that user would get from the REST API,
  including entity-level permission overrides.
- Tokens are random, stored only as SHA-256 hashes, and bound to the `/mcp` resource. Access tokens last 1 hour,
  refresh tokens 30 days.
- Users can see and disconnect their apps at **`/mcp/connections`**. Authorizations and disconnects
  appear in the BookStack audit log (`mcp_client_authorize`, `mcp_client_revoke`).

## Install

Needs a BookStack version with theme module support (written against **v26.09**).

1. Have a theme set, e.g. `APP_THEME=custom` in `.env`, with a `themes/custom/` folder.
2. Copy this folder to `themes/custom/modules/bookstack-mcp/`, so that it contains
   `bookstack-module.json`, `functions.php`, `src/` and `views/`. Leave out `.dev/`.
   Or zip it and run `php artisan bookstack:install-module /path/to/bookstack-mcp.zip`.
3. Create the tables. This also happens automatically on first use:
   ```bash
   php artisan bookstack-mcp:install
   ```
4. In **Settings → Roles**, give *Access system API* to the roles that should be able to use AI apps.
   Optionally give them *Export content* too, for `export_book` / `export_chapter`.

### BookStack in a sub-path (e.g. `https://example.com/wiki`)

Everything works under the sub-path. Clients find the endpoints through the `WWW-Authenticate` header
and `/wiki/.well-known/openid-configuration`. Some clients only try the root-level RFC 8414 location,
so if one fails to discover the server, also forward that location (nginx example):

```nginx
location = /.well-known/oauth-authorization-server/wiki { return 307 /wiki/.well-known/oauth-authorization-server; }
location = /.well-known/oauth-protected-resource/wiki/mcp { return 307 /wiki/.well-known/oauth-protected-resource/mcp; }
```

## Connect an app

- **Claude (web/desktop):** Settings → Connectors → *Add custom connector*, URL `https://your-bookstack/mcp`.
  Claude connects from Anthropic's cloud, so the instance must be reachable from the internet.
- **Claude Code:**
  ```bash
  claude mcp add --transport http bookstack https://your-bookstack/mcp
  ```
  Then run `/mcp` in Claude Code to sign in. This works for instances on a VPN or internal network too.

## Configuration (`.env`, all optional)

| Variable | Default | |
|---|---|---|
| `MCP_ACCESS_TOKEN_TTL` | `3600` | Access token lifetime, seconds |
| `MCP_REFRESH_TOKEN_TTL` | `2592000` | Refresh token lifetime, seconds (30 days) |
| `MCP_DYNAMIC_REGISTRATION` | `true` | Allow apps to self-register. Turn off to freeze the set of clients |
| `MCP_ALLOWED_REDIRECT_HOSTS` | *(any)* | Comma list of hosts app redirects may go to, e.g. `claude.ai,claude.com`. `localhost` is always allowed |
| `MCP_MAX_RESULT_CHARS` | `200000` | Longer tool results are truncated |
| `API_REQUESTS_PER_MIN` | `180` | BookStack's existing API rate limit, also applied per user to MCP |

## Maintenance

```bash
php artisan bookstack-mcp:prune                       # remove expired tokens & abandoned client registrations
php artisan bookstack-mcp:prune --user=jane@example.com   # disconnect every app for one user
php artisan bookstack-mcp:install --uninstall         # drop the module's tables
```

## Notes

- Uses BookStack's logical theme system. BookStack describes it as semi-stable, so re-check after BookStack upgrades.
- Endpoints added: `/mcp`, `/mcp/oauth/{authorize,token,register,revoke}`, `/mcp/connections`,
  `/.well-known/{oauth-protected-resource[/mcp],oauth-authorization-server,openid-configuration}`.
- Tables added: `mcp_oauth_clients`, `mcp_oauth_tokens`. Authorization codes live in the BookStack cache for 5 minutes.
