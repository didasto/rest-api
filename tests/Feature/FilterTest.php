<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Tests\TestCase;

class FilterTest extends TestCase
{
    protected function seedArticles(): void
    {
        $this->article(['title' => 'Alpha',   'position' => 1]);
        $this->article(['title' => 'Alphabet', 'position' => 5]);
        $this->article(['title' => 'Beta',    'position' => 9]);
        $this->article(['title' => 'Gamma',   'position' => 9, 'slug' => null]);
    }

    /** @return array<int, string> */
    protected function titlesFor(string $query): array
    {
        return array_column($this->getJson('/api/articles?'.$query)->assertOk()->json(), 'title');
    }

    public function test_comparison_operators(): void
    {
        $this->seedArticles();

        $this->assertSame(['Alpha'], $this->titlesFor('filter[position][eq]=1'));
        $this->assertSame(['Alphabet', 'Beta', 'Gamma'], $this->titlesFor('filter[position][ne]=1'));
        $this->assertSame(['Alpha'], $this->titlesFor('filter[position][lt]=5'));
        $this->assertSame(['Alpha', 'Alphabet'], $this->titlesFor('filter[position][lte]=5'));
        $this->assertSame(['Beta', 'Gamma'], $this->titlesFor('filter[position][gt]=5'));
        $this->assertSame(['Alphabet', 'Beta', 'Gamma'], $this->titlesFor('filter[position][gte]=5'));
        $this->assertSame(['Alphabet', 'Beta'], $this->titlesFor('filter[position][between]=5,9&filter[title][ne]=Gamma'));
    }

    public function test_list_operators(): void
    {
        $this->seedArticles();

        $this->assertSame(['Alpha', 'Beta'], $this->titlesFor('filter[title][in]=Alpha,Beta'));
        $this->assertSame(['Alphabet', 'Gamma'], $this->titlesFor('filter[title][notIn]=Alpha,Beta'));
    }

    public function test_text_operators(): void
    {
        $this->seedArticles();

        $this->assertSame(['Alpha', 'Alphabet'], $this->titlesFor('filter[title][startsWith]=Alph'));
        $this->assertSame(['Alphabet'], $this->titlesFor('filter[title][endsWith]=bet'));
        $this->assertSame(['Alpha', 'Alphabet'], $this->titlesFor('filter[title][like]=lph'));
    }

    public function test_wildcards_in_a_search_term_are_escaped(): void
    {
        $this->article(['title' => '100 percent']);
        $this->article(['title' => '100%']);

        // Without escaping the % would match both rows.
        $this->assertSame(['100%'], $this->titlesFor('filter[title][like]=100%'));
    }

    public function test_null_operator(): void
    {
        $this->seedArticles();

        $this->assertSame(['Gamma'], $this->titlesFor('filter[slug][null]=1'));
    }

    public function test_the_short_form_means_equality(): void
    {
        $this->seedArticles();

        $this->assertSame(['Beta'], $this->titlesFor('filter[title]=Beta'));
    }

    public function test_filters_combine(): void
    {
        $this->seedArticles();

        $this->assertSame(
            ['Alphabet'],
            $this->titlesFor('filter[position][gte]=5&filter[title][startsWith]=Alph'),
        );
    }

    public function test_sorting(): void
    {
        $this->seedArticles();

        $this->assertSame(
            ['Beta', 'Gamma', 'Alphabet', 'Alpha'],
            $this->titlesFor('sort=-position,title'),
        );
    }

    public function test_an_unknown_operator_is_rejected_and_names_the_alternatives(): void
    {
        $response = $this->getJson('/api/articles?filter[id][bogus]=1')->assertStatus(422);

        $this->assertStringContainsString("Unknown filter 'id[bogus]'", $response->json('message'));
        $this->assertStringContainsString('eq, ne, lt, lte, gt, gte, in, notIn, null', $response->json('message'));
    }

    public function test_an_unknown_field_is_rejected_and_names_the_alternatives(): void
    {
        $response = $this->getJson('/api/articles?filter[secret][eq]=x')->assertStatus(422);

        $this->assertStringContainsString('Allowed fields: id, title, slug, position, created', $response->json('message'));
    }

    public function test_an_unlisted_sort_column_is_rejected(): void
    {
        $response = $this->getJson('/api/articles?sort=secret')->assertStatus(422);

        $this->assertStringContainsString("Cannot sort by 'secret'", $response->json('message'));
    }

    public function test_a_column_alias_is_honoured(): void
    {
        $this->article(['title' => 'Old']);

        // The fixture maps the field "created" onto the column created_at.
        $this->getJson('/api/articles?filter[created][gte]=2000-01-01')
            ->assertOk()
            ->assertJsonCount(1);
    }
}
