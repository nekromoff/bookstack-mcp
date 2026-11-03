<?php

namespace BookStackMcp\Controllers;

use BookStack\Users\Models\User;
use BookStackMcp\Config;
use BookStackMcp\Mcp\Tools;
use BookStackMcp\OAuth\Tokens;
use BookStackMcp\OAuth\UserAccess;
use BookStackMcp\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * MCP Streamable HTTP transport (stateless, JSON responses only).
 */
class McpController
{
    protected const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        protected Tokens $tokens,
        protected UserAccess $userAccess,
        protected Tools $tools,
    ) {
    }

    public function handle(Request $request): Response|JsonResponse
    {
        Schema::ensure();

        $token = $this->tokens->findByAccessToken((string) $request->bearerToken());
        if (!$token || rtrim($token->resource, '/') !== rtrim(Config::resourceUrl(), '/')) {
            return $this->unauthorized($request->bearerToken() ? 'invalid_token' : null);
        }

        $user = User::query()->find($token->user_id);
        $denialReason = $this->userAccess->denialReason($user);
        if ($denialReason) {
            return $this->unauthorized('invalid_token', $denialReason);
        }

        // From here on, BookStack treats this request as made by this user,
        // so all content queries apply their roles & entity permissions.
        auth()->setUser($user);
        $this->tokens->touch($token);

        $rateKey = 'bookstack-mcp:user:' . $user->id;
        $perMinute = (int) config('api.requests_per_minute');
        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            return response()->json(
                $this->error(null, -32000, 'Rate limit exceeded. Try again in a minute.'),
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($rateKey)]
            );
        }
        RateLimiter::hit($rateKey, 60);

        $message = json_decode($request->getContent(), true);
        if (!is_array($message)) {
            return response()->json($this->error(null, -32700, 'Parse error'), 400);
        }

        if (array_is_list($message)) {
            return response()->json($this->error(null, -32600, 'Batch requests are not supported'), 400);
        }

        // Notifications & responses from the client need no reply.
        if (!array_key_exists('id', $message)) {
            return response('', 202);
        }

        return response()->json($this->dispatch($message));
    }

    public function methodNotAllowed(): Response
    {
        return response('This MCP server supports POST requests only.', 405, ['Allow' => 'POST']);
    }

    protected function dispatch(array $message): array
    {
        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method'] ?? null) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping'       => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->tools->definitions()]),
            'tools/call' => $this->callTool($id, $params),
            default      => $this->error($id, -32601, 'Method not found'),
        };
    }

    protected function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? '';
        $version = in_array($requested, self::PROTOCOL_VERSIONS) ? $requested : self::PROTOCOL_VERSIONS[0];
        $appName = setting('app-name');

        return [
            'protocolVersion' => $version,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => [
                'name'    => 'bookstack-mcp',
                'title'   => $appName,
                'version' => Config::VERSION,
            ],
            'instructions' => "Read-only access to the \"{$appName}\" BookStack wiki, as the signed-in user "
                . '(' . user()->name . "). Content is organised as shelves > books > chapters > pages. "
                . 'Use search to find content, then get_page to read it. Only content this user may view is returned.',
        ];
    }

    protected function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        if (!is_string($name) || !$this->tools->has($name)) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return $this->result($id, $this->tools->call($name, $arguments));
    }

    protected function unauthorized(?string $error, string $description = ''): Response
    {
        $header = 'Bearer resource_metadata="' . Config::protectedResourceMetadataUrl() . '", scope="' . Config::SCOPE . '"';
        if ($error) {
            $header .= ', error="' . $error . '"';
        }
        if ($description) {
            $header .= ', error_description="' . addslashes($description) . '"';
        }

        return response($description ?: 'Unauthorized', 401, ['WWW-Authenticate' => $header]);
    }

    protected function result(mixed $id, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
