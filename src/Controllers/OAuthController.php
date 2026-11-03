<?php

namespace BookStackMcp\Controllers;

use BookStack\Http\Controller;
use BookStackMcp\Config;
use BookStackMcp\OAuth\AuthCodes;
use BookStackMcp\OAuth\Clients;
use BookStackMcp\OAuth\Tokens;
use BookStackMcp\OAuth\UserAccess;
use BookStackMcp\Schema;
use BookStack\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * OAuth 2.1 authorization server for MCP clients, as per the MCP authorization spec.
 * Users sign in through BookStack's normal login, so any configured auth method
 * (standard, LDAP, SAML2, OIDC, social, MFA) applies.
 */
class OAuthController extends Controller
{
    public function __construct(
        protected Clients $clients,
        protected AuthCodes $codes,
        protected Tokens $tokens,
        protected UserAccess $userAccess,
    ) {
    }

    /**
     * RFC 9728 protected resource metadata.
     */
    public function protectedResourceMetadata(): JsonResponse
    {
        return response()->json([
            'resource'                 => Config::resourceUrl(),
            'authorization_servers'    => [Config::issuer()],
            'scopes_supported'         => [Config::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => setting('app-name'),
        ]);
    }

    /**
     * RFC 8414 authorization server metadata.
     */
    public function authorizationServerMetadata(): JsonResponse
    {
        $metadata = [
            'issuer'                                => Config::issuer(),
            'authorization_endpoint'                => url('/mcp/oauth/authorize'),
            'token_endpoint'                        => url('/mcp/oauth/token'),
            'revocation_endpoint'                   => url('/mcp/oauth/revoke'),
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported'                      => [Config::SCOPE],
            'service_documentation'                 => url('/'),
        ];

        if (Config::dynamicRegistrationEnabled()) {
            $metadata['registration_endpoint'] = url('/mcp/oauth/register');
        }

        return response()->json($metadata);
    }

    /**
     * RFC 7591 dynamic client registration.
     */
    public function register(Request $request): JsonResponse
    {
        if (!Config::dynamicRegistrationEnabled()) {
            return $this->oauthError('access_denied', 'Dynamic client registration is disabled on this instance.', 403);
        }

        $rateKey = 'bookstack-mcp:register:' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            return $this->oauthError('slow_down', 'Too many registration attempts. Try again later.', 429);
        }
        RateLimiter::hit($rateKey, 60);

        Schema::ensure();

        $redirectUris = $request->input('redirect_uris');
        if (!is_array($redirectUris) || count($redirectUris) === 0 || count($redirectUris) > 10) {
            return $this->oauthError('invalid_redirect_uri', 'Between 1 and 10 redirect_uris are required.');
        }

        foreach ($redirectUris as $uri) {
            $error = $this->clients->validateRedirectUri($uri);
            if ($error) {
                return $this->oauthError('invalid_redirect_uri', $error);
            }
        }

        $authMethod = $request->input('token_endpoint_auth_method', 'none');
        if ($authMethod !== 'none') {
            return $this->oauthError('invalid_client_metadata', 'Only public clients (token_endpoint_auth_method "none") using PKCE are supported.');
        }

        $client = $this->clients->register((string) $request->input('client_name', ''), $redirectUris);

        return response()->json([
            'client_id'                  => $client->client_id,
            'client_id_issued_at'        => strtotime($client->created_at),
            'client_name'                => $client->client_name,
            'redirect_uris'              => $client->redirect_uris,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope'                      => Config::SCOPE,
        ], 201, $this->noStoreHeaders());
    }

    /**
     * Show the consent screen. The user is already logged in to BookStack here.
     */
    public function showAuthorize(Request $request)
    {
        if (user()->isGuest()) {
            return redirect()->guest(url('/login'));
        }

        Schema::ensure();

        $validated = $this->validateAuthorizeRequest($request);
        if ($validated instanceof RedirectResponse || is_string($validated)) {
            return is_string($validated) ? $this->errorPage($validated) : $validated;
        }

        $denialReason = $this->userAccess->denialReason(user());
        if ($denialReason) {
            return $this->errorPage($denialReason);
        }

        $this->setPageTitle('Connect ' . $validated['client']->client_name);

        return view('bookstack-mcp-authorize', [
            'client'       => $validated['client'],
            'redirectHost' => parse_url($validated['redirect_uri'], PHP_URL_HOST) ?: $validated['redirect_uri'],
            'params'       => $validated['params'],
            'user'         => user(),
        ]);
    }

    /**
     * Handle the user's approve/deny decision from the consent screen.
     */
    public function submitAuthorize(Request $request)
    {
        if (user()->isGuest()) {
            return redirect()->guest(url('/login'));
        }

        $validated = $this->validateAuthorizeRequest($request);
        if ($validated instanceof RedirectResponse || is_string($validated)) {
            return is_string($validated) ? $this->errorPage($validated) : $validated;
        }

        $denialReason = $this->userAccess->denialReason(user());
        if ($denialReason) {
            return $this->errorPage($denialReason);
        }

        if ($request->input('decision') !== 'approve') {
            return $this->redirectWithParams($validated['redirect_uri'], [
                'error'             => 'access_denied',
                'error_description' => 'The user denied the request.',
                'state'             => $validated['params']['state'],
            ]);
        }

        $code = $this->codes->create([
            'client_id'      => $validated['client']->client_id,
            'user_id'        => user()->id,
            'redirect_uri'   => $validated['redirect_uri'],
            'code_challenge' => $validated['params']['code_challenge'],
            'scope'          => Config::SCOPE,
            'resource'       => Config::resourceUrl(),
        ]);

        $this->logActivity('mcp_client_authorize', $validated['client']->client_name . ' (' . $validated['client']->client_id . ')');

        return $this->redirectWithParams($validated['redirect_uri'], [
            'code'  => $code,
            'state' => $validated['params']['state'],
            'iss'   => Config::issuer(),
        ]);
    }

    /**
     * Token endpoint for authorization_code and refresh_token grants.
     */
    public function token(Request $request): JsonResponse
    {
        Schema::ensure();

        $clientId = (string) $request->input('client_id', '');
        $client = $this->clients->find($clientId);
        if (!$client) {
            return $this->oauthError('invalid_client', 'Unknown client_id.', 401);
        }

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $client),
            'refresh_token'      => $this->exchangeRefreshToken($request, $client),
            default              => $this->oauthError('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.'),
        };
    }

    protected function exchangeCode(Request $request, object $client): JsonResponse
    {
        $code = $this->codes->consume((string) $request->input('code', ''));
        if (!$code || $code['client_id'] !== $client->client_id) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid or expired.');
        }

        if ((string) $request->input('redirect_uri', '') !== $code['redirect_uri']) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $verifier = (string) $request->input('code_verifier', '');
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return $this->oauthError('invalid_grant', 'A valid PKCE code_verifier is required.');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($code['code_challenge'], $challenge)) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }

        if (!$this->resourceIsValid($request->input('resource'))) {
            return $this->oauthError('invalid_target', 'The requested resource is not served by this server.');
        }

        $user = User::query()->find($code['user_id']);
        $denialReason = $this->userAccess->denialReason($user);
        if ($denialReason) {
            return $this->oauthError('invalid_grant', $denialReason);
        }

        $tokens = $this->tokens->issue($client->client_id, $user->id, $code['scope'], $code['resource']);

        return response()->json($tokens, 200, $this->noStoreHeaders());
    }

    protected function exchangeRefreshToken(Request $request, object $client): JsonResponse
    {
        $token = $this->tokens->findByRefreshToken((string) $request->input('refresh_token', ''), $client->client_id);
        if (!$token) {
            return $this->oauthError('invalid_grant', 'The refresh token is invalid or expired.');
        }

        if (!$this->resourceIsValid($request->input('resource'))) {
            return $this->oauthError('invalid_target', 'The requested resource is not served by this server.');
        }

        // Re-check the account, so removed roles or permissions end access on refresh.
        $denialReason = $this->userAccess->denialReason(User::query()->find($token->user_id));
        if ($denialReason) {
            $this->tokens->delete($token->id);
            return $this->oauthError('invalid_grant', $denialReason);
        }

        return response()->json($this->tokens->rotate($token), 200, $this->noStoreHeaders());
    }

    /**
     * RFC 7009 token revocation.
     */
    public function revoke(Request $request): JsonResponse
    {
        Schema::ensure();

        $token = (string) $request->input('token', '');
        if ($token !== '') {
            $this->tokens->revokeByToken($token);
        }

        return response()->json([], 200, $this->noStoreHeaders());
    }

    /**
     * Validate an authorization request (GET from the client, or POST from the consent form).
     * Returns the validated data, a string error to show the user when the client
     * or redirect URI can't be trusted, or an error redirect back to the client.
     */
    protected function validateAuthorizeRequest(Request $request): array|string|RedirectResponse
    {
        $params = [
            'response_type'         => (string) $request->input('response_type', ''),
            'client_id'             => (string) $request->input('client_id', ''),
            'redirect_uri'          => (string) $request->input('redirect_uri', ''),
            'code_challenge'        => (string) $request->input('code_challenge', ''),
            'code_challenge_method' => (string) $request->input('code_challenge_method', ''),
            'state'                 => $request->input('state') === null ? null : (string) $request->input('state'),
            'scope'                 => (string) $request->input('scope', ''),
            'resource'              => $request->input('resource') === null ? null : (string) $request->input('resource'),
        ];

        $client = $this->clients->find($params['client_id']);
        if (!$client) {
            return 'This application is not registered with this BookStack instance. Try reconnecting from the application.';
        }

        if ($params['redirect_uri'] === '' || !$this->clients->redirectUriMatches($client, $params['redirect_uri'])) {
            return 'The application provided a redirect address that it did not register. The request has been stopped for your safety.';
        }

        $redirectError = function (string $error, string $description) use ($params) {
            return $this->redirectWithParams($params['redirect_uri'], [
                'error'             => $error,
                'error_description' => $description,
                'state'             => $params['state'],
            ]);
        };

        if ($params['response_type'] !== 'code') {
            return $redirectError('unsupported_response_type', 'Only the "code" response type is supported.');
        }

        if ($params['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $params['code_challenge'])) {
            return $redirectError('invalid_request', 'PKCE with code_challenge_method S256 is required.');
        }

        $requestedScopes = array_filter(explode(' ', $params['scope']));
        if (count(array_diff($requestedScopes, [Config::SCOPE])) > 0) {
            return $redirectError('invalid_scope', 'Only the "' . Config::SCOPE . '" scope is supported.');
        }

        if (!$this->resourceIsValid($params['resource'])) {
            return $redirectError('invalid_target', 'The requested resource is not served by this server.');
        }

        return [
            'client'       => $client,
            'redirect_uri' => $params['redirect_uri'],
            'params'       => $params,
        ];
    }

    /**
     * RFC 8707 resource indicator check. Absent is allowed for older clients.
     */
    protected function resourceIsValid(mixed $resource): bool
    {
        if ($resource === null || $resource === '') {
            return true;
        }

        if (!is_string($resource)) {
            return false;
        }

        $normalise = fn (string $url) => rtrim(strtolower($url), '/');

        return in_array($normalise($resource), [$normalise(Config::resourceUrl()), $normalise(Config::issuer())]);
    }

    protected function redirectWithParams(string $uri, array $params): RedirectResponse
    {
        $params = array_filter($params, fn ($value) => $value !== null);
        $separator = str_contains($uri, '?') ? '&' : '?';

        return redirect()->away($uri . $separator . http_build_query($params));
    }

    protected function errorPage(string $message)
    {
        $this->setPageTitle('MCP connection error');

        return response()->view('bookstack-mcp-error', ['message' => $message], 400);
    }

    protected function oauthError(string $error, string $description, int $status = 400): JsonResponse
    {
        return response()->json([
            'error'             => $error,
            'error_description' => $description,
        ], $status, $this->noStoreHeaders());
    }

    protected function noStoreHeaders(): array
    {
        return ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
    }
}
