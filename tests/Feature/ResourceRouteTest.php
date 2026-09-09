<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Tests\Fixtures\Article;
use Didasto\RestApi\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class ResourceRouteTest extends TestCase
{
    public function test_it_registers_one_route_per_action(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/articles'))
            ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        // Sorted, because the router groups its routes by method.
        $this->assertSame([
            'DELETE api/articles/{id}',
            'GET api/articles',
            'GET api/articles/{id}',
            'POST api/articles',
            'PUT|PATCH api/articles/{id}',
        ], $routes);

        $this->assertNotNull(Route::getRoutes()->getByName('articles.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('articles.destroy'));
    }

    public function test_index_returns_a_flat_array_with_pagination_headers(): void
    {
        $this->article();
        $this->article();

        $response = $this->getJson('/api/articles');

        $response->assertOk();
        $response->assertHeader('X-Total-Count', '2');
        $response->assertHeader('X-Page', '1');
        $response->assertHeader('X-Per-Page', '25');
        $response->assertHeader('X-Last-Page', '1');

        $this->assertCount(2, $response->json());
        $this->assertArrayHasKey('title', $response->json()[0]);
    }

    public function test_an_empty_result_is_an_empty_array_and_not_a_404(): void
    {
        $this->getJson('/api/articles')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_hidden_columns_stay_out_of_the_payload(): void
    {
        $this->article(['secret' => 'do not show']);

        $this->assertArrayNotHasKey('secret', $this->getJson('/api/articles')->json()[0]);
    }

    public function test_pagination_can_be_steered_and_is_capped(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->article();
        }

        $this->getJson('/api/articles?per_page=2&page=2')
            ->assertOk()
            ->assertHeader('X-Page', '2')
            ->assertHeader('X-Last-Page', '3');

        $this->getJson('/api/articles?per_page=99999')
            ->assertOk()
            ->assertHeader('X-Per-Page', '200');
    }

    public function test_show_returns_one_record_or_404(): void
    {
        $article = $this->article();

        $this->getJson("/api/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('id', $article->id);

        $this->getJson('/api/articles/999999')->assertNotFound();
    }

    public function test_store_creates_a_record_and_validates(): void
    {
        $this->postJson('/api/articles', ['title' => 'Fresh', 'position' => 4])
            ->assertCreated()
            ->assertJsonPath('title', 'Fresh')
            ->assertJsonPath('position', 4);

        $this->assertDatabaseHas('articles', ['title' => 'Fresh']);

        $this->postJson('/api/articles', ['position' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_update_accepts_put_and_patch_with_the_same_rules(): void
    {
        $article = $this->article(['title' => 'Before', 'position' => 1]);

        $this->putJson("/api/articles/{$article->id}", ['title' => 'After', 'position' => 2])
            ->assertOk()
            ->assertJsonPath('title', 'After');

        $this->patchJson("/api/articles/{$article->id}", ['position' => 3])
            ->assertOk()
            ->assertJsonPath('position', 3)
            ->assertJsonPath('title', 'After');
    }

    public function test_destroy_deletes_and_answers_with_204(): void
    {
        $article = $this->article();

        $this->deleteJson("/api/articles/{$article->id}")->assertNoContent();

        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
    }

}
