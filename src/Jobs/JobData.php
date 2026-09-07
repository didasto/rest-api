<?php

namespace Didasto\RestApi\Jobs;

/**
 * Die Eingangsdaten eines Laufs - das, womit der erste Job anfaengt.
 *
 * Wird einmal aus dem validierten Request gebaut und danach nicht mehr
 * veraendert. Jeder Job der Kette liest sie ueber $this->data().
 *
 *   class ProduktSucheData extends JobData
 *   {
 *       public function __construct(
 *           public string $suchbegriff,
 *           public ?string $marke = null,
 *       ) {}
 *   }
 */
abstract class JobData extends JobPayload
{
}
