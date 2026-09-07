<?php

namespace Didasto\RestApi\Jobs;

/**
 * Das Ergebnis eines Laufs - alle Felder nullable, weil zu Beginn nichts
 * davon feststeht und einzelne Jobs fehlschlagen duerfen.
 *
 * Jeder Job traegt seinen Teil ein; der naechste liest, was da ist.
 *
 *   class ProduktSucheResult extends JobResult
 *   {
 *       public ?string $asin = null;
 *       public ?string $gtin = null;
 *       public ?string $epid = null;
 *       public ?int $produktId = null;
 *   }
 *
 * Wer nachsehen will, was die Vorgaenger geliefert haben, fragt gefuellt():
 *
 *   if ($result->gefuellt('asin', 'gtin')) { ... }
 */
abstract class JobResult extends JobPayload
{
    /** Sind alle genannten Felder gesetzt? Ohne Angabe: ist ueberhaupt etwas gesetzt? */
    public function gefuellt(string ...$fields): bool
    {
        if ($fields === []) {
            return array_filter($this->toArray(), fn ($value) => $value !== null) !== [];
        }

        foreach ($fields as $field) {
            if (($this->{$field} ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /** Wie gefuellt(), aber es reicht, wenn eines der Felder da ist. */
    public function eines(string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (($this->{$field} ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
