<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Tests\TestCase;

class OpenApiTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function document(): array
    {
        return $this->getJson('/api/doc')->assertOk()->json();
    }

    public function test_it_describes_every_action_of_a_resource(): void
    {
        $paths = $this->document()['paths'];

        $this->assertSame(['get', 'post'], array_keys($paths['/api/articles']));
        $this->assertSame(['get', 'put', 'patch', 'delete'], array_keys($paths['/api/articles/{id}']));
    }

    public function test_the_documentation_route_documents_itself_out(): void
    {
        $this->assertArrayNotHasKey('/api/doc', $this->document()['paths']);
    }

    public function test_status_codes_are_complete(): void
    {
        $paths = $this->document()['paths'];

        $this->assertSame([200, 422], array_keys($paths['/api/articles']['get']['responses']));
        $this->assertSame([201, 422], array_keys($paths['/api/articles']['post']['responses']));
        $this->assertSame([200, 404], array_keys($paths['/api/articles/{id}']['get']['responses']));
        $this->assertSame([200, 404, 422], array_keys($paths['/api/articles/{id}']['put']['responses']));
        $this->assertSame([204, 404], array_keys($paths['/api/articles/{id}']['delete']['responses']));
    }

    public function test_pagination_headers_are_documented(): void
    {
        $headers = $this->document()['paths']['/api/articles']['get']['responses']['200']['headers'];

        $this->assertSame(
            ['X-Total-Count', 'X-Page', 'X-Per-Page', 'X-Last-Page', 'Link'],
            array_keys($headers),
        );
    }

    public function test_filters_are_one_deep_object_parameter_per_field(): void
    {
        $parameters = collect($this->document()['paths']['/api/articles']['get']['parameters'])
            ->keyBy('name');

        $this->assertTrue($parameters->has('filter[id]'));
        $this->assertFalse($parameters->has('filter[id][eq]'), 'one parameter per operator is the old shape');

        $id = $parameters['filter[id]'];

        $this->assertSame('deepObject', $id['style']);
        $this->assertTrue($id['explode']);
        $this->assertFalse($id['schema']['additionalProperties']);
        $this->assertSame(
            ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn', 'null'],
            array_keys($id['schema']['properties']),
        );

        // The type of the field wins, except where the operator differs.
        $this->assertSame('integer', $id['schema']['properties']['eq']['type']);
        $this->assertSame('boolean', $id['schema']['properties']['null']['type']);
        $this->assertSame('string', $id['schema']['properties']['in']['type']);
    }

    public function test_sort_page_and_per_page_are_documented(): void
    {
        $parameters = collect($this->document()['paths']['/api/articles']['get']['parameters'])
            ->keyBy('name');

        $this->assertStringContainsString('id, title, position', $parameters['sort']['description']);
        $this->assertSame(1, $parameters['page']['schema']['default']);
        $this->assertSame(25, $parameters['per_page']['schema']['default']);
        $this->assertSame(200, $parameters['per_page']['schema']['maximum']);
    }

    public function test_the_schema_is_derived_from_the_table_columns(): void
    {
        $properties = $this->document()['components']['schemas']['Article']['properties'];

        $this->assertSame('integer', $properties['id']['type']);
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('string', $properties['title']['type']);
        $this->assertSame('integer', $properties['position']['type']);
        $this->assertSame('number', $properties['price']['type']);
        $this->assertSame('boolean', $properties['published']['type']);
        $this->assertSame('date-time', $properties['created_at']['format']);

        $this->assertArrayNotHasKey('secret', $properties, 'hidden columns must stay out');
    }

    public function test_read_only_fields_never_reach_a_request_body(): void
    {
        $paths = $this->document()['paths'];

        // The fixture request deliberately declares a rule for id.
        $body = $paths['/api/articles']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('id', $body['properties']);
        $this->assertArrayNotHasKey('created_at', $body['properties']);
        $this->assertSame(['title', 'slug', 'position'], array_keys($body['properties']));
        $this->assertSame(['title'], $body['required']);
    }

    public function test_validation_rules_become_schema_constraints(): void
    {
        $body = $this->document()['paths']['/api/articles']['post']
            ['requestBody']['content']['application/json']['schema'];

        $this->assertSame(120, $body['properties']['title']['maxLength']);
        $this->assertTrue($body['properties']['slug']['nullable']);
        $this->assertSame(0, $body['properties']['position']['minimum']);
    }

    public function test_put_and_patch_share_the_rules_but_not_the_operation_id(): void
    {
        $item = $this->document()['paths']['/api/articles/{id}'];

        $this->assertSame(
            $item['put']['requestBody'],
            $item['patch']['requestBody'],
        );

        $this->assertNotSame($item['put']['operationId'], $item['patch']['operationId']);
    }

    public function test_a_job_api_is_documented_with_its_own_schema(): void
    {
        $document = $this->document();

        $this->assertSame(['post'], array_keys($document['paths']['/api/jobs/report']));
        $this->assertSame(['get', 'delete'], array_keys($document['paths']['/api/jobs/report/{id}']));

        $post = $document['paths']['/api/jobs/report']['post'];

        $this->assertSame([202, 422], array_keys($post['responses']));
        $this->assertSame(['Location', 'Retry-After'], array_keys($post['responses']['202']['headers']));

        $run = $document['components']['schemas']['JobRun']['properties'];

        $this->assertSame(
            ['pending', 'processing', 'finished', 'failed', 'cancelled'],
            $run['status']['enum'],
        );
        $this->assertSame(100, $run['progress']['maximum']);
    }

    public function test_the_document_is_valid_json_for_generators(): void
    {
        $raw = $this->get('/api/doc')->assertOk()->getContent();

        $this->assertStringNotContainsString('"properties": []', $raw, 'an empty properties must be {}');
        $this->assertSame('3.1.0', json_decode($raw, true)['openapi']);
    }

    public function test_routes_without_an_auth_middleware_carry_no_security(): void
    {
        $this->assertArrayNotHasKey(
            'security',
            $this->document()['paths']['/api/articles']['get'],
        );
    }

    public function test_an_auth_middleware_adds_security_and_the_matching_responses(): void
    {
        config()->set('rest-api.directories', [
            __DIR__.'/../Fixtures' => [
                'prefix'     => 'api',
                'middleware' => ['auth:api', 'role:editor'],
                'patterns'   => ['*Controller.php'],
            ],
        ]);

        // Re-register with the middleware in place.
        $this->app->make(\Didasto\RestApi\Routing\ResourceRegistrar::class)->register();

        $document = $this->document();
        $secured  = null;

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $spec) {
                if (isset($spec['security'])) {
                    $secured = $spec;

                    break 2;
                }
            }
        }

        $this->assertNotNull($secured, 'no route was marked as secured');
        $this->assertSame([['bearerAuth' => ['editor']]], $secured['security']);
        $this->assertArrayHasKey(401, $secured['responses']);
        $this->assertArrayHasKey(403, $secured['responses']);
    }
}
