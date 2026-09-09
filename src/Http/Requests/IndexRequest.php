<?php

namespace Didasto\RestApi\Http\Requests;

/**
 * Request for the listing: it carries filters(), sortable() and
 * relations(), but no validation rules for a body.
 */
class IndexRequest extends RestRequest
{
}
