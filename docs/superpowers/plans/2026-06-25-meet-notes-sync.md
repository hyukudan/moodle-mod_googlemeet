# Meet Notes Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sincronizar las notas de Google Meet ("Notes by Gemini", un Google Doc opcional en Drive) y mostrarlas al alumno en una pestaña "Notas".

**Architecture:** Espejo del flujo de transcripción. Al sincronizar, buscar por name-matching un Google Doc en la carpeta Meet Recordings, exportarlo a HTML vía `files/{id}/export`, sanearlo con Moodle, almacenarlo en dos columnas nuevas de `googlemeet_recordings`, y renderizarlo en una pestaña visible para todos. Las notas se capturan tanto en alta como en re-sync (son tardías). De paso se arregla el endpoint REST `get` que rompía la descarga de transcripción.

**Tech Stack:** PHP 8.x, Moodle 4.0+ plugin API (XMLDB, `core\oauth2\rest`, Mustache, `format_text`/`clean_text`), PHPUnit.

## Global Constraints

- Versión previa en dev (sin commitear): `2.18.0` / `2026061500`. Nueva versión: `2.19.0` / `2026062500`.
- Árbol de trabajo SUCIO con WIP "materiales por sesión v2". NO tocar ficheros de ese WIP salvo los que esta feature necesita (`lib.php`, `locallib.php` ya están modificados por el WIP — editar solo las zonas de esta feature, commits atómicos).
- Strings de idioma OBLIGATORIOS en los tres ficheros: `lang/en/googlemeet.php` (autoritativo), `lang/es/googlemeet.php`, `lang/pt_br/googlemeet.php`. Añadir solo en es/pt rompe el render (gotcha conocido).
- Name-matching, no event-ID-matching (modelo del plugin).
- Notas = HTML saneado, visibles a TODOS (sin gate `caneditrecording`). Transcripción cruda sigue solo-profe.
- NO tocar el pipeline de análisis IA (`process_video_analysis`).
- Reparto: **codex genera, Claude verifica** contra comportamiento real / BD.
- Deploy: rsync a `formacion51/public/mod/googlemeet` → `admin/cli/upgrade.php` → `purge_caches` → reset opcache (runbook CLAUDE.md). Tras deploy re-confirmar `makerecordingspublic`.

---

### Task 1: Schema + version bump

**Files:**
- Modify: `db/install.xml:73-74` (tras `transcriptfileid`, dentro de `googlemeet_recordings`)
- Modify: `db/upgrade.php:396` (nuevo bloque antes de `return true;`)
- Modify: `version.php:28-29`

**Interfaces:**
- Produces: columnas `googlemeet_recordings.notestext` (text, null) y `googlemeet_recordings.notesdocid` (char 255, null). Disponibles automáticamente en cualquier `$DB->get_record('googlemeet_recordings', ...)`.

- [ ] **Step 1: Añadir campos a install.xml**

En `db/install.xml`, tras la línea 74 (`transcriptfileid`), insertar:

```xml
        <FIELD NAME="notestext" TYPE="text" NOTNULL="false" SEQUENCE="false" COMMENT="Sanitized HTML of the Gemini meeting notes (Google Doc)."/>
        <FIELD NAME="notesdocid" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false" COMMENT="Google Drive file ID of the notes Google Doc."/>
```

- [ ] **Step 2: Añadir paso de upgrade**

En `db/upgrade.php`, justo antes de `return true;` (línea ~396), insertar:

```php
    if ($oldversion < 2026062500) {
        // Add Gemini meeting-notes columns to recordings.
        $table = new xmldb_table('googlemeet_recordings');

        $field = new xmldb_field('notestext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'transcriptfileid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('notesdocid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'notestext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026062500, 'googlemeet');
    }
```

- [ ] **Step 3: Bump version**

En `version.php`:
```php
$plugin->release = '2.19.0';
$plugin->version = 2026062500;
```

- [ ] **Step 4: Verificar XML válido**

Run: `php -r "echo simplexml_load_file('db/install.xml') ? 'XML OK\n' : 'XML BAD\n';"`
Expected: `XML OK`

- [ ] **Step 5: Verificar PHP sin errores de sintaxis**

Run: `php -l db/upgrade.php && php -l version.php`
Expected: `No syntax errors detected` en ambos

- [ ] **Step 6: Commit**

```bash
git add db/install.xml db/upgrade.php version.php
git commit -m "feat(googlemeet): schema for Meet notes (notestext/notesdocid); v2026062500"
```

---

### Task 2: REST endpoints (`get` + `export`)

**Files:**
- Modify: `classes/rest.php:77` (dentro del array de `get_api_functions()`, tras `create_permission`)

**Interfaces:**
- Produces: API `'get'` → `GET files/{fileid}?alt=media`, response cruda. API `'export'` → `GET files/{fileid}/export?mimeType=...`, response cruda. Consumidas por `client::download_file_content()` (ya existente, hoy roto) y por el nuevo `client::export_doc_html()` (Task 3).

- [ ] **Step 1: Añadir endpoints**

En `classes/rest.php`, dentro del array que devuelve `get_api_functions()`, añadir tras el bloque `'create_permission' => [...]` (cerrar con coma el anterior):

```php
            'get' => [
                'endpoint' => 'https://www.googleapis.com/drive/v3/files/{fileid}',
                'method' => 'get',
                'args' => [
                    'fileid' => PARAM_RAW,
                    'alt' => PARAM_RAW
                ],
                'response' => 'raw'
            ],
            'export' => [
                'endpoint' => 'https://www.googleapis.com/drive/v3/files/{fileid}/export',
                'method' => 'get',
                'args' => [
                    'fileid' => PARAM_RAW,
                    'mimeType' => PARAM_RAW
                ],
                'response' => 'raw'
            ]
```

Nota de verificación: el `call()` del core (`lib/classes/oauth2/rest.php`) hace `json_decode` solo si `response === 'json'`, devuelve raw response si `=== 'headers'`, y `return $response;` (cuerpo crudo) en cualquier otro caso → `'raw'` entrega el cuerpo sin decodificar. Confirmado leyendo ese fichero.

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l classes/rest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificación funcional (Claude)**

Confirmar que `classes/client.php:732` (`download_file_content`, que llama `helper::request($service, 'get', ['fileid'=>..., 'alt'=>'media'])`) ahora resuelve a un endpoint declarado en vez de lanzar `coding_exception`. Esto repara la descarga de transcripción desde fichero como efecto secundario.

- [ ] **Step 4: Commit**

```bash
git add classes/rest.php
git commit -m "fix(googlemeet): declare Drive 'get'+'export' REST endpoints (fixes transcript download, enables notes export)"
```

---

### Task 3: Drive client — buscar y exportar notas

**Files:**
- Modify: `classes/client.php` (añadir 2 métodos privados tras `find_transcript_for_recording()`, ~línea 721; y un helper de saneado)
- Test: `tests/notes_sanitize_test.php` (nuevo)

**Interfaces:**
- Consumes: `helper::request()`, API `'export'` (Task 2), `$service` (rest), `$parents` (string), `$this->drive_quote()`.
- Produces:
  - `private function find_notes_for_recording($service, $parents, $videoname): ?array` → `['docid' => string, 'content' => string]` (HTML saneado) o `null`.
  - `private function export_doc_html($service, $docid): ?string` → HTML crudo del Doc o `null`.
  - `private function sanitize_notes_html($html): string` → HTML seguro (extrae `<body>`, pasa por `clean_text(..., FORMAT_HTML)`).

- [ ] **Step 1: Test de saneado (failing)**

Crear `tests/notes_sanitize_test.php`:

```php
<?php
namespace mod_googlemeet;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for notes HTML sanitization.
 *
 * @package   mod_googlemeet
 * @covers    \mod_googlemeet\client
 */
final class notes_sanitize_test extends \advanced_testcase {

    /**
     * Invoke the private sanitize_notes_html() via reflection.
     */
    private function sanitize(string $html): string {
        $client = new \ReflectionClass(client::class);
        $method = $client->getMethod('sanitize_notes_html');
        $method->setAccessible(true);
        // sanitize_notes_html is static-safe: no instance state used.
        $instance = $client->newInstanceWithoutConstructor();
        return $method->invoke($instance, $html);
    }

    public function test_strips_script_tags(): void {
        $this->resetAfterTest();
        $out = $this->sanitize('<html><body><p>Hola</p><script>alert(1)</script></body></html>');
        $this->assertStringContainsString('Hola', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_keeps_structure(): void {
        $this->resetAfterTest();
        $out = $this->sanitize('<html><body><h2>Temas</h2><ul><li>Punto A</li><li>Punto B</li></ul></body></html>');
        $this->assertStringContainsString('Punto A', $out);
        $this->assertStringContainsString('<li>', $out);
    }

    public function test_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->sanitize(''));
    }
}
```

- [ ] **Step 2: Ejecutar test → debe fallar**

Run: `vendor/bin/phpunit mod/googlemeet/tests/notes_sanitize_test.php` (desde Moodle root, con las salvedades de init.php/locale de CLAUDE.md)
Expected: FAIL (`sanitize_notes_html` no existe).

- [ ] **Step 3: Implementar los 3 métodos en client.php**

Tras `find_transcript_for_recording()` (cierre en ~línea 721), añadir:

```php
    /**
     * Find and fetch the Gemini meeting notes (a Google Doc) for a recording.
     *
     * Notes are optional and often generated AFTER the recording appears, so this
     * is called both for new recordings and for existing ones still missing notes.
     *
     * @param rest $service The REST service
     * @param string $parents The parent folders query (may be empty)
     * @param string $videoname The video filename
     * @return array|null ['docid' => string, 'content' => string] or null
     */
    private function find_notes_for_recording($service, $parents, $videoname) {
        $basename = pathinfo($videoname, PATHINFO_FILENAME);

        // Mirror the transcript search: prefer the Meet Recordings folder, fall back
        // to all of Drive when no folder was found (empty parents clause).
        $parentclause = !empty($parents) ? '(' . $parents . ') and ' : '';
        $notesparams = [
            'q' => $parentclause . 'trashed = false and
                    "me" in owners and
                    mimeType = "application/vnd.google-apps.document" and
                    name contains "' . $this->drive_quote($basename) . '"',
            'pageSize' => 10,
            'fields' => 'files(id,name,mimeType)'
        ];

        try {
            $response = helper::request($service, 'list', $notesparams, false);
            if (empty($response->files)) {
                return null;
            }

            // Take the first matching Doc. Name-matching to the recording basename
            // makes a wrong match very unlikely within the Meet Recordings folder.
            $docfile = $response->files[0];
            $html = $this->export_doc_html($service, $docfile->id);
            if (empty($html)) {
                return null;
            }

            $clean = $this->sanitize_notes_html($html);
            if (trim($clean) === '') {
                return null;
            }

            return [
                'docid' => $docfile->id,
                'content' => $clean,
            ];
        } catch (\Exception $e) {
            debugging("Failed to fetch notes: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Export a Google Doc to HTML.
     *
     * @param rest $service The REST service
     * @param string $docid The Google Doc file ID
     * @return string|null The HTML body or null on failure
     */
    private function export_doc_html($service, $docid) {
        try {
            $params = ['fileid' => $docid, 'mimeType' => 'text/html'];
            return helper::request($service, 'export', $params, false);
        } catch (\Exception $e) {
            debugging("Failed to export notes doc {$docid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Sanitize the HTML exported from a Google Doc into safe, structure-preserving HTML.
     *
     * Google Docs HTML wraps content in <html><head><style>...</style></head><body>...</body>.
     * We keep only the body's inner HTML and run it through Moodle's clean_text() with
     * FORMAT_HTML, which strips scripts/styles/event handlers but preserves headings/lists.
     *
     * @param string $html Raw exported HTML
     * @return string Safe HTML (may be empty string)
     */
    private function sanitize_notes_html($html) {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Extract inner <body> if present; otherwise use the whole string.
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // Drop <style> blocks Google Docs inlines in the body, then let Moodle sanitize.
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        return trim(clean_text($html, FORMAT_HTML));
    }
```

- [ ] **Step 4: Ejecutar test → debe pasar**

Run: `vendor/bin/phpunit mod/googlemeet/tests/notes_sanitize_test.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l classes/client.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add classes/client.php tests/notes_sanitize_test.php
git commit -m "feat(googlemeet): client methods to find/export/sanitize Gemini notes Doc"
```

---

### Task 4: Cableado en `syncrecordings()`

**Files:**
- Modify: `classes/client.php:444-446` (precarga, junto a `$existingids`)
- Modify: `classes/client.php:481-499` (rama de notas tras la de transcripción)

**Interfaces:**
- Consumes: `find_notes_for_recording()` (Task 3), `$existingids` (ya existente).
- Produces: `$recordings[$i]->notestext` y `$recordings[$i]->notesdocid` poblados cuando hay notas, para grabaciones nuevas O existentes sin notas. Consumido por `sync_recordings()` (Task 5).

- [ ] **Step 1: Precargar mapa de notas existentes**

En `classes/client.php`, junto a la precarga de `$existingids` (líneas 444-446), añadir tras `$existingids = array_flip($existingids);`:

```php
                // Map recordingid => notestext so we know which existing recordings
                // still lack notes (Gemini notes are often generated later).
                $existingnotes = $DB->get_records_menu('googlemeet_recordings',
                    ['googlemeetid' => $googlemeet->id], '', 'recordingid, notestext');
```

- [ ] **Step 2: Añadir la rama de notas**

En `classes/client.php`, el bloque actual `if (!isset($existingids[$recording->id])) { ... transcript ... }` (líneas 481-499) queda SOLO para transcripción. Inmediatamente DESPUÉS del cierre de ese `if` (antes de `unset($recordings[$i]->id);` en línea 501), insertar:

```php
                        // Notes are fetched for new recordings AND for existing ones
                        // that still have no notes, because Gemini notes are often
                        // published after the recording first appears.
                        $existingnotestext = $existingnotes[$recording->id] ?? null;
                        if (!isset($existingids[$recording->id]) || empty($existingnotestext)) {
                            $notesdata = $this->find_notes_for_recording($service, $parents, $recording->name);
                            if ($notesdata) {
                                $recordings[$i]->notestext = $notesdata['content'];
                                $recordings[$i]->notesdocid = $notesdata['docid'];
                            }
                        }
```

Nota: los `unset()` de líneas 501-503 (`id`, `permissionIds`, `videoMediaMetadata`) no afectan a `notestext`/`notesdocid`, así que sobreviven al paso a `sync_recordings()`.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l classes/client.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verificación (Claude)**

Revisar que `$DB` está en scope (sí: `global $PAGE, $DB, $CFG;` en `syncrecordings()` línea 359) y que la rama se ejecuta dentro del `if (isset($recording->videoMediaMetadata))` (grabaciones procesadas), igual que la transcripción.

- [ ] **Step 5: Commit**

```bash
git add classes/client.php
git commit -m "feat(googlemeet): fetch notes on sync (new + existing-without-notes)"
```

---

### Task 5: Persistencia en `sync_recordings()`

**Files:**
- Modify: `lib.php:708-712` (insert: añadir notas)
- Modify: `lib.php:674-680` (loop de existentes: añadir update de notas)

**Interfaces:**
- Consumes: `$insertrecording->notestext`/`notesdocid` y `$file->notestext`/`notesdocid` (Task 4).
- Produces: filas de `googlemeet_recordings` con notas pobladas (insert nuevas, update existentes sin notas).

- [ ] **Step 1: Insert de notas en grabaciones nuevas**

En `lib.php`, tras el bloque de transcripción (líneas 708-712), añadir antes de `$newrecordingids[] = $DB->insert_record(...)` (línea 714):

```php
            // Add notes if available.
            if (!empty($insertrecording->notestext)) {
                $recording->notestext = $insertrecording->notestext;
                $recording->notesdocid = $insertrecording->notesdocid ?? null;
            }
```

- [ ] **Step 2: Update de notas en grabaciones existentes sin notas**

En `lib.php`, el comentario de líneas 662-664 dice que las existentes "nunca se tocan". Las notas son la excepción deliberada. Reemplazar el loop de existentes (líneas 674-680) por:

```php
    $updatednotes = 0;
    foreach ($googlemeetrecordings as $googlemeetrecording) {
        // O(1) lookup with isset() instead of O(n) in_array().
        if (!isset($fileidsmap[$googlemeetrecording->recordingid])) {
            // Accumulate every orphaned recording id, not just the last one.
            $deleterecordings[] = $googlemeetrecording->id;
            continue;
        }

        // Backfill notes for an existing recording that just received them (Gemini
        // notes are often published after the recording first synced). This is the
        // only field updated on existing rows; all Drive metadata stays immutable.
        if (empty($googlemeetrecording->notestext)
                && !empty($fileidsmap[$googlemeetrecording->recordingid])) {
            $incoming = $filesbyid[$googlemeetrecording->recordingid] ?? null;
            if ($incoming && !empty($incoming->notestext)) {
                $DB->update_record('googlemeet_recordings', (object)[
                    'id' => $googlemeetrecording->id,
                    'notestext' => $incoming->notestext,
                    'notesdocid' => $incoming->notesdocid ?? null,
                    'timemodified' => time(),
                ]);
                $updatednotes++;
            }
        }
    }
    $stats['updated'] = $updatednotes;
```

- [ ] **Step 3: Construir `$filesbyid` (mapa objeto completo)**

El loop anterior referencia `$filesbyid`. Junto a `$fileidsmap` (líneas 655-660), ampliar para guardar el objeto entero. Reemplazar ese bloque por:

```php
    $fileidsmap = [];
    $filesbyid = [];
    foreach ($files as $file) {
        if (isset($file->recordingId)) {
            $fileidsmap[$file->recordingId] = true;
            $filesbyid[$file->recordingId] = $file;
        }
    }
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l lib.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verificación (Claude)**

Confirmar: (a) `$stats['updated']` ya existía inicializado a 0 (línea 684), ahora se rellena; (b) el mensaje de feedback de `build_sync_message()` no se rompe con `updated > 0` (revisar `client.php`); (c) las grabaciones existentes sin notas reciben `find_notes_for_recording` en Task 4 incluso si NO son `unprocessed` — verificar que llegan a `$files` con `recordingId` seteado.

- [ ] **Step 6: Commit**

```bash
git add lib.php
git commit -m "feat(googlemeet): persist notes on insert + backfill notes for existing recordings"
```

---

### Task 6: UI (pestaña "Notas") + idioma

**Files:**
- Modify: `templates/recording_hub.mustache:66` (nav, tras el tab de transcripción) y `:236` (panel, tras el de transcripción)
- Modify: `locallib.php:707` (contexto: añadir `hasnotes`/`notes`)
- Modify: `lang/en/googlemeet.php:311`, `lang/es/googlemeet.php:174`, `lang/pt_br/googlemeet.php:204`

**Interfaces:**
- Consumes: `$recording->notestext` (cargado por `view.php:112` `get_record`), string `hub_tab_notes`.
- Produces: pestaña "Notas" visible a todos cuando `hasnotes`.

- [ ] **Step 1: String EN (autoritativo)**

En `lang/en/googlemeet.php`, tras la línea 311 (`hub_tab_materials`):
```php
$string['hub_tab_notes'] = 'Notes';
```

- [ ] **Step 2: String ES**

En `lang/es/googlemeet.php`, tras la línea 174:
```php
$string['hub_tab_notes'] = 'Notas';
```

- [ ] **Step 3: String PT_BR**

En `lang/pt_br/googlemeet.php`, tras la línea 204:
```php
$string['hub_tab_notes'] = 'Notas';
```

- [ ] **Step 4: Botón de pestaña en el nav**

En `templates/recording_hub.mustache`, tras el bloque `{{#caneditrecording}}...transcript-tab...{{/caneditrecording}}` (cierra en línea 66), insertar (NOTA: fuera del gate caneditrecording, dentro de `{{#hasnotes}}`):

```html
    {{#hasnotes}}
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="googlemeet-notes-tab" data-bs-toggle="tab" data-bs-target="#googlemeet-notes-panel" type="button" role="tab" aria-controls="googlemeet-notes-panel" aria-selected="false">
        {{# str }} hub_tab_notes, mod_googlemeet {{/ str }}
      </button>
    </li>
    {{/hasnotes}}
```

- [ ] **Step 5: Panel de la pestaña**

En `templates/recording_hub.mustache`, tras el bloque `{{#caneditrecording}}...transcript-panel...{{/caneditrecording}}` (cierra en línea 236), insertar:

```html
    {{#hasnotes}}
    <div class="tab-pane fade" id="googlemeet-notes-panel" role="tabpanel" aria-labelledby="googlemeet-notes-tab">
      <div class="googlemeet-ai-notes-text">{{{notes}}}</div>
    </div>
    {{/hasnotes}}
```

- [ ] **Step 6: Contexto en locallib.php**

En `locallib.php`, dentro del array `$templatecontext` (tras `'transcript' => ...` línea 707), añadir:

```php
        'hasnotes' => !empty($recording->notestext),
        'notes' => !empty($recording->notestext)
            ? format_text($recording->notestext, FORMAT_HTML, ['context' => $context])
            : '',
```

- [ ] **Step 7: Estilo (reutilizar el de transcripción)**

En `styles.css`, tras la regla `.googlemeet-ai-transcript-text {` (línea 1220), añadir el selector de notas a la misma declaración o duplicar la clase. Mínimo:
```css
.googlemeet-ai-notes-text {
  /* Reuse transcript text styling for structured notes. */
  max-height: 480px;
  overflow-y: auto;
}
```
(Ajustar a las propiedades reales de `.googlemeet-ai-transcript-text` tras leerla; objetivo: contenedor legible y desplazable.)

- [ ] **Step 8: Verificar sintaxis PHP**

Run: `php -l locallib.php && php -l lang/en/googlemeet.php && php -l lang/es/googlemeet.php && php -l lang/pt_br/googlemeet.php`
Expected: `No syntax errors detected` en todos

- [ ] **Step 9: Commit**

```bash
git add templates/recording_hub.mustache locallib.php styles.css lang/en/googlemeet.php lang/es/googlemeet.php lang/pt_br/googlemeet.php
git commit -m "feat(googlemeet): student-facing Notes tab rendering sanitized Gemini notes"
```

---

### Task 7: Privacidad + verificación end-to-end

**Files:**
- Modify: `classes/privacy/provider.php` (comentario de transparencia para la nueva columna)

**Interfaces:**
- Consumes: todo lo anterior.

- [ ] **Step 1: Documentar columna en provider**

En `classes/privacy/provider.php`, localizar el comentario que describe `googlemeet_recordings`/`transcripttext` (la tabla ya está cubierta; el contenido externo de Drive ya está declarado). Añadir una línea de transparencia indicando que `notestext`/`notesdocid` almacenan notas derivadas del Google Doc de Drive. (Texto de comentario, sin cambio funcional si la tabla ya se exporta entera.)

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l classes/privacy/provider.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Suite completa**

Run: `vendor/bin/phpunit mod/googlemeet` (con salvedades init.php/locale de CLAUDE.md)
Expected: verde (incl. `notes_sanitize_test`).

- [ ] **Step 4: Verificación end-to-end con datos reales (Claude + usuario)**

1. Deploy a `formacion51/public/mod/googlemeet` (rsync) → `admin/cli/upgrade.php` (aplica Task 1) → `purge_caches` → reset opcache.
2. Re-confirmar `makerecordingspublic`: `set_config('makerecordingspublic', 1, 'googlemeet')`.
3. Abrir la actividad googlemeet del **vídeo de 2026-06-24 (tiene notas)** y lanzar sync (`?sync=1` POST).
4. Confirmar en BD: `SELECT id, name, LENGTH(notestext), notesdocid FROM {googlemeet}_recordings WHERE notestext IS NOT NULL;` → fila con notas.
5. Confirmar en navegador (Playwright si procede): la pestaña **"Notas"** aparece para un alumno y muestra contenido estructurado (encabezados/viñetas), sin `<script>`/estilos rotos.
6. Confirmar que una grabación SIN notas no muestra la pestaña.

- [ ] **Step 5: Commit**

```bash
git add classes/privacy/provider.php
git commit -m "docs(googlemeet): privacy note for notestext/notesdocid columns"
```

---

## Self-Review (cobertura del spec)

- §4 Schema → Task 1 ✓
- §5 Endpoints REST (`get`+`export`, fix transcripción) → Task 2 ✓
- §6 Cliente Drive (`find_notes_for_recording`, export HTML, saneado) → Task 3 ✓
- §6.2 Cableado sync con guard ampliado (`$existingnotes`) → Task 4 ✓
- §7 Persistencia (insert + update existentes) → Task 5 ✓
- §8 UI pestaña Notas (todos, `hasnotes`) + contexto → Task 6 ✓
- §9 i18n (en/es/pt_br), privacidad, versión, tests → Tasks 1/6/7 ✓
- §11 Verificación end-to-end con vídeo 2026-06-24 → Task 7 ✓
- §3.1 HTML + saneado Moodle → Tasks 3/6 ✓
- §3.2 Notas tardías en re-sync → Tasks 4/5 ✓
- §3.3 Detección genérica name-matching → Task 3 ✓
```
