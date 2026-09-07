# didasto/rest-api

Model- und Custom-REST-APIs fuer Laravel. Routing ueber Attribute, OpenAPI
aus den Request-Klassen, Filter deklarativ.

Alle Methoden sind `public` oder `protected`, keine Klasse ist `final` -
jede Stufe laesst sich einzeln ersetzen.

## Model-Ressource

```php
use Didasto\RestApi\Attributes\RestResource;
use Didasto\RestApi\Http\Controllers\RestController;

#[RestResource(model: Mitglied::class, uri: 'mitglieder', except: ['delete'])]
class MitgliedController extends RestController
{
    protected ?string $listRequest   = MitgliedListRequest::class;
    protected ?string $storeRequest  = MitgliedStoreRequest::class;
    protected ?string $updateRequest = MitgliedStoreRequest::class;

    // Optional - Standard ist $model->toArray()
    public function transform(Model $model): array
    {
        return $model->only(['id', 'name', 'ort']);
    }
}
```

Daraus entstehen:

| Aktion   | Route                          | Name                 |
|----------|--------------------------------|----------------------|
| `list`   | `GET    /api/mitglieder`       | `mitglieder.list`    |
| `show`   | `GET    /api/mitglieder/{id}`  | `mitglieder.show`    |
| `store`  | `POST   /api/mitglieder`       | `mitglieder.store`   |
| `update` | `PUT|PATCH /api/mitglieder/{id}` | `mitglieder.update`|
| `delete` | `DELETE /api/mitglieder/{id}`  | `mitglieder.delete`  |

`only:` schaltet einzelne an, `except:` einzelne ab. Bei `PATCH` werden
`required`-Regeln automatisch zu `sometimes` - ein Teil-Update muss nicht
das ganze Objekt mitschicken.

## Request-Klasse

```php
class MitgliedListRequest extends RestRequest
{
    public function filters(): array
    {
        return [
            'id'       => IdFilter::class,                    // Gruppe
            'name'     => StringFilter::class,
            'saldo'    => NumericFilter::class,
            'erstellt' => new DateFilter(column: 'created_at'),
            'ort'      => [new LikeFilter(), new NullFilter()], // einzeln
        ];
    }

    public function sortable(): array { return ['id', 'name', 'saldo']; }
    public function relations(): array { return ['beitraege']; }
}
```

Abfragen: `?filter[id][gte]=5&filter[name][like]=Mei&sort=-saldo&per_page=50`

Kurzform ohne Operator bedeutet Gleichheit: `?filter[name]=Meier`

Unbekannte Felder, Operatoren oder Sortierspalten liefern 422 mit der
Liste des Erlaubten - kein stilles Ignorieren.

### Mitgelieferte Filter

Einzeln: `EqualsFilter` (eq), `NotEqualsFilter` (ne), `GreaterThanFilter`
(gt), `GreaterOrEqualFilter` (gte), `LessThanFilter` (lt),
`LessOrEqualFilter` (lte), `InFilter` (in), `NotInFilter` (notIn),
`LikeFilter` (like), `StartsWithFilter`, `EndsWithFilter`, `NullFilter`,
`BetweenFilter`.

Gruppen: `IdFilter`, `NumericFilter`, `StringFilter`, `DateFilter`,
`BooleanFilter`. Eine Gruppe ist nur eine Liste von Einzelfiltern:

```php
class SaldoFilter extends FilterGroup
{
    public function filters(): array
    {
        return [EqualsFilter::class, GreaterThanFilter::class, BetweenFilter::class];
    }

    public function type(): string { return 'number'; }
}
```

`%` und `_` im Suchbegriff werden maskiert - sie sind keine Platzhalter.

## Handgeschriebene Endpunkte

Routing wie gewohnt ueber die Spatie-Attribute, Dokumentation ueber
`#[ApiOperation]`:

```php
#[Prefix('kasse')]
class KasseController
{
    #[Post('abschluss')]
    #[ApiOperation(summary: 'Kassenabschluss buchen', tag: 'Kasse')]
    public function abschluss(AbschlussRequest $request): JsonResponse { ... }
}
```

Erbt der Request von `RestRequest`, baut der Generator den Request-Body
aus dessen `rules()`. Ohne `#[ApiOperation]` landet der Endpunkt trotzdem
im Dokument, nur ohne Beschreibung. `hidden: true` haelt ihn heraus.

## OpenAPI

`GET /api/doc` liefert das aktuelle `openapi.json`. Route, Middleware und
die einbezogenen URI-Muster stehen in `config/rest-api.php`. Im
Produktivbetrieb `REST_API_DOC_CACHE=true` setzen.

Das Antwort-Schema wird aus den Regeln der Update- bzw. Store-Request
abgeleitet, ergaenzt um `id`, `created_at` und `updated_at`. Was sich aus
einer Regel nicht ableiten laesst, ergaenzt `#[ApiSchema]` am Controller:

```php
#[ApiSchema(field: 'iban', example: 'DE02120300000000202051', format: 'iban')]
```

## Antwortformat

Flaches JSON - Einzelressourcen als Objekt, Listen als Array. Die
Paginierung steht in den Headern:

```
X-Total-Count, X-Page, X-Per-Page, X-Last-Page
Link: <...>; rel="next", <...>; rel="last"
```

## Konfiguration

```
php artisan vendor:publish --tag=rest-api-config
```

Die Verzeichnisse in `directories` werden nach `#[RestResource]`
durchsucht; `prefix` und `middleware` gelten fuer alle dort gefundenen
Ressourcen.

## Geschuetzte Endpunkte

Der Authorize-Knopf in Swagger/Scalar entsteht aus
`openapi.security.schemes`. Welche Route ein Token braucht, leitet der
Generator aus der Middleware ab - gepflegt wird also nur die Route:

```php
'security' => [
    'schemes'    => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
    'middleware' => ['auth' => 'bearerAuth', 'role' => 'bearerAuth'],
],
```

`#[Middleware(['auth:api', 'role:kassierer'])]` an der Route ergibt im
Dokument `security: [{bearerAuth: ['kassierer']}]` plus die Antworten 401
und 403. Routen ohne passende Middleware bleiben ohne `security` und sind
in der UI auch ohne Anmeldung aufrufbar.

## Job-APIs

```php
#[RestJob(key: 'mitglieder-export', uri: 'exporte/mitglieder', cancellable: true)]
class MitgliederExportController extends JobController
{
    protected ?string $storeRequest = ExportRequest::class;
    protected ?string $dataClass    = ExportData::class;
    protected ?string $resultClass  = ExportResult::class;

    public function jobs(?JobData $data): array
    {
        // Ein Element = ein Schritt in total/processed
        return array_map(fn ($jahr) => new ExportiereJahr($jahr), $data->jahre);
    }

    public function resultUrl(JobRun $run): ?string
    {
        return $run->status === JobStatus::Finished
            ? route('exporte.show', ['id' => $run->id])
            : null;
    }
}
```

| Route                              | Bedeutung                    |
|------------------------------------|------------------------------|
| `POST   /api/exporte/mitglieder`      | anstossen, liefert 202 + `id` |
| `GET    /api/exporte/mitglieder/{id}` | Stand                        |
| `DELETE /api/exporte/mitglieder/{id}` | abbrechen (nur `cancellable`) |

Antwort in beiden Faellen dasselbe feste Schema:

```json
{
  "id": 177,
  "key": "mitglieder-export",
  "status": "processing",
  "total": 7,
  "processed": 5,
  "failed": 0,
  "progress": 71,
  "message": null,
  "result_url": null,
  "created_at": "2026-09-07T21:47:15.123456Z",
  "started_at": "2026-09-07T21:47:16.001000Z",
  "finished_at": null
}
```

`status`: `pending`, `processing`, `finished`, `failed`, `cancelled`.
Solange es laeuft, nennt `Retry-After` ein sinnvolles Abfrageintervall;
`POST` setzt zusaetzlich `Location` auf die Statusroute.

Die Zahlen kommen beim Abruf live aus Laravels Batch - **die Jobs muessen
nichts zurueckmelden**. Erst wenn der Batch durch ist, werden sie
festgeschrieben; danach wird der Batch nicht mehr befragt.

`failed` zaehlt einzelne fehlgeschlagene Jobs, ohne den Lauf abzubrechen
(`allowFailures`). Sind am Ende welche uebrig, steht der Lauf auf
`failed` - die uebrigen Jobs sind aber gelaufen.

Aufraeumen: `php artisan rest-api:prune-jobs` entfernt abgeschlossene
Laeufe aelter als `jobs.prune` Tage.

### Ketten und wachsendes total

Ein Batch ist keine feste Groesse. Ein laufender Job darf weitere Jobs
anhaengen, `total` waechst dabei mit:

```php
class ExportiereJahr implements ShouldQueue
{
    use Batchable, Queueable;

    public function handle(): void
    {
        $daten = $this->sammle();

        // Noch innerhalb von handle() anhaengen - siehe Hinweis unten
        $this->batch()?->add([new SchreibeDatei($daten), new BenachrichtigeVorstand($daten)]);
    }
}
```

So bildest du eine Kette ab, ohne den Lauf vorher zu kennen: Job 1
startet mit `total: 1`, haengt zwei Jobs an, und der naechste Abruf
zeigt `total: 3`.

**Wichtig:** Das Anhaengen gehoert in `handle()`. Laravel meldet den
Erfolg eines Jobs erst, wenn `handle()` durch ist - wer erst danach
anhaengt, riskiert, dass der Lauf zwischendurch als `finished` gilt.

Faellt ein Glied der Kette aus, werden die nachfolgenden Jobs nie
angehaengt. Der Lauf steht dann auf `failed`, und `total` sagt ehrlich,
wie weit es gekommen ist:

```
Job 1 ok, Job 2 endgueltig fehlgeschlagen, Job 3 nie erzeugt
-> status: failed, total: 2, processed: 1, failed: 1, progress: 100
```

### Was als Fehlschlag zaehlt

Wiederholungen zaehlen nicht. Der Worker legt einen gescheiterten Job
zurueck in die Queue und meldet den Fehlschlag erst nach dem letzten
Versuch (`$tries` am Job oder `--tries` am Worker):

| Verlauf von Job 2 bei `tries = 3`      | processed | failed | status     |
|----------------------------------------|-----------|--------|------------|
| 1. Versuch scheitert, laeuft noch      | 1         | 0      | processing |
| 2. Versuch gelingt                     | 3         | 0      | finished   |
| alle 3 Versuche scheitern              | 2         | 1      | failed     |

Ein Job, der dreimal scheitert, bleibt also **ein** fehlgeschlagener Job.

Dahinter stecken zwei Eigenheiten von Laravels Batch, die `JobRun::sync()`
abfaengt - beide sind in der Methode kommentiert:

- Ein endgueltig fehlgeschlagener Job verringert `pending_jobs` nicht.
  Laravel setzt deshalb `finished_at` nie, sobald etwas fehlgeschlagen ist:
  `$batch->finished()` bleibt fuer immer `false`. Fertig ist der Lauf,
  wenn `pending - failed` aufgeht.
- Der Zaehler `failed_jobs` waechst bei jedem Fehlschlag, `failed_job_ids`
  wird eindeutig gefuehrt. Nach `queue:retry` laufen beide auseinander.
  Gezaehlt wird deshalb ueber die IDs - ein Job, der nach `queue:retry`
  doch noch durchlaeuft, faellt korrekt aus `failed` heraus und der Lauf
  steht wieder auf `finished`.

### Daten durch die Kette reichen

Queue-Jobs geben nichts zurueck, und was der naechste Job braucht, steht
beim Anstossen noch gar nicht fest. Deshalb haengen an jedem Lauf zwei
typisierte Objekte, die alle Jobs teilen:

- **`JobData`** - die Eingangsdaten, gebaut aus dem validierten Request.
  Unveraenderlich, jeder Job liest dasselbe.
- **`JobResult`** - das Ergebnis. Alle Felder nullable, weil zu Beginn
  nichts feststeht und einzelne Jobs ausfallen duerfen.

```php
class ProduktSucheData extends JobData
{
    public function __construct(
        public string $suchbegriff,
        public ?string $marke = null,
        public ?Quelle $quelle = null,      // Enums werden mit uebersetzt
    ) {}
}

class ProduktSucheResult extends JobResult
{
    public ?string $asin = null;
    public ?string $gtin = null;
    public ?string $epid = null;
    public ?int $produktId = null;
}
```

Am Controller eintragen, den Rest macht das Package:

```php
protected ?string $dataClass   = ProduktSucheData::class;
protected ?string $resultClass = ProduktSucheResult::class;
```

Im Job:

```php
class FindeAsinJob implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithJobRun;

    public function handle(): void
    {
        $treffer = $this->suche($this->data()->suchbegriff);

        $this->updateResult(function (ProduktSucheResult $result) use ($treffer) {
            $result->asin = $treffer->asin;
        });
    }
}
```

Der naechste Job liest, was da ist - auch wenn dazwischen etwas
fehlgeschlagen ist:

```php
$this->updateResult(function (ProduktSucheResult $result) {
    if ($result->eines('asin', 'gtin')) {         // epid darf fehlen
        $result->produktId = $this->anlegen($result)->id;
    }
});
```

`gefuellt('asin', 'gtin')` verlangt alle genannten Felder, `eines(...)`
laesst eines genuegen.

**Warum eine Closure statt `$result->asin = ...; $result->save();`:**
Gleichzeitig laufende Jobs haetten sonst beide den Stand von vor ihrem
Start und wuerden sich gegenseitig ueberschreiben - der langsamere
gewinnt, die Felder des anderen waeren weg. `updateResult()` laedt den
Datensatz unter einer Zeilensperre neu, laesst die Closure darauf
arbeiten und schreibt zurueck.

### Reihenfolge

Ein verschachteltes Array ist eine Kette und laeuft der Reihe nach - das
ist Laravels eigene Batch-Konvention:

```php
public function jobs(?JobData $data): array
{
    return [
        [new SucheProduktdatenJob(), new ErzeugeProduktJob()],   // nacheinander
        new PruefeBestandJob(),                                   // parallel dazu
    ];
}
```

Ketten zaehlen mit jedem Glied in `total` - drei verkettete Jobs sind
drei Schritte, keiner. Die Lauf-Nummer haengt das Package selbst an jeden
Job; im Job ist dafuer nichts zu tun.

### Woher die Felder eines Schemas kommen

Zwei Quellen, in dieser Reihenfolge:

1. **Die Tabellenspalten des Models.** Damit steht auch bei einer reinen
   Lese-API ein vollstaendiges Schema in der Doku. Beruecksichtigt werden
   `$casts` (auch Enum-Casts werden zu `enum`), `$hidden` und die
   Zeitstempel; Primaerschluessel und `created_at`/`updated_at` sind
   `readOnly`.
2. **Die Regeln der Update- bzw. Store-Request.** Sie ueberschreiben, was
   aus der Tabelle kam - `max:64` wird zu `maxLength`, `in:a,b` zu `enum`,
   `email` zu `format`.

Steht keine Datenbank zur Verfuegung (Pipeline, `route:cache`), faellt der
Generator still auf `id`, `created_at` und `updated_at` zurueck - die Doku
wird also nie zum Grund, warum ein Build scheitert. Abschalten laesst sich
der erste Schritt ueber `openapi.schema_from_model => false`.
