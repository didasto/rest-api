# Changelog

## [Unreleased] [minor]

### Fixed
- Eine unbekannte Aktion in `defaults.actions` oder in `only`/`except` wurde
  still uebersprungen - die Route fehlte dann kommentarlos. Jetzt gibt es eine
  Exception, die den Namen, die erlaubten Aktionen und den wahrscheinlichen
  Grund nennt (eine publizierte Config aus einer aelteren Version).

## [1.0.0] - 2026-09-07
### Added
- Antwort-Schemas werden aus den Tabellenspalten des Models abgeleitet, wenn
  keine Store- oder Update-Request existiert. Casts, versteckte Felder und
  Enums werden beruecksichtigt; abschaltbar ueber `openapi.schema_from_model`.

### Changed
- **Breaking:** Die Aktionen heissen wie in Laravels Resource-Controllern:
  `list` wird zu `index`, `delete` wird zu `destroy`. Betrifft
  Controller-Methode, Routenname (`mitglieder.index`, `mitglieder.destroy`),
  `only`/`except` am Attribut und `defaults.actions` in der Config.
  `ListRequest` heisst `IndexRequest`.
- **Breaking:** Die Request-Klassen werden nicht mehr ueber die Properties
  `$listRequest`, `$storeRequest` und `$updateRequest` gesetzt, sondern ueber
  die Methode `requestFor(string $action)`. Sie kennt zusaetzlich `show` und
  `destroy`, was dort `authorize()` ermoeglicht.
- **Breaking:** `PUT` und `PATCH` nehmen dieselben Regeln entgegen. Die
  automatische Umwandlung von `required` zu `sometimes` bei `PATCH` entfaellt -
  sie tat bei `required_with`, `prohibited_unless` und Rule-Objekten
  stillschweigend das Falsche. Teil-Updates schreiben `sometimes` selbst.

### Fixed
- Ein leeres `properties` wurde als `[]` statt `{}` ausgegeben - damit war das
  Dokument nach OpenAPI ungueltig.
- `requestBody` wurde auch dann gesetzt, wenn es keine Request-Klasse und damit
  nichts zu beschreiben gab.

## [0.9.0] - 2026-09-07
### Added
- Initial Package setup and functions

