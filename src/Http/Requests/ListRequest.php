<?php

namespace Didasto\RestApi\Http\Requests;

/**
 * Request fuer die Listenansicht: hier stehen nur filters(), sortable()
 * und relations(), keine Validierungsregeln fuer einen Body.
 */
class ListRequest extends RestRequest
{
}
