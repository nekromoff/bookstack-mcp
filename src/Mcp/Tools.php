<?php

namespace BookStackMcp\Mcp;

use BookStack\Entities\Controllers\BookApiController;
use BookStack\Entities\Controllers\BookshelfApiController;
use BookStack\Entities\Controllers\ChapterApiController;
use BookStack\Entities\Controllers\PageApiController;
use BookStack\Entities\Queries\EntityQueries;
use BookStack\Entities\Tools\Markdown\HtmlToMarkdown;
use BookStack\Exceptions\NotFoundException;
use BookStack\Exceptions\PermissionsException;
use BookStack\Exports\ExportFormatter;
use BookStack\Permissions\Permission;
use BookStack\Search\SearchApiController;
use BookStack\Util\HtmlToPlainText;
use BookStackMcp\Config;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Read-only MCP tools.
 *
 * Wherever possible these call BookStack's own API controllers, so results and
 * permission handling are exactly those of the official REST API for the user.
 */
class Tools
{
    public function __construct(
        protected EntityQueries $queries,
        protected ExportFormatter $exportFormatter,
    ) {
    }

    public function definitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $listing = [
            'type'       => 'object',
            'properties' => [
                'count'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Items to return (default 50).'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Items to skip, for paging.'],
                'sort'   => ['type' => 'string', 'description' => 'Field to sort by, prefixed with + or -, e.g. "-updated_at".'],
                'filter' => [
                    'type'                 => 'object',
                    'description'          => 'Field filters. Keys are field names with an optional operator suffix (eq, ne, gt, lt, gte, lte, like), e.g. {"book_id": 4} or {"name:like": "%onboarding%"}.',
                    'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
                ],
            ],
        ];
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        $tools = [
            [
                'name'        => 'search',
                'title'       => 'Search',
                'description' => 'Search shelves, books, chapters and pages. Supports BookStack search syntax: exact "phrases", [tag=value], {type:page}, {updated_by:me}, {created_by:me}, {in_name:term} and more. Optionally limit to one book or chapter.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'      => ['type' => 'string', 'description' => 'Search terms.'],
                        'book_id'    => $id + ['description' => 'Only search within this book.'],
                        'chapter_id' => $id + ['description' => 'Only search within this chapter.'],
                        'page'       => ['type' => 'integer', 'minimum' => 1, 'description' => 'Results page (default 1).'],
                        'count'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Results per page (default 20).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            ['name' => 'list_shelves', 'title' => 'List shelves', 'description' => 'List shelves.', 'inputSchema' => $listing],
            ['name' => 'list_books', 'title' => 'List books', 'description' => 'List books.', 'inputSchema' => $listing],
            ['name' => 'list_chapters', 'title' => 'List chapters', 'description' => 'List chapters. Filter by {"book_id": N} for one book.', 'inputSchema' => $listing],
            ['name' => 'list_pages', 'title' => 'List pages', 'description' => 'List pages (metadata only, no content). Filter by {"book_id": N} or {"chapter_id": N}.', 'inputSchema' => $listing],
            [
                'name'        => 'get_shelf',
                'title'       => 'Get shelf',
                'description' => 'Get a shelf with the books on it.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            ],
            [
                'name'        => 'get_book',
                'title'       => 'Get book',
                'description' => 'Get a book with its table of contents (chapters and pages).',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            ],
            [
                'name'        => 'get_chapter',
                'title'       => 'Get chapter',
                'description' => 'Get a chapter with its list of pages.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            ],
            [
                'name'        => 'get_page',
                'title'       => 'Read page',
                'description' => 'Read the content of a page.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'               => $id,
                        'format'           => ['type' => 'string', 'enum' => ['markdown', 'plaintext', 'html'], 'description' => 'Content format (default markdown).'],
                        'include_comments' => ['type' => 'boolean', 'description' => 'Include page comments (default false).'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'export_book',
                'title'       => 'Read whole book',
                'description' => 'Read the full content of every page in a book in one go. Requires the "Export content" role permission.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => $id, 'format' => ['type' => 'string', 'enum' => ['markdown', 'plaintext'], 'description' => 'Default markdown.']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'export_chapter',
                'title'       => 'Read whole chapter',
                'description' => 'Read the full content of every page in a chapter in one go. Requires the "Export content" role permission.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => $id, 'format' => ['type' => 'string', 'enum' => ['markdown', 'plaintext'], 'description' => 'Default markdown.']],
                    'required'   => ['id'],
                ],
            ],
        ];

        return array_map(fn (array $tool) => $tool + ['annotations' => $readOnly], $tools);
    }

    public function has(string $name): bool
    {
        return in_array($name, array_column($this->definitions(), 'name'));
    }

    /**
     * Run a tool, returning an MCP CallToolResult.
     */
    public function call(string $name, array $args): array
    {
        try {
            $text = match ($name) {
                'search'         => $this->search($args),
                'list_shelves'   => $this->listing(BookshelfApiController::class, $args),
                'list_books'     => $this->listing(BookApiController::class, $args),
                'list_chapters'  => $this->listing(ChapterApiController::class, $args),
                'list_pages'     => $this->listing(PageApiController::class, $args),
                'get_shelf'      => $this->read(BookshelfApiController::class, 'bookshelf', $args),
                'get_book'       => $this->read(BookApiController::class, 'book', $args),
                'get_chapter'    => $this->read(ChapterApiController::class, 'chapter', $args),
                'get_page'       => $this->getPage($args),
                'export_book'    => $this->export('book', $args),
                'export_chapter' => $this->export('chapter', $args),
            };
        } catch (NotFoundException|ModelNotFoundException) {
            return $this->toolError('Not found, or you do not have permission to view it.');
        } catch (PermissionsException $exception) {
            return $this->toolError($exception->getMessage() ?: 'You do not have permission to do that.');
        } catch (ValidationException $exception) {
            return $this->toolError('Invalid arguments: ' . implode(' ', $exception->validator->errors()->all()));
        } catch (\InvalidArgumentException $exception) {
            return $this->toolError($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            return $this->toolError('The tool failed with an internal error. Details have been logged on the BookStack server.');
        }

        return ['content' => [['type' => 'text', 'text' => $this->limit($text)]], 'isError' => false];
    }

    protected function search(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $params = ['query' => $query, 'page' => $args['page'] ?? 1, 'count' => $args['count'] ?? 20];

        if (!empty($args['chapter_id'])) {
            $data = $this->callApi(SearchApiController::class, 'chapter', $params, ['id' => (string) intval($args['chapter_id'])]);
        } elseif (!empty($args['book_id'])) {
            $data = $this->callApi(SearchApiController::class, 'book', $params, ['id' => (string) intval($args['book_id'])]);
        } else {
            $data = $this->callApi(SearchApiController::class, 'all', $params);
        }

        $results = array_map(function (array $hit) {
            $preview = $hit['preview_html']['content'] ?? '';
            unset($hit['preview_html'], $hit['slug'], $hit['priority'], $hit['template']);
            $hit['preview'] = trim(html_entity_decode(strip_tags($preview), ENT_QUOTES | ENT_HTML5));

            return $hit;
        }, $data['data'] ?? []);

        return $this->json(['total' => $data['total'] ?? 0, 'page' => (int) $params['page'], 'results' => $results]);
    }

    protected function listing(string $controller, array $args): string
    {
        $params = [
            'count'  => $args['count'] ?? 50,
            'offset' => $args['offset'] ?? 0,
        ];
        if (!empty($args['sort'])) {
            $params['sort'] = (string) $args['sort'];
        }
        if (is_array($args['filter'] ?? null)) {
            $params['filter'] = array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $args['filter']);
        }

        $data = $this->callApi($controller, 'list', $params);
        $items = $data['data'] ?? [];

        return $this->json([
            'total'    => $data['total'] ?? 0,
            'offset'   => (int) $params['offset'],
            'returned' => count($items),
            'data'     => $items,
        ]);
    }

    protected function read(string $controller, string $type, array $args): string
    {
        $id = $this->requireId($args);
        $data = $this->callApi($controller, 'read', [], ['id' => (string) $id]);

        unset($data['description_html'], $data['cover']);
        $data = ['url' => $this->queries->findVisibleById($type, $id)?->getUrl()] + $data;

        return $this->json($data);
    }

    protected function getPage(array $args): string
    {
        $id = $this->requireId($args);
        $format = $args['format'] ?? 'markdown';
        $page = $this->callApi(PageApiController::class, 'read', [], ['id' => (string) $id]);

        $content = match ($format) {
            'html'      => $page['html'] ?? '',
            'plaintext' => (new HtmlToPlainText())->convert($page['html'] ?? ''),
            default     => !empty($page['markdown']) ? $page['markdown'] : (new HtmlToMarkdown($page['html'] ?? ''))->convert(),
        };

        $tags = array_map(fn (array $tag) => $tag['value'] !== '' ? "{$tag['name']}={$tag['value']}" : $tag['name'], $page['tags'] ?? []);
        $meta = array_filter([
            'id'         => $page['id'],
            'book_id'    => $page['book_id'],
            'chapter_id' => $page['chapter_id'] ?: null,
            'url'        => $this->queries->findVisibleById('page', $id)?->getUrl(),
            'updated'    => ($page['updated_at'] ?? '') . ' by ' . ($page['updated_by']['name'] ?? 'unknown'),
            'tags'       => implode(', ', $tags),
            'draft'      => !empty($page['draft']) ? 'yes' : null,
        ]);

        $text = '# ' . $page['name'] . "\n\n";
        foreach ($meta as $key => $value) {
            $text .= "{$key}: {$value}\n";
        }
        $text .= "\n---\n\n" . trim($content) . "\n";

        if (!empty($args['include_comments'])) {
            $text .= "\n---\n\n## Comments\n\n" . ($this->formatComments($page['comments']['active'] ?? []) ?: "No comments.\n");
        }

        return $text;
    }

    protected function export(string $type, array $args): string
    {
        if (!user()->can(Permission::ContentExport)) {
            throw new PermissionsException('Your BookStack role does not have the "Export content" permission. Use get_page to read pages one at a time instead.');
        }

        $id = $this->requireId($args);
        $plain = ($args['format'] ?? 'markdown') === 'plaintext';

        if ($type === 'book') {
            $book = $this->queries->books->findVisibleByIdOrFail($id);
            return $plain ? $this->exportFormatter->bookToPlainText($book) : $this->exportFormatter->bookToMarkdown($book);
        }

        $chapter = $this->queries->chapters->findVisibleByIdOrFail($id);
        return $plain ? $this->exportFormatter->chapterToPlainText($chapter) : $this->exportFormatter->chapterToMarkdown($chapter);
    }

    protected function formatComments(array $nodes, int $depth = 0): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $comment = $node['comment'] ?? [];
            $author = $comment['created_by']['name'] ?? ('user #' . ($comment['created_by'] ?? '?'));
            $body = trim((new HtmlToPlainText())->convert($comment['html'] ?? ''));
            $text .= str_repeat('  ', $depth) . "- {$author} ({$comment['created_at']}): " . str_replace("\n", ' ', $body) . "\n";
            $text .= $this->formatComments($node['children'] ?? [], $depth + 1);
        }

        return $text;
    }

    /**
     * Call a BookStack API controller action as the current user,
     * with the given query parameters as its request input.
     */
    protected function callApi(string $controller, string $method, array $query = [], array $routeArgs = []): array
    {
        $originalRequest = app('request');
        $apiRequest = Request::create('/', 'GET', $query);
        $apiRequest->setUserResolver(fn () => user());

        app()->instance('request', $apiRequest);
        Facade::clearResolvedInstance('request');

        try {
            /** @var JsonResponse $response */
            $response = app()->call([app()->make($controller), $method], $routeArgs);
        } finally {
            app()->instance('request', $originalRequest);
            Facade::clearResolvedInstance('request');
        }

        return $response->getData(true);
    }

    protected function requireId(array $args): int
    {
        $id = intval($args['id'] ?? 0);
        if ($id < 1) {
            throw new \InvalidArgumentException('A valid numeric "id" is required.');
        }

        return $id;
    }

    protected function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function limit(string $text): string
    {
        $max = Config::maxResultChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n\n[Truncated at {$max} characters. Read individual pages or chapters for the rest.]";
    }

    protected function toolError(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
