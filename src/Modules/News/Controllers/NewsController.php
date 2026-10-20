<?php

declare(strict_types=1);

namespace App\Modules\News\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\News\Services\NewsService;

final class NewsController
{
    public function __construct(private readonly NewsService $news)
    {
    }

    /** GET /api/v1/news[?limit=3] — newest first, without the article body. */
    public function index(Request $request): Response
    {
        return Response::data($this->news->publicList($request->query()))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /** GET /api/v1/news/{slug} — one article with its paragraphs. */
    public function show(Request $request): Response
    {
        return Response::data($this->news->publicArticle((string) $request->param('slug')))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
