# Diseño: Sincronización de Notas de Google Meet (Notes by Gemini)

- **Fecha:** 2026-06-25
- **Plugin:** `mod_googlemeet` (versión actual en dev: `2.18.0` / `2026061500`, sin commitear)
- **Estado del árbol:** sucio (feature "materiales por sesión v2" a medio hacer). Esta feature se construye encima sin entrelazarse con ese WIP.

## 1. Objetivo

Google Meet, en reuniones recientes con "Tomar notas con Gemini" activado, genera un documento de **notas** (resumen estructurado: temas, decisiones, elementos de acción) y lo guarda en Google Drive como **Google Doc**. Es **opcional**: no todas las reuniones lo tienen.

Hoy el plugin sincroniza la **transcripción** pero ignora las notas. Las notas son contenido más limpio y estructurado que la transcripción cruda, y aportan valor directo al alumno.

**Meta:** al sincronizar, detectar si hay notas para una grabación, descargarlas, almacenarlas y mostrarlas al alumno en una pestaña propia.

## 2. Alcance (acordado)

- **SÍ:** detectar + descargar + almacenar + mostrar las notas en una pestaña **"Notas"** visible para **todos** (alumnos incluidos), solo cuando existan.
- **NO:** las notas NO alimentan el pipeline de análisis IA (`process_video_analysis`). Ese pipeline queda intacto.
- **NO:** no se toca la lógica de transcripción salvo el arreglo del endpoint `get` (ver §5), necesario y de paso.

### Diferencia deliberada de visibilidad

| Contenido | Visibilidad | Razón |
|---|---|---|
| Transcripción cruda | Solo profe (`caneditrecording`) | Ruidosa: muletillas, nombres de hablantes, sin estructura |
| **Notas (nuevo)** | **Todos (alumno incluido)** | Ya vienen limpias y estructuradas: aptas para publicación |

## 3. Decisiones de diseño aprobadas

### 3.1 Formato de descarga: HTML
Los Google Docs se exportan vía `files/{id}/export?mimeType=...`. Se exporta **`text/html`** (no `text/plain`) para conservar la estructura (encabezados, viñetas, elementos de acción). Antes de mostrarlo se sanea con `format_text(..., FORMAT_HTML, [...])` + `clean_text()` de Moodle, que neutraliza cualquier XSS del documento. Se almacena el HTML saneado.

### 3.2 Notas tardías: capturarlas también en re-sync
Las notas de Gemini **suelen tardar** y a menudo no existen cuando la grabación aparece por primera vez. Por tanto, a diferencia de la transcripción (que solo se busca para grabaciones nuevas), las notas se buscan para:
- grabaciones **nuevas**, Y
- grabaciones **existentes cuyo `notestext` esté vacío**.

Así el autosync (cada 15 min) o un re-sync manual posterior las recoge cuando Gemini las publica. Coste acotado: una llamada extra a Drive por grabación-sin-notas en cada sync.

### 3.3 Detección genérica robusta (name-matching)
Coherente con el modelo del plugin (empareja por nombre, no por event ID). Se busca en la carpeta "Meet Recordings"/"Registros de reuniones" (con fallback a todo el Drive, igual que la transcripción) un fichero con:
- `mimeType = "application/vnd.google-apps.document"`, y
- `name contains "<nombre base del vídeo>"`.

No se exige marcador de idioma ("Notes by Gemini"/"Notas") para no romperse si Google cambia el sufijo o el idioma. Riesgo asumido: si hubiese otro Doc homónimo en la carpeta podría confundirse (muy improbable en la práctica).

## 4. Esquema de datos

Tabla `googlemeet_recordings`, dos columnas nuevas (espejo de las de transcripción):

```xml
<FIELD NAME="notestext" TYPE="text" NOTNULL="false" SEQUENCE="false"
       COMMENT="Sanitized HTML of the Gemini meeting notes (Google Doc)."/>
<FIELD NAME="notesdocid" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false"
       COMMENT="Google Drive file ID of the notes Google Doc."/>
```

- `db/install.xml`: añadir ambos campos para instalaciones nuevas.
- `db/upgrade.php`: paso de upgrade nuevo (keyed a la versión inmediatamente anterior; ver §9) que añade ambas columnas con `add_field` si no existen.

## 5. Endpoints REST (`classes/rest.php`)

Hoy `client::download_file_content()` llama a `helper::request($service, 'get', ...)` pero **`get` no está declarado** en `get_api_functions()`, y el `call()` del core lanza `coding_exception` para funciones no declaradas. Resultado: la descarga del fichero de transcripción **siempre falla** (silenciada por el `try/catch`). Se añaden dos endpoints:

```php
// Descarga binaria/texto de un fichero Drive normal (arregla el bug de transcripción).
'get' => [
    'endpoint' => 'https://www.googleapis.com/drive/v3/files/{fileid}',
    'method' => 'get',
    'args' => ['fileid' => PARAM_RAW, 'alt' => PARAM_RAW],
    'response' => 'raw',
],
// Exportación de un Google Doc a un mimeType concreto (text/html para notas).
'export' => [
    'endpoint' => 'https://www.googleapis.com/drive/v3/files/{fileid}/export',
    'method' => 'get',
    'args' => ['fileid' => PARAM_RAW, 'mimeType' => PARAM_RAW],
    'response' => 'raw',
],
```

> Nota: el `call()` del core soporta `response => 'raw'` (devuelve el cuerpo sin `json_decode`). Verificar en `lib/classes/oauth2/rest.php` que `'raw'` (o el tipo no-`json`/no-`headers`) devuelve el cuerpo crudo; si solo distingue `json`/`headers`, cualquier otro valor cae en `return $response;` (cuerpo crudo) — que es lo deseado.

**Efecto secundario positivo:** al declarar `get`, la descarga de transcripción desde fichero Drive empieza a funcionar. Es una mejora, no una regresión (antes simplemente caía al fallback yt-dlp). Se documenta en el commit.

## 6. Cliente Drive (`classes/client.php`)

### 6.1 Nuevo método `find_notes_for_recording($service, $parents, $videoname)`
Espejo de `find_transcript_for_recording()`:
1. `basename = pathinfo($videoname, PATHINFO_FILENAME)`.
2. Construir query: `parentclause + 'trashed = false and "me" in owners and mimeType = "application/vnd.google-apps.document" and name contains "<basename>"'`. Fallback a todo el Drive si `$parents` vacío (mismo patrón que transcripción).
3. Si hay resultado, exportar con `export_doc_html($service, $fileid)` → `files/{id}/export?mimeType=text/html`.
4. Sanear el HTML (ver §6.3).
5. Devolver `['docid' => $file->id, 'content' => $htmlsaneado]` o `null`.
6. Todo dentro de `try/catch` con `debugging(...)` (igual que transcripción): si falla, se sigue sin notas.

### 6.2 Cableado en `syncrecordings()`
Tras el bloque de transcripción (client.php ~481-499), añadir la rama de notas con el guard ampliado:

```php
$needsnotes = !isset($existingids[$recording->id]) || empty($existingnotes[$recording->id]);
if ($needsnotes) {
    $notesdata = $this->find_notes_for_recording($service, $parents, $recording->name);
    if ($notesdata) {
        $recordings[$i]->notestext  = $notesdata['content'];
        $recordings[$i]->notesdocid = $notesdata['docid'];
    }
}
```

- `$existingnotes`: precargar junto a `$existingids` (cerca de client.php:444) un mapa `recordingid => notestext` para saber qué grabaciones existentes aún no tienen notas, sin una query por grabación.
- Importante: el `unset($recordings[$i]->id)` y demás limpiezas actuales solo afectan a grabaciones nuevas; para grabaciones existentes que solo reciben notas hay que asegurar que `sync_recordings()` las actualice (ver §7).

### 6.3 Saneado de HTML
En el cliente se hace una limpieza mínima/segura del HTML exportado (p. ej. quedarse con el `<body>`, retirar estilos inline pesados de Google Docs). El saneado **definitivo** anti-XSS se delega a Moodle al renderizar (`format_text` + `clean_text` en locallib). No se confía en el HTML de Google como seguro.

## 7. Persistencia (`lib.php` → `sync_recordings()`)

- **Insert (grabación nueva):** persistir `notestext`/`notesdocid` si vienen (espejo de líneas 708-712 para transcripción).
- **Update (grabación existente sin notas):** hoy `sync_recordings()` ignora las grabaciones ya existentes. Añadir una rama: si una grabación existente llega con `notestext` no vacío y en BD estaba vacío, hacer `$DB->set_field`/`update_record` para rellenar `notestext`/`notesdocid`. No tocar el resto de campos de esas filas.

## 8. UI (`templates/recording_hub.mustache` + `locallib.php`)

### 8.1 Template
- Nueva pestaña **"Notas"** en la barra de tabs, **sin** envolver en `{{#caneditrecording}}` (visible a todos), renderizada **solo si `{{#hasnotes}}`**.
- Panel `#googlemeet-notes-panel` que vuelca `{{{notes}}}` (HTML ya saneado) dentro de un contenedor con estilo (`.googlemeet-ai-notes-text` o similar, reutilizando estilos existentes de transcripción).
- Si `^hasnotes`, la pestaña no aparece (no mostrar "cargando..."; las notas son opcionales).

### 8.2 `googlemeet_print_recording_hub()` (locallib.php ~700)
- Cargar `notestext`/`notesdocid` de la grabación.
- Pasar al contexto: `'hasnotes' => !empty($notestext)`, `'notes' => format_text($notestext, FORMAT_HTML, ['context' => $context])`.

## 9. i18n, privacidad, versión, tests

- **Lang:** `lang/en/googlemeet.php` (autoritativo) + `lang/es/` + `lang/pt_br/`. Strings nuevos: etiqueta de la pestaña ("Notes"/"Notas"), y cualquier aria-label. **Obligatorio en los tres idiomas** (gotcha conocido del plugin: strings añadidos solo en es rompen el array_intersect/render).
- **Privacy** (`classes/privacy/provider.php`): documentar la nueva columna `notestext`/`notesdocid` en `googlemeet_recordings`. Google Drive ya está declarado como ubicación externa; añadir comentario de transparencia para el contenido de notas.
- **Versión** (`version.php`): bump a `2026062500` (o el siguiente > `2026061500`), release `2.19.0`. El paso de `db/upgrade.php` se keyea a `2026061500` (versión previa en dev) → `upgrade_mod_savepoint(true, 2026062500, 'googlemeet')`.
- **Tests** (`tests/`): test unitario del saneado de HTML de notas (entrada Google-Docs-HTML → salida segura) y, si es factible sin Drive real, de la selección de fichero en `find_notes_for_recording` (mockear el `rest`).

## 10. Flujo completo

```
syncrecordings()
  ├─ precargar $existingids + $existingnotes (recordingid → notestext)
  └─ por cada grabación procesada:
       ├─ si nueva → find_transcript_for_recording()      [+ arreglo endpoint get]
       └─ si (nueva O notestext vacío) → find_notes_for_recording()
            ├─ Drive list (mimeType=document, name contains basename)
            ├─ export?mimeType=text/html → saneado mínimo
            └─ → notestext / notesdocid
  └─ sync_recordings():
       ├─ insert grabaciones nuevas (con notas si las hay)
       └─ update grabaciones existentes que ahora traen notas (rellenar notestext/notesdocid)

view → googlemeet_print_recording_hub():
  └─ hasnotes? → pestaña "Notas" (todos) con format_text(FORMAT_HTML)
```

## 11. Verificación

- **Datos reales disponibles:** existe un vídeo de **2026-06-24** con notas en el Drive de la cuenta. Verificación end-to-end posible: sincronizar esa actividad y comprobar que la pestaña "Notas" aparece con contenido estructurado y correcto.
- PHPUnit: `vendor/bin/phpunit mod/googlemeet` (con las salvedades de `init.php`/locale documentadas en CLAUDE.md).
- Despliegue: rsync a `formacion51/public/mod/googlemeet` → `admin/cli/upgrade.php` → `purge_caches` → reset opcache (según runbook de CLAUDE.md). Tras deploy, re-confirmar `makerecordingspublic`.

## 12. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `response => 'raw'` no soportado tal cual por el `call()` del core | Verificar en `lib/classes/oauth2/rest.php`; usar el valor que devuelva el cuerpo crudo |
| HTML de Google Docs con estilos/scripts | `clean_text` + `format_text` de Moodle al renderizar; saneado mínimo previo en cliente |
| Falso positivo de name-matching (otro Doc homónimo) | Riesgo bajo asumido; preferir Doc dentro de la carpeta Meet Recordings antes que fallback global |
| Llamada extra a Drive por grabación-sin-notas en cada sync | Acotada por `$existingnotes`; solo afecta a grabaciones sin notas todavía |
| Entrelazado con WIP "materiales por sesión" en árbol sucio | Commits separados y atómicos; no tocar ficheros del WIP salvo necesidad |
| Reparto codex/Claude | codex genera, Claude verifica contra BD/comportamiento real (workflow habitual del proyecto) |
```
