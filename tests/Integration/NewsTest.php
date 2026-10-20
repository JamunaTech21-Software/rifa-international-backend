<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Core\Migrator;
use App\Shared\Security\CsrfTokenManager;
use Tests\Support\Fixtures;

/** P5-03: public GET /news, GET /news/{slug} and the admin News & Updates CRUD + cover photo. */
final class NewsTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/news';

    private int $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->superAdmin = $this->createAdmin('super_admin');
        $this->signIn($this->superAdmin);
    }

    private function signIn(int $id): void
    {
        $this->loginAs($id);
        $this->app->container()->get(CsrfTokenManager::class)->token();
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    /** @return array<string, mixed> */
    private function create(array $overrides = []): array
    {
        $response = $this->request('POST', self::BASE, $overrides + [
            'title' => 'New Job Openings in Malaysia',
            'excerpt' => 'Fresh opportunities are now open.',
            'body' => "First paragraph about the openings.\r\n\r\n\r\nSecond paragraph,\nwrapped over two lines.",
        ], $this->csrf());
        $this->assertSame(201, $response->status(), $response->body());

        return $response->decodedJson()['data'];
    }

    public function test_create_splits_paragraphs_and_defaults_to_published_today(): void
    {
        $post = $this->create();

        $this->assertSame('new-job-openings-in-malaysia', $post['slug']);
        $this->assertSame('2026-10-20', $post['published_on']);
        $this->assertSame('published', $post['status']);
        $this->assertSame(['First paragraph about the openings.', 'Second paragraph, wrapped over two lines.'], $post['body']);
        $this->assertSame("First paragraph about the openings.\n\nSecond paragraph,\nwrapped over two lines.", $post['body_text']);
        $this->assertSame('new-job-openings-in-malaysia-2', $this->create()['slug']);
    }

    public function test_create_validation_messages(): void
    {
        $empty = $this->request('POST', self::BASE, ['published_on' => '20-10-2026'], $this->csrf());

        $this->assertSame([
            'title' => 'Please enter the title.',
            'excerpt' => 'Please enter a short summary.',
            'body' => 'Please write the article.',
            'published_on' => 'Enter a valid date (YYYY-MM-DD).',
        ], $empty->decodedJson()['error']['fields']);
    }

    public function test_public_list_hides_drafts_and_scheduled_posts_and_is_newest_first(): void
    {
        $old = $this->create(['title' => 'Older article', 'published_on' => '2026-09-01']);
        $new = $this->create(['title' => 'Newer article', 'published_on' => '2026-10-19']);
        $this->create(['title' => 'Draft article', 'is_published' => false]);
        $scheduled = $this->create(['title' => 'Scheduled article', 'published_on' => '2026-10-25']);
        $this->assertSame('scheduled', $scheduled['status']);

        $response = $this->request('GET', '/api/v1/news');
        $this->assertSame('public, max-age=300', $response->header('Cache-Control'));
        $list = $response->decodedJson()['data'];
        $this->assertSame([$new['id'], $old['id']], array_column($list, 'id'));
        $this->assertSame(['id', 'slug', 'title', 'published_on', 'excerpt', 'image_public_id'], array_keys($list[0]));

        $this->assertSame([$new['id']], array_column($this->request('GET', '/api/v1/news?limit=1')->decodedJson()['data'], 'id'));
        $this->assertSame(422, $this->request('GET', '/api/v1/news?limit=0')->status());

        // On its date the scheduled post appears.
        $this->clock()->freeze('2026-10-25 00:00:01');
        $this->assertSame($scheduled['id'], $this->request('GET', '/api/v1/news')->decodedJson()['data'][0]['id']);
    }

    public function test_public_article_by_slug(): void
    {
        $post = $this->create();
        $draft = $this->create(['title' => 'Draft article', 'is_published' => false]);

        $article = $this->request('GET', "/api/v1/news/{$post['slug']}")->decodedJson()['data'];
        $this->assertSame(['First paragraph about the openings.', 'Second paragraph, wrapped over two lines.'], $article['body']);
        $this->assertArrayNotHasKey('body_text', $article);
        $this->assertArrayNotHasKey('is_published', $article);

        $this->assertSame(404, $this->request('GET', "/api/v1/news/{$draft['slug']}")->status());
        $this->assertSame(404, $this->request('GET', '/api/v1/news/no-such-article')->status());
    }

    public function test_update_changes_only_sent_fields_and_keeps_the_slug(): void
    {
        $post = $this->create();

        $response = $this->request('PATCH', self::BASE . "/{$post['id']}", ['title' => 'Updated title here', 'is_published' => false], $this->csrf());
        $updated = $response->decodedJson()['data'];

        $this->assertSame($post['slug'], $updated['slug']);
        $this->assertSame('Updated title here', $updated['title']);
        $this->assertSame('draft', $updated['status']);
        $this->assertSame($post['excerpt'], $updated['excerpt']);

        $empty = $this->request('PATCH', self::BASE . "/{$post['id']}", ['body' => ''], $this->csrf());
        $this->assertSame(['body' => 'Please write the article.'], $empty->decodedJson()['error']['fields']);
    }

    public function test_admin_list_filters(): void
    {
        $this->create(['title' => 'Published article']);
        $this->create(['title' => 'Draft article', 'is_published' => false]);
        $this->create(['title' => 'Scheduled article', 'published_on' => '2026-11-01']);

        $titles = fn (string $query) => array_column($this->request('GET', self::BASE . $query)->decodedJson()['data'], 'title');

        $this->assertCount(3, $titles(''));
        $this->assertSame(['Published article'], $titles('?status=published'));
        $this->assertSame(['Scheduled article'], $titles('?status=scheduled'));
        $this->assertSame(['Draft article'], $titles('?status=draft'));
        $this->assertSame(['Draft article'], $titles('?q=draft'));
    }

    public function test_cover_photo_upload_replace_remove_and_delete_rules(): void
    {
        $post = $this->create();
        $upload = function () use ($post) {
            $png = Fixtures::png();
            $file = new UploadedFile('cover.png', Fixtures::tempFile($png), strlen($png), UPLOAD_ERR_OK, true);

            return $this->request('POST', self::BASE . "/{$post['id']}/image", ['x' => '1'], $this->csrf() + ['Content-Type' => 'multipart/form-data; boundary=x'], ['image' => $file]);
        };

        $first = $upload()->decodedJson()['data']['image_public_id'];
        $this->assertStringStartsWith('rifa/news/new-job-openings-in-malaysia-', $first);
        $second = $upload()->decodedJson()['data']['image_public_id'];
        $this->assertSame([$first], $this->images->deleted);

        $this->signIn($this->createAdmin('staff'));
        $this->assertSame(403, $this->request('DELETE', self::BASE . "/{$post['id']}", [], $this->csrf())->status());

        $this->signIn($this->superAdmin);
        $this->assertSame(204, $this->request('DELETE', self::BASE . "/{$post['id']}", [], $this->csrf())->status());
        $this->assertSame([$first, $second], $this->images->deleted);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'news.deleted'"));
    }

    public function test_starter_seed_fills_an_empty_table_once(): void
    {
        $seed = dirname(__DIR__, 2) . '/database/seeds/news.sql';
        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');

        $migrator->runFile($seed);
        $list = $this->request('GET', '/api/v1/news')->decodedJson()['data'];
        $this->assertSame(['new-job-openings-in-malaysia', 'beware-of-fraud-agents', 'how-to-prepare-your-documents'], array_column($list, 'slug'));
        $this->assertCount(3, $this->request('GET', '/api/v1/news/new-job-openings-in-malaysia')->decodedJson()['data']['body']);

        $this->db->execute("DELETE FROM news_posts WHERE slug = 'beware-of-fraud-agents'");
        $migrator->runFile($seed);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM news_posts'));
    }
}
