<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     mod_googlemeet
 * @category    string
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Cadenas base.
$string['at'] = 'a las';
$string['issuerid'] = 'Servicio OAuth';
$string['issuerid_desc'] = '<a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret" target="_blank">Cómo configurar un servicio OAuth</a>';
$string['cachedef_userinfo'] = 'Información de la cuenta de Google vinculada (por sesión)';
$string['calendar_action_enterroom'] = 'Ir al aula';
$string['calendareventname'] = 'Clase en directo: {$a}';
$string['checkweekdays'] = 'Selecciona los días de la semana que estén dentro del rango de fechas seleccionado.';
$string['creatoremail'] = 'Correo del organizador';
$string['creatoremail_error'] = 'Introduce una dirección de correo válida';
$string['creatoremail_changedenied'] = 'Solo un administrador del sitio, o el propietario de esa cuenta de Google con la sesión iniciada en ella, puede cambiar el correo del organizador.';
$string['creatoremail_help'] = 'Correo del organizador de la sesión';
$string['date'] = 'Fecha';
$string['duration'] = 'Duración';
$string['earlierto'] = 'La fecha de la sesión no puede ser anterior a la fecha de inicio del curso ({$a}).';
$string['emailcontent'] = 'Contenido del correo';
$string['emailcontent_default'] = '<p>Hola %userfirstname%,</p>
<p>Este recordatorio es para avisarte de que habrá una sesión de Google Meet en %coursename%</p>
<p><b>%googlemeetname%</b></p>
<p>Cuándo: %eventdate% %duration% %timezone%</p>
<p>Enlace de acceso: %url%</p>';
$string['emailcontent_help'] = 'Cuando se envía una notificación a estudiantes, el contenido del correo se toma de este campo. Se pueden usar los siguientes comodines:
<ul>
<li>%userfirstname%</li>
<li>%userlastname%</li>
<li>%coursename%</li>
<li>%googlemeetname%</li>
<li>%eventdate%</li>
<li>%duration%</li>
<li>%timezone%</li>
<li>%url%</li>
<li>%cmid%</li>
</ul>';
$string['entertheroom'] = 'Entrar en la sala';
$string['eventdate'] = 'Fecha de la sesión';
$string['eventdetails'] = 'Detalles de la sesión';
$string['from'] = 'de';
$string['googlemeet:addinstance'] = 'Añadir una nueva instancia de Google Meet';
$string['googlemeet:editrecording'] = 'Editar grabaciones';
$string['googlemeet:removerecording'] = 'Eliminar grabaciones';
$string['googlemeet:syncgoogledrive'] = 'Sincronizar con Google Drive';
$string['googlemeet:view'] = 'Ver Google Meet';
$string['googleeventcreationfailed'] = 'No se ha podido crear la sala de Google Meet o el evento de Calendar. La actividad se ha guardado; prueba a guardarla de nuevo más tarde o sincronízala desde la página de la actividad.';
$string['hide'] = 'Ocultar';
$string['invalideventenddate'] = 'Esta fecha no puede ser anterior a la "Fecha de la sesión"';
$string['invalideventendtime'] = 'La hora de fin debe ser posterior a la hora de inicio';
$string['invalidissuerid'] = 'El servicio OAuth seleccionado en la configuración de "Google Meet" no es compatible con Google';
$string['invalidstoredurl'] = 'No se puede mostrar este recurso; la URL de Google Meet no es válida.';
$string['invalidstoredurl_editor'] = 'La URL de Google Meet guardada en esta actividad no es válida, así que se oculta el botón para entrar. Los alumnos siguen viendo las grabaciones. <a href="{$a}">Edita los ajustes de la actividad</a> para corregirla.';
$string['recordingnotfound'] = 'Esta grabación ya no está disponible o se ha eliminado.';
$string['isnotcreatoremail'] = 'Iniciar sesión con la cuenta del organizador o cambiar el correo del organizador en la configuración para sincronizar grabaciones.';
$string['jstableinfo'] = 'Mostrando {start} a {end} de {rows} grabaciones';
$string['jstableinfofiltered'] = 'Mostrando {start} a {end} de {rows} grabaciones (filtradas de {rowsTotal} grabaciones)';
$string['jstableloading'] = 'Cargando...';
$string['jstablenorows'] = 'No se ha encontrado ninguna grabación';
$string['jstableperpage'] = '{select} grabaciones por página';
$string['jstablesearch'] = 'Buscar...';
$string['lastsync'] = 'Última sincronización:';
$string['loading'] = 'Cargando';
$string['logintoaccount'] = 'Iniciar sesión con tu cuenta de Google';
$string['logintoyourgoogleaccount'] = 'Iniciar sesión con tu cuenta de Google para que la URL de Google Meet pueda crearse automáticamente';
$string['loggedinaccount'] = 'Cuenta de Google conectada';
$string['logout'] = 'Cerrar sesión';
$string['manage'] = 'Gestionar';
$string['messageprovider:notification'] = 'Recordatorio de inicio de clase';
$string['minutesbefore'] = 'Minutos antes';
$string['minutesbefore_help'] = 'Número de minutos antes del inicio de la sesión en que debe enviarse la notificación.';
$string['modulename'] = 'Google Meet';
$string['modulename_help'] = 'El módulo Google Meet permite al profesor crear una sala de Google Meet como recurso del curso y, tras las sesiones, poner a disposición de los estudiantes las grabaciones guardadas en Google Drive.
<p>©2018 Google LLC All rights reserved.<br/>
Google Meet and the Google Meet logo are registered trademarks of Google LLC.</p>';
$string['modulenameplural'] = 'Instancias de Google Meet';
$string['multieventdateexpanded'] = 'Recurrencia de la fecha de la sesión expandida';
$string['multieventdateexpanded_desc'] = 'Mostrar la configuración de "Recurrencia de la fecha de la sesión" expandida por defecto al crear una sala nueva.';
$string['name'] = 'Nombre';
$string['never'] = 'Nunca';
$string['notification'] = 'Recordatorio de clase';
$string['notificationexpanded'] = 'Notificación expandida';
$string['notify'] = 'Enviar notificación a estudiantes';
$string['notify_help'] = 'Si se marca, se enviará una notificación a estudiantes sobre la fecha de inicio de la sesión.';
$string['notifycationexpanded_desc'] = 'Mostrar la configuración de "Notificación" expandida por defecto al crear una sala nueva.';
$string['notifytask'] = 'Tarea de notificación de Google Meet';
$string['or'] = 'o';
$string['play'] = 'Reproducir';
$string['pluginadministration'] = 'Administración de Google Meet';
$string['pluginname'] = 'Google Meet';
$string['privacy:metadata:googlemeet_notify_done'] = 'Registra las notificaciones enviadas a los usuarios sobre el inicio de las sesiones. Estos datos son temporales y se eliminan tras la fecha de inicio de la sesión.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'El ID del evento';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'El ID del usuario';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'La marca de tiempo que indica cuándo recibió el usuario una notificación';
$string['recording'] = 'Grabación';
$string['recordings'] = 'Grabaciones';
$string['recordingswiththename'] = 'Grabaciones con el nombre:';
$string['recurrenceeventdate'] = 'Recurrencia de la fecha de la sesión';
$string['recurrenceeventdate_help'] = 'Esta función permite crear múltiples recurrencias a partir de la fecha de la sesión.
<br>* <strong>Repetir en</strong>: Selecciona los días de la semana en los que se celebrará la clase (por ejemplo, lunes / miércoles / viernes).
<br>* <strong>Repetir cada</strong>: Permite configurar la frecuencia. Si la clase se celebrará cada semana, selecciona 1; si será cada dos semanas, selecciona 2; cada 3 semanas, selecciona 3, etc.
<br>* <strong>Repetir hasta</strong>: Selecciona el último día de la reunión (el último día que se quiere usar para la fecha recurrente de la sesión).';
$string['repeatasfollows'] = 'Repetir la fecha de la sesión anterior de la siguiente forma';
$string['repeatevery'] = 'Repetir cada';
$string['repeaton'] = 'Repetir en';
$string['repeatuntil'] = 'Repetir hasta';
$string['roomcreator'] = 'Organizador:';
$string['roomname'] = 'Nombre de la sala';
$string['roomurl'] = 'URL de la sala';
$string['roomurl_caution'] = '<strong>¡Atención!</strong> Si se cambia la URL de la sala o el correo del organizador, las grabaciones sincronizadas pueden eliminarse en la siguiente sincronización.';
$string['roomurl_desc'] = 'La URL de la sala se generará automáticamente.';
$string['roomurlexpanded'] = 'URL de la sala expandida';
$string['roomurlexpanded_desc'] = 'Mostrar la configuración de "URL de la sala" expandida por defecto al crear una sala nueva.';
$string['servicenotenabled'] = 'Acceso no configurado. Comprueba que los servicios \'Google Drive API\' y \'Google Calendar API\' estén habilitados.';
$string['sessionexpired'] = 'La sesión de la cuenta de Google ha caducado durante el proceso; iniciar sesión de nuevo.';
$string['show'] = 'Mostrar';
$string['strftimedmy'] = '%a. %d %b. %Y';
$string['strftimedmyhm'] = '%a. %d %b. %Y %H:%M';
$string['strftimedmweekday'] = '%a %d %b';
$string['strftimehm'] = '%H:%M';
$string['syncwithgoogledrive'] = 'Sincronizar con Google Drive';
$string['sync_info'] = 'Esperar al menos 10 minutos para que el archivo de la grabación se genere y se guarde en "Mi unidad > Meet Recordings" del organizador.
<p></p>
Para eliminar una grabación, primero borrar el archivo de la grabación de Google Drive y después hacer clic en el botón de sincronización anterior.
<p></p>
Para grabar una reunión, comprobar que:
<ul>
    <li>No se haya alcanzado la cuota personal de Drive.</li>
    <li>La organización no haya alcanzado su cuota de Drive.</li>
</ul>
Si hay espacio en tu Drive, pero la organización no tiene espacio, no se puede grabar la reunión.
<p></p>
Para más información, consultar este artículo del Centro de Ayuda:
<br>
<a href="https://notifications.google.com/g/p/APNL1TjJltVk6EcLPyFTJ8V_9ty1FeTAD0XSSJVLiaWPezIaQKfIPd1kGURFUMVV3I5yHgVZoOgxkl4gySV-4SCf2pZ27Vk8Iy9DnHSQBqtK51uG3Gyz" target="_blank" rel="nofollow noopener">https://support.google.com/meet/answer/9308681</a>';
$string['sync_notloggedin'] = 'Iniciar sesión con tu cuenta de Google para sincronizar la grabación de Google Meet con Moodle';
$string['timeahead'] = 'No es posible crear múltiples recurrencias de la fecha de la sesión que superen un año; ajustar las fechas de inicio y fin.';
$string['timedate'] = '%d/%m/%Y %H:%M';
$string['to'] = 'a';
$string['today'] = 'Hoy';
$string['upcomingevents'] = 'Próximas sesiones';
$string['url'] = '';
$string['url_failed'] = 'Se requiere una URL válida de Google Meet';
$string['url_help'] = 'Ej. https://meet.google.com/aaa-aaaa-aaa';
$string['visible'] = 'Visible';
$string['week'] = 'Semana(s)';

// New strings for UI redesign (v2.2.0-custom).
$string['event_status_live'] = 'En directo';
$string['event_status_soon'] = 'Comienza pronto';
$string['event_status_scheduled'] = 'Programado';
$string['event_countdown_started'] = 'Comenzando';
$string['event_countdown_starts_in_prefix'] = 'Comienza en';
$string['event_starts_in'] = 'Comienza en {$a}';
$string['event_started_ago'] = 'Comenzó hace {$a}';
$string['event_join_now'] = 'Unirse ahora';
$string['event_time_minutes'] = '{$a} min';
$string['event_time_hours'] = '{$a}h';
$string['event_time_days'] = '{$a}d';
$string['event_no_upcoming'] = 'No hay sesiones programadas';
$string['room_enter_live'] = 'EN DIRECTO · Entrar a la sala';
$string['room_enter_soon'] = 'Entrar a la sala';
$string['room_enter_soon_countdown'] = 'Entrar a la sala';
$string['room_enter_teacher'] = 'Preparar sala · Entrar';
$string['room_next_class'] = 'Próxima clase: {$a}';
$string['room_teacher_note'] = 'Disponible para el profesorado para preparar la sala.';

$string['recordings_count'] = '{$a} grabación(es)';
$string['recording_watch'] = 'Ver grabación';
$string['recording_hidden'] = 'Oculto para estudiantes';

$string['sync_settings'] = 'Configuración de sincronización';
$string['sync_help_title'] = 'Cómo funciona la sincronización';
$string['sync_new_recordings'] = '{$a} grabación(es) nueva(s) añadida(s)';
$string['sync_updated_recordings'] = '{$a} grabación(es) actualizada(s)';
$string['sync_deleted_recordings'] = '{$a} grabación(es) eliminada(s)';
$string['sync_trashed_recordings'] = '{$a} grabación(es) movida(s) a la papelera';
$string['sync_restored_recordings'] = '{$a} grabación(es) restaurada(s) desde la papelera';
$string['sync_already_running'] = 'Sincronización ya en curso, inténtalo en unos minutos.';
$string['sync_enrichment_queued'] = 'Las transcripciones, notas y permisos se están procesando en segundo plano.';
$string['sync_no_changes'] = 'Sincronización completa. {$a} grabación(es) ya estaban actualizadas';
$string['sync_no_recordings_found'] = 'Sincronización completa. No se encontraron grabaciones en Google Drive para esta reunión';

// Holiday/exclusion periods.
$string['holidayperiods'] = 'Períodos de exclusión';
$string['holidayperiods_help'] = 'Define períodos durante los cuales no se programarán eventos (ej. vacaciones de Navidad, Semana Santa). Los eventos que caigan en estos períodos serán omitidos.';
$string['addholidayperiod'] = 'Añadir período de exclusión';
$string['removeholidayperiod'] = 'Eliminar';
$string['holidayname'] = 'Nombre (opcional)';
$string['holidayname_placeholder'] = 'ej. Vacaciones de Navidad';
$string['holidaystartdate'] = 'Fecha de inicio';
$string['holidayenddate'] = 'Fecha de fin';
$string['invalidholidayenddate'] = 'La fecha de fin de un período de exclusión no puede ser anterior a su fecha de inicio';
$string['noholidayperiods'] = 'No hay períodos de exclusión definidos';

// Cancelled dates.
$string['cancelleddates'] = 'Sesiones canceladas';
$string['cancelleddates_help'] = 'Define fechas individuales cuando las sesiones están canceladas (ej. enfermedad, festivo). Estas sesiones aparecerán con un indicador "Cancelada" en lugar de ocultarse.';
$string['addcancelleddate'] = 'Añadir fecha cancelada';
$string['removecancelleddate'] = 'Eliminar';
$string['cancelleddate'] = 'Fecha';
$string['cancelledreason'] = 'Motivo (opcional)';
$string['cancelledreason_placeholder'] = 'ej. Enfermedad del profesor';
$string['event_status_cancelled'] = 'Cancelada';

// Max upcoming events.
$string['maxupcomingevents'] = 'Máximo de próximos eventos';
$string['maxupcomingevents_help'] = 'Selecciona el número máximo de próximos eventos a mostrar en la página de la actividad.';

// Recordings settings.
$string['recordingssettings'] = 'Configuración de visualización de grabaciones';
$string['maxrecordings'] = 'Grabaciones por página';
$string['maxrecordings_help'] = 'Selecciona el número máximo de grabaciones a mostrar por página. Las grabaciones adicionales serán accesibles mediante paginación.';
$string['recordingsorder'] = 'Orden de grabaciones';
$string['recordingsorder_help'] = 'Selecciona si mostrar primero las grabaciones más recientes o las más antiguas.';
$string['recordingsorder_desc'] = 'Más recientes primero';
$string['recordingsorder_desc_short'] = 'Recientes';
$string['recordingsorder_asc'] = 'Más antiguas primero';
$string['recordingsorder_asc_short'] = 'Antiguas';
$string['recordingfilter'] = 'Filtro de nombre de grabación';
$string['recordingfilter_placeholder'] = 'ej. Clase de oposiciones EXAMPLE';
$string['recordingfilter_help'] = 'Introduce un patrón de texto personalizado para filtrar las grabaciones de Google Drive. La sincronización solo incluirá grabaciones cuyo nombre de archivo contenga este texto. Esto es útil cuando el nombre de la grabación en Google Drive (del calendario de Google) difiere del nombre de la actividad en Moodle. Déjalo vacío para usar el filtrado predeterminado (nombre de actividad o código de reunión).';
$string['autosynchours'] = 'Horas tras finalizar la sesión para auto-sincronizar';
$string['autosynchours_help'] = 'Horas que hay que esperar tras el final de una sesión programada antes de sincronizar automáticamente las grabaciones de Google Drive. Poner 0 para desactivar (el profesor siempre puede sincronizar manualmente). La política de reintentos se controla a nivel de sitio mediante "Intentos máximos de sincronización" y "Intervalo entre reintentos".';
$string['autosynchours_default'] = 'Auto-sincronización: horas por defecto';
$string['autosynchours_default_desc'] = 'Valor por defecto para "Horas tras finalizar la sesión para auto-sincronizar" al crear nuevas actividades de Google Meet.';
$string['maxsyncattempts'] = 'Intentos máximos de sincronización';
$string['maxsyncattempts_desc'] = 'Número máximo de intentos de auto-sincronización por evento antes de rendirse. Poner 1 mantiene el comportamiento original de un solo intento. Valores más altos permiten recuperarse de fallos transitorios (token revocado, errores de la API de Drive, grabación aún no procesada).';
$string['syncretryinterval'] = 'Intervalo entre reintentos (segundos)';
$string['syncretryinterval_desc'] = 'Segundos a esperar entre dos intentos fallidos de auto-sincronización del mismo evento. Mínimo 60.';
$string['process_autosync_task'] = 'Auto-sincronizar grabaciones de Google Meet tras finalizar las sesiones';
$string['process_recording_enrichment_task'] = 'Procesar enriquecimiento de grabaciones de Google Meet';
$string['recordings_pagination_info'] = 'Mostrando {$a->start} a {$a->end} de {$a->total} lecciones';
$string['recordings_page_previous'] = 'Anterior';
$string['recordings_page_next'] = 'Siguiente';
$string['recordings_sort_by'] = 'Ordenar por';
$string['recordings_showing'] = 'Mostrando';

// AI features strings.
$string['ai_settings'] = 'Funciones IA (Gemini)';
$string['ai_settings_desc'] = 'Configura las funciones impulsadas por IA usando Google Gemini para generar automáticamente resúmenes, puntos clave y transcripciones de las grabaciones.';
$string['enableai'] = 'Habilitar análisis IA';
$string['enableai_desc'] = 'Habilitar análisis de grabaciones con IA usando Google Gemini.';
$string['geminiapikey'] = 'Clave API de Gemini';
$string['geminiapikey_desc'] = 'Introduce tu clave API de Google Gemini. Puedes obtener una gratis en <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.';
$string['aimodel'] = 'Modelo de IA';
$string['aimodel_desc'] = 'Selecciona el modelo de Gemini que se usará para el análisis. Flash es más rápido y tiene un nivel gratuito generoso; Pro ofrece mayor capacidad, pero tiene límites gratuitos más bajos.';
$string['googlemeet:generateai'] = 'Generar análisis IA';
$string['ai_autogenerate'] = 'Auto-generar análisis';
$string['ai_autogenerate_desc'] = 'Generar automáticamente el análisis IA cuando se sincronicen nuevas grabaciones.';
$string['ai_analysis'] = 'Análisis con IA';
$string['ai_summary'] = 'Resumen';
$string['ai_keypoints'] = 'Puntos clave';
$string['ai_transcript'] = 'Transcripción';
$string['ai_topics'] = 'Temas';
$string['ai_generate'] = 'Generar análisis IA';
$string['ai_regenerate'] = 'Regenerar';
$string['ai_generating'] = 'Generando análisis…';
$string['ai_status_pending'] = 'Pendiente';
$string['ai_status_processing'] = 'Procesando';
$string['ai_status_completed'] = 'Completado';
$string['ai_status_failed'] = 'Fallido';
$string['ai_noanalysis'] = 'No hay análisis IA disponible todavía.';
$string['ai_notconfigured'] = 'El análisis IA no está habilitado en este sitio.';
$string['ai_noanalysis_hint'] = 'Pulsa el botón de arriba para generar un resumen, puntos clave y transcripción con IA.';
$string['ai_error'] = 'Error generando análisis: {$a}';
$string['ai_not_configured'] = 'Las funciones IA no están configuradas. Por favor contacta con el administrador.';
$string['ai_disabled'] = 'Las funciones IA están deshabilitadas.';
$string['ai_generated_on'] = 'Generado';
$string['ai_model'] = 'Modelo';
$string['ai_model_used'] = 'Modelo: {$a}';
$string['ai_copy_transcript'] = 'Copiar';
$string['ai_copied'] = '¡Copiado!';
$string['ai_expand'] = 'Expandir';
$string['ai_collapse'] = 'Contraer';
$string['ai_process_task'] = 'Procesar análisis IA pendientes';
$string['ai_analysis_available'] = 'Análisis IA disponible';
$string['ai_keypoints_short'] = 'puntos clave';
$string['ai_keypoints_count'] = '{$a} punto clave';
$string['ai_keypoints_count_plural'] = '{$a} puntos clave';
$string['ai_transcript_loading'] = 'Cargando transcripción…';
$string['ai_transcript_unavailable'] = 'Transcripción no disponible para esta grabación.';
$string['ai_error_unknown'] = 'Ha ocurrido un error desconocido. Por favor, inténtalo de nuevo.';
$string['ai_timeout_hint'] = 'Tiempo de espera agotado: la solicitud tardó demasiado';
$string['ai_subtitles_unavailable'] = 'No se han encontrado subtítulos automáticos para esta grabación. Pulsa "Transcribir desde vídeo" para descargar el vídeo completo y generar la transcripción a partir del audio.';
$string['ai_transcribe_from_video'] = 'Transcribir desde vídeo';
$string['ai_transcribe_from_video_desc'] = 'Descarga el vídeo completo de Google Drive y deja que Gemini genere la transcripción. Puede tardar varios minutos y usar hasta varios GB de espacio temporal en disco.';
$string['ai_transcribe_from_video_confirm'] = 'Esto descargará el vídeo completo (puede pesar varios GB). ¿Quieres continuar?';
$string['ai_processing_background'] = 'Análisis en progreso';
$string['ai_processing_background_hint'] = 'Se está obteniendo la transcripción de la grabación y analizándola (no se descarga el vídeo). Esto puede tardar unos minutos. La página se actualizará automáticamente cuando termine.';
$string['ai_check_status'] = 'Comprobar estado';
$string['ai_process_video_task'] = 'Procesar análisis IA de vídeo';
$string['ai_edit_manual'] = 'Editar análisis';
$string['ai_edit_manual_title'] = 'Editar análisis';
$string['ai_edit_summary_placeholder'] = 'Introduce un resumen de la clase…';
$string['ai_edit_keypoints_placeholder'] = 'Introduce los puntos clave (uno por línea)…';
$string['ai_edit_topics_placeholder'] = 'Introduce los temas (separados por comas o líneas)…';
$string['ai_edit_transcript_placeholder'] = 'Pega la transcripción aquí…';
$string['ai_edit_save'] = 'Guardar';
$string['ai_edit_cancel'] = 'Cancelar';
$string['ai_edit_saving'] = 'Guardando…';
$string['ai_edit_saved'] = 'Análisis guardado correctamente';
$string['ai_edit_error'] = 'Error guardando análisis: {$a}';
$string['ai_analyze_with_gemini'] = 'Analizar con Gemini';
$string['ai_analyze_transcript_hint'] = 'Pega la transcripción de Google Meet y haz clic en "Analizar con Gemini" para generar automáticamente el resumen, puntos clave y temas.';
$string['ai_analyze_empty_transcript'] = 'Por favor, pega primero una transcripción';
$string['ai_analyzing'] = 'Analizando…';
$string['ai_analyze_success'] = '¡Análisis completado! Revisa y guarda los resultados.';
$string['googlemeet:managequestions'] = 'Gestionar preguntas de práctica con IA de las grabaciones';
$string['hub_back_to_recordings'] = 'Volver a lecciones';
$string['hub_tab_summary'] = 'Resumen';
$string['hub_tab_questions'] = 'Preguntas';
$string['hub_tab_transcript'] = 'Transcripción';
$string['hub_tab_materials'] = 'Materiales';
$string['hub_tab_notes'] = 'Notas';
$string['attachmentsheader'] = 'Adjuntos';
$string['attachments'] = 'Archivos para que los alumnos descarguen';
$string['attachments_help'] = 'Sube archivos aquí (PDF, diapositivas, documentos, etc.). Los alumnos matriculados los verán como una lista de descarga en la página de la actividad. Déjalo en blanco para no mostrar nada.';
$string['material_saved'] = 'Materiales guardados';
$string['materials_invalidrecording'] = 'Elige una grabación para gestionar sus materiales.';
$string['materials_empty_teacher'] = 'No hay materiales; añade el primero.';
$string['materials_manage'] = 'Gestionar materiales';
$string['materials_none'] = 'No hay materiales disponibles para esta grabación.';
$string['materials_upload'] = 'Materiales';
$string['openindrive'] = 'Abrir en Drive';
$string['chapters_mode_reference'] = 'Referencia';
$string['chapters_seek_hint'] = 'Pulsa un capítulo para saltar a ese momento del vídeo.';
$string['chapters_mode_seek'] = 'Saltar al minuto';
$string['chapters_student_hint'] = 'El vídeo no se puede reproducir aquí; usa los tiempos como referencia mientras lo ves en Drive.';
$string['chapters_teacher_hint'] = 'El vídeo no se puede reproducir aquí; pulsa un capítulo para resaltar su marca temporal en la transcripción.';
$string['chapters_show'] = 'Mostrar';
$string['chapters_hide'] = 'Ocultar';
$string['chapters_title'] = 'Capítulos de la lección';
$string['chapter_seek_announce'] = 'Vídeo situado en {$a}';
$string['timestamp_seek_aria'] = 'Ir a {$a} en el vídeo';
$string['chapter_copy_link'] = 'Copiar enlace a este momento';
$string['chapter_copy_link_aria'] = 'Copiar enlace a {$a}';
$string['chapter_link_copied'] = 'Enlace a {$a} copiado';
$string['chapter_link_copy_manual'] = 'Copia este enlace: {$a}';
$string['recording_resume_button'] = 'Continuar en {$a}';
$string['recording_resume_note'] = 'Último punto al que saltaste desde Moodle (no es la posición exacta de reproducción).';
$string['transcript_search_label'] = 'Buscar en la transcripción';
$string['transcript_search_placeholder'] = 'Buscar en la transcripción… (Intro: siguiente coincidencia)';
$string['transcript_search_count'] = '{$a} coincidencias';
$string['transcript_search_none'] = 'Sin coincidencias';
$string['transcript_search_match_aria'] = 'Coincidencia: ir a {$a} en el vídeo';
$string['chapter_jump_aria'] = '{$a->title}, empieza en {$a->timestamp}';
$string['chapter_no_transcript_error'] = 'No hay transcripción para generar capítulos.';
$string['chapter_timestamp_copied'] = 'Tiempo copiado: {$a}';
$string['chapter_timestamp_reference'] = 'Tiempo: {$a}';
$string['chapter_transcript_highlighted'] = 'Transcripción desplazada a {$a}';
$string['classroom_kicker'] = 'Aula virtual';
$string['classroom_lessons'] = 'Lecciones';
$string['continue_watching_cta'] = 'Continuar viendo';
$string['continue_watching_empty'] = 'Tu próxima lección pendiente aparecerá aquí cuando haya grabaciones disponibles.';
$string['continue_watching_empty_title'] = 'Sin lección pendiente';
$string['continue_watching_label'] = 'Continuar viendo';
$string['continue_latest_cta'] = 'Ver la clase';
$string['continue_latest_label'] = 'Última clase';
$string['hero_campus_calendar_link'] = 'Ver en el calendario del campus';
$string['hero_full_schedule_link'] = 'Ver horario completo';
$string['hero_next_classes'] = 'Próximas clases';
$string['hub_next_recording'] = 'Siguiente';
$string['hub_previous_recording'] = 'Anterior';
$string['lesson_has_test'] = 'Test de práctica';
$string['player_help_summary'] = '¿No ves el vídeo?';
$string['player_help_body'] = 'Inicia sesión en Google o permite cookies de terceros para drive.google.com. Si la grabación es muy reciente, puede que aún no esté compartida contigo: vuelve a intentarlo más tarde o ábrela en Drive.';
$string['recording_embed_unavailable_help'] = 'Puede que aún no esté compartida o que no sea un vídeo de Drive. Prueba a abrirla en Drive.';
$string['recording_embed_unavailable'] = 'Esta grabación no se puede reproducir incrustada.';
$string['recording_hide_from_students_button'] = 'Ocultar a alumnos';
$string['recording_mark_viewed'] = 'Marcar como vista';
$string['recording_progress_aria'] = 'Estado de visionado: {$a}';
$string['recording_progress_completed'] = 'Vista';
$string['recording_progress_meter'] = 'Progreso de visionado';
$string['recording_progress_partial'] = 'Parcial';
$string['recording_progress_unseen'] = 'No vista';
$string['recording_progress_saving'] = 'Guardando...';
$string['recording_show_to_students_button'] = 'Mostrar a alumnos';
$string['schedule_next_badge'] = 'Próxima';
$string['schedule_sync_note'] = 'Horario programado por el equipo docente.';
$string['schedule_title'] = 'Horario de clases';
$string['question_advanced_edit'] = 'Editar avanzado en banco de preguntas';
$string['question_bulk_discard'] = 'Descartar seleccionadas';
$string['question_bulk_publish'] = 'Publicar seleccionadas';
$string['question_category_name'] = 'Google Meet: {$a}';
$string['question_correct_answer'] = 'Respuesta correcta';
$string['question_discard'] = 'Descartar';
$string['question_discard_ready_error'] = 'Solo se pueden descartar preguntas en borrador.';
$string['question_edit'] = 'Editar';
$string['question_empty_student'] = 'Todavía no hay preguntas publicadas para esta clase.';
$string['question_empty_teacher'] = 'Genera preguntas con IA para crear borradores que pueda revisar el profesorado.';
$string['question_explanation'] = 'Explicación y referencia';
$string['question_generate_ai'] = 'Generar preguntas con IA';
$string['question_generate_more'] = 'Generar más';
$string['question_generate_task'] = 'Generar preguntas IA de Google Meet';
$string['question_generating'] = 'Generando preguntas...';
$string['question_no_reference'] = 'sin referencia detectada';
$string['question_no_transcript_error'] = 'No hay transcripción para generar preguntas.';
$string['question_publish'] = 'Publicar';
$string['question_reference_label'] = 'Referencia:';
$string['question_status_draft'] = 'Borrador';
$string['question_status_published'] = 'Publicado';
$string['question_stem'] = 'Pregunta';
$string['question_student_phase1'] = 'Hay preguntas publicadas. El reproductor de práctica para estudiantes estará disponible en una actualización posterior.';
$string['question_unpublish'] = 'Despublicar';
$string['question_ai_draft_note'] = 'Generado con IA, pendiente de revisión';
$string['question_ai_reviewed_note'] = 'Generado con IA, revisado por el profesorado';
$string['practice_check'] = 'Comprobar';
$string['practice_correct'] = 'Correcto';
$string['practice_correct_answer'] = 'Respuesta correcta:';
$string['practice_finish'] = 'Finalizar';
$string['practice_finished'] = 'Práctica completada';
$string['practice_incorrect'] = 'Incorrecto';
$string['practice_loading'] = 'Cargando preguntas...';
$string['practice_next'] = 'Siguiente';
$string['practice_retry'] = 'Reintentar';
$string['practice_title'] = 'Preguntas de práctica';
$string['practice_progress'] = 'Pregunta {$a->current} de {$a->total}';
$string['showhide'] = 'Mostrar/ocultar';

// Manejo de errores y privacidad (revisión seguridad/calidad 2026-05-30).
$string['ai_error_generic'] = 'Error al generar el análisis. Inténtalo de nuevo más tarde o contacta con el administrador.';
$string['ai_invalid_analysis'] = 'La IA devolvió un análisis que no se pudo procesar: {$a}';
$string['ai_video_not_public'] = 'No se puede descargar la grabación para el análisis de vídeo completo. Esta opción requiere que la grabación esté compartida públicamente ("Cualquier persona con el enlace"), lo cual depende de la opción "Hacer públicas las grabaciones", o que haya subtítulos autogenerados disponibles. El contenido descargado era una página de error, no un vídeo.';
$string['makerecordingspublic'] = 'Hacer las grabaciones accesibles públicamente';
$string['makerecordingspublic_desc'] = 'Si está activado, las grabaciones sincronizadas reciben acceso de lectura "cualquier persona con el enlace" en Google Drive para que los estudiantes matriculados (que no son los propietarios en Drive) puedan reproducir la grabación incrustada. Desactivarlo mejora la privacidad, pero impide la reproducción a todos excepto a la cuenta de Google propietaria de las grabaciones.';
$string['noeventswithperiod'] = 'Con los días seleccionados y el período de "Repetir cada N semanas", no se generaría ningún evento en el rango de fechas. Reduce el período o amplía la fecha de fin.';
$string['privacy:metadata:core_oauth2'] = 'La actividad Google Meet utiliza el subsistema OAuth 2 para autenticar a los usuarios frente a los servicios de Google.';
$string['privacy:metadata:googlemeet'] = 'Información sobre las instancias de la actividad Google Meet.';
$string['privacy:metadata:googlemeet:creatoremail'] = 'La dirección de correo de la cuenta de Google usada para crear la sala de Meet. Identifica al creador pero se almacena como propiedad de la actividad, no vinculada a una cuenta de usuario de Moodle.';
$string['privacy:metadata:googlemeet_ai_analysis'] = 'Análisis generado por IA de las grabaciones. La transcripción puede contener de forma incidental los nombres o las voces de los participantes de la sesión. Estos datos se asocian a una grabación, no a un usuario concreto de Moodle.';
$string['privacy:metadata:googlemeet_ai_analysis:summary'] = 'Un resumen del contenido de la grabación generado por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:keypoints'] = 'Puntos clave extraídos del contenido de la grabación generados por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:topics'] = 'Temas tratados en el contenido de la grabación generados por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:chapters'] = 'Capítulos con marcas temporales generados por IA a partir de la transcripción de la grabación.';
$string['privacy:metadata:googlemeet_ai_analysis:transcript'] = 'Una transcripción de la grabación, que puede contener los nombres o las voces de los participantes de la sesión.';
$string['privacy:metadata:googlemeet_recordings'] = 'Información sobre las grabaciones sincronizadas desde Google Drive, incluida la transcripción original de Meet. La transcripción puede contener los nombres o el habla de los participantes de la sesión. Estos datos están asociados a una grabación, no a un usuario concreto de Moodle.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'El nombre del archivo de la grabación.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'El enlace de Google Drive utilizado para ver la grabación.';
$string['privacy:metadata:googlemeet_recordings:transcripttext'] = 'La transcripción original de Google Meet de la grabación, que puede contener los nombres o el habla de los participantes de la sesión.';
$string['privacy:metadata:googlemeet_recordings:transcriptfileid'] = 'El identificador del archivo de Google Drive de la transcripción de la grabación.';
$string['privacy:metadata:googlemeet_recordings:notestext'] = 'Las notas de la reunión generadas por Gemini de la grabación, que pueden contener los nombres o las aportaciones de los participantes de la sesión.';
$string['privacy:metadata:googlemeet_recordings:notesdocid'] = 'El identificador del documento de Google Drive de las notas de la grabación.';
$string['privacy:metadata:google_gemini'] = 'Las transcripciones de las grabaciones y, como alternativa, el vídeo completo de la grabación se envían a la API de Google Gemini para generar el análisis. Pueden contener datos personales como los nombres y las voces de los participantes.';
$string['privacy:metadata:google_gemini:transcript'] = 'El texto de la transcripción de la grabación enviado para su análisis.';
$string['privacy:metadata:google_gemini:video'] = 'El archivo de vídeo completo de la grabación, enviado para su análisis cuando no hay transcripción disponible.';
$string['privacy:metadata:google_drive'] = 'Las grabaciones se leen del Google Drive del usuario en su nombre mediante su autorización OAuth 2.';
$string['privacy:metadata:google_drive:userid'] = 'La identidad del usuario autenticado se envía a Google Drive para acceder a sus grabaciones.';
$string['privacy:metadata:google_calendar'] = 'Los eventos de calendario y la sala de Meet se crean y leen en el Google Calendar del usuario en su nombre mediante su autorización OAuth 2.';
$string['privacy:metadata:google_calendar:userid'] = 'La identidad del usuario autenticado se envía a Google Calendar para gestionar sus eventos y la sala de Meet.';
$string['subtitlelanguage'] = 'Idioma de los subtítulos';
$string['subtitlelanguage_desc'] = 'Código de idioma usado al extraer los subtítulos generados automáticamente de las grabaciones de Google Drive (p. ej. es, en, pt-BR). El valor por defecto es es.';
$string['ytdlppath'] = 'Ruta de yt-dlp';
$string['ytdlppath_desc'] = 'Ruta completa al ejecutable yt-dlp usado para extraer subtítulos generados automáticamente de Google Drive. Déjala vacía para detectarlo desde PATH. Usa una ruta en la que solo puedan escribir los administradores (nunca /tmp). Si no se encuentra yt-dlp, se omite la extracción de subtítulos y el análisis con IA sube el vídeo completo.';
$string['googlemeet:subscriberecordings'] = 'Suscribirse a notificaciones de nuevas grabaciones';
$string['messageprovider:recordingavailable'] = 'Grabación de Google Meet disponible';
$string['privacy:metadata:googlemeet_recording_subs'] = 'Almacena los usuarios suscritos a notificaciones cuando hay nuevas grabaciones disponibles en una actividad Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:googlemeetid'] = 'El ID de la actividad Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:userid'] = 'El ID del usuario.';
$string['privacy:metadata:googlemeet_recording_subs:timecreated'] = 'La marca de tiempo que indica cuándo el usuario se suscribió a las notificaciones de grabaciones.';
$string['privacy:metadata:googlemeet_recording_progress'] = 'Almacena el progreso de visionado de cada usuario para las grabaciones de Google Meet.';
$string['privacy:metadata:googlemeet_recording_progress:recordingid'] = 'El ID de la grabación.';
$string['privacy:metadata:googlemeet_recording_progress:userid'] = 'El ID del usuario.';
$string['privacy:metadata:googlemeet_recording_progress:watchedseconds'] = 'Segundos acumulados con la página visible mediante el proxy de heartbeat.';
$string['privacy:metadata:googlemeet_recording_progress:completed'] = 'Indica si la grabación se ha marcado como vista.';
$string['privacy:metadata:googlemeet_recording_progress:timecreated'] = 'La marca de tiempo en que se creó el progreso de visionado.';
$string['privacy:metadata:googlemeet_recording_progress:timemodified'] = 'La marca de tiempo de la última actualización del progreso de visionado.';
$string['recordingprogress'] = 'Progreso de visionado de grabaciones';
$string['recordingavailable_body'] = 'Hay {$a->count} nueva(s) grabación(es) disponible(s) en {$a->name}.' . "\n\n" . 'Abre la actividad: {$a->url}';
$string['recordingavailable_subject'] = '{$a->count} nueva(s) grabación(es) disponible(s): {$a->name}';
$string['recordingavailable_subject_one'] = 'Ya puedes ver la grabación de {$a->name}';
$string['recordingavailable_subject_many'] = '{$a->count} grabaciones nuevas te esperan en {$a->name}';
$string['recordingavailable_body_one'] = 'Hola {$a->user},' . "\n\n" . '¿No pudiste asistir o quieres repasar con calma? Ya tienes disponible la grabación de la clase en «{$a->name}».' . "\n\n" . 'Verla ahora: {$a->url}';
$string['recordingavailable_body_many'] = 'Hola {$a->user},' . "\n\n" . 'Tienes {$a->count} grabaciones nuevas esperándote en «{$a->name}», perfectas para repasar a tu ritmo.' . "\n\n" . 'Verlas ahora: {$a->url}';
$string['recordingnotificationsubscription'] = 'Suscripción a notificaciones de grabaciones';
$string['subscriberecordings'] = 'Recibir avisos';
$string['unsubscriberecordings'] = 'Dejar de recibir avisos';
$string['ai_status_chip_processing'] = 'Análisis en curso';
$string['ai_status_chip_pending'] = 'Análisis en cola';
$string['ai_status_chip_failed_student'] = 'Análisis no disponible';
$string['ai_status_chip_failed_teacher'] = 'Error de análisis';
$string['ai_badge_label'] = 'Análisis IA';
$string['recording_watch_cta'] = 'Abrir clase';
$string['recording_new'] = 'Nueva';
$string['recording_view_summary'] = 'Ver resumen';
$string['recording_play_aria'] = 'Abrir clase grabada';
$string['recordings_search_label'] = 'Buscar clases';
$string['recordings_search_placeholder'] = 'Buscar en las lecciones…';
$string['recordings_search_placeholder_student'] = 'Buscar en las lecciones…';
$string['recordings_search_button'] = 'Buscar';
$string['recordings_filter_clear'] = 'Quitar filtros';
$string['recordings_filter_all_topics'] = 'Todos los temas';
$string['recordings_no_filter_results'] = 'No hay grabaciones que coincidan con los filtros.';
$string['recordings_topics_overflow_aria'] = '{$a} temas más';
$string['search_match_notes'] = 'Coincidencia en las notas';
$string['search_match_transcript'] = 'Coincidencia en la transcripción';
$string['recordings_trash_count'] = 'Papelera ({$a})';
$string['recordings_trash_created_at'] = 'Grabada';
$string['recordings_trash_deleted_at'] = 'Eliminada';
$string['recording_restore'] = 'Restaurar';
$string['recording_purge'] = 'Eliminar definitivamente';
$string['recording_purge_confirm'] = '¿Eliminar definitivamente esta grabación? También se eliminarán su análisis con IA y los materiales adjuntos.';
$string['recording_trash'] = 'Eliminar';
$string['recording_trash_aria'] = 'Mover grabación a la papelera';
$string['recording_trash_confirm'] = 'Se moverá a la papelera; podrás restaurarla más adelante.';
$string['recording_rename'] = 'Renombrar grabación';
$string['recordings_view_label'] = 'Vista';
$string['recordings_view_cards'] = 'Tarjetas';
$string['recordings_view_list'] = 'Lista';
$string['recordings_view_cards_aria'] = 'Ver grabaciones como tarjetas';
$string['recordings_view_list_aria'] = 'Ver grabaciones como lista';
$string['materials_more'] = '+{$a} más';
$string['materials_more_aria'] = 'Ver {$a} materiales más';
$string['question_discard_confirm'] = '¿Descartar esta pregunta en borrador?';
$string['question_bulk_discard_confirm'] = '¿Descartar las preguntas en borrador seleccionadas?';
$string['question_unpublish_confirm'] = '¿Despublicar esta pregunta? El alumnado dejará de verla.';
$string['thereisnorecordingtoshow'] = 'Todavía no hay grabaciones.';
$string['strftimedm'] = '%d %b';
$string['messageprovider:stalerecurrence'] = 'Aviso de recurrencia de clases en directo abandonada';
$string['stalerecurrence_task'] = 'Comprobar recurrencias de Google Meet abandonadas';
$string['stalerecurrence_heading'] = 'Avisos de recurrencia abandonada';
$string['stalerecurrence_heading_desc'] = 'Avisa a los administradores cuando una actividad de Google Meet sigue programando sesiones futuras pero dejó de grabar — señal de que sus clases en directo terminaron y hay que cerrar la recurrencia.';
$string['stalerecurrence_enabled'] = 'Activar avisos de recurrencia abandonada';
$string['stalerecurrence_enabled_desc'] = 'Ejecuta una comprobación semanal y notifica a los administradores sobre recurrencias abandonadas.';
$string['stalerecurrence_weeks'] = 'Semanas sin grabar';
$string['stalerecurrence_weeks_desc'] = 'Cuántas semanas sin grabación nueva (con sesiones futuras aún programadas) antes de marcar una actividad como abandonada.';
$string['stalerecurrence_renotifydays'] = 'Reavisar tras (días)';
$string['stalerecurrence_renotifydays_desc'] = 'Si la actividad sigue abandonada, recuerda a los administradores de nuevo pasados estos días.';
$string['stalerecurrence_subject'] = 'Recurrencia de Google Meet posiblemente abandonada: {$a->activity}';
$string['stalerecurrence_body'] = 'La actividad "{$a->activity}" del curso "{$a->course}" tiene aún {$a->futurecount} sesión(es) futura(s) programada(s), pero su última grabación fue el {$a->lastrecording}. Si sus clases en directo han terminado, cierra la recurrencia poniendo "Repetir hasta" en una fecha pasada aquí: {$a->editurl}';
$string['stalerecurrence_body_html'] = 'La actividad "<strong>{$a->activity}</strong>" del curso "<strong>{$a->course}</strong>" tiene aún <strong>{$a->futurecount}</strong> sesión(es) futura(s) programada(s), pero su última grabación fue el <strong>{$a->lastrecording}</strong>.<br>Si sus clases en directo han terminado, cierra la recurrencia poniendo <em>Repetir hasta</em> en una fecha pasada: <a href="{$a->editurl}">editar la actividad</a>.';
$string['stalerecurrence_editlink'] = 'Editar la actividad';
$string['privacy:metadata:preference:lastjump'] = 'El último capítulo o marca de tiempo al que el usuario saltó en cada grabación (una preferencia por grabación), usado para ofrecer "Continuar en". No es la posición real de reproducción.';
$string['hub_lesson_nav_aria'] = 'Navegación entre clases';
$string['hub_previous_lesson'] = 'Clase anterior';
$string['hub_next_lesson'] = 'Clase siguiente';
$string['hub_duration_label'] = 'Duración';
$string['hub_meta_chapters'] = '{$a} capítulos';
$string['hub_ai_generated_note'] = 'Generado con IA a partir de la grabación. Contrástalo con tu temario.';
$string['hub_read_more'] = 'Leer más';
$string['hub_read_less'] = 'Leer menos';
$string['hub_keypoints_reviewed'] = '{$a->done} de {$a->total} repasados';
$string['hub_keypoints_hint'] = 'Marca cada punto cuando lo domines. Se guarda solo en este navegador.';
$string['hub_keypoint_check_aria'] = 'Marcar el punto clave {$a} como repasado';
$string['hub_topics_hint'] = 'Pulsa un tema para ver todas las clases en las que aparece.';
$string['hub_topic_link_aria'] = 'Ver las clases sobre {$a}';
$string['hub_tabs_aria'] = 'Contenido de la clase';
$string['hub_tab_drafts'] = '{$a} en borrador';
$string['practice_live_score'] = 'Aciertos: {$a}';
$string['practice_score'] = '{$a->correct} de {$a->total} correctas';
$string['practice_result_great'] = 'Excelente: dominas esta clase. Vuelve a repasarla en unos días para fijarla.';
$string['practice_result_good'] = 'Buen trabajo. Repasa las que has fallado y vuelve a intentarlo.';
$string['practice_result_low'] = 'Vuelve al resumen y a los puntos clave de esta clase y repite las que has fallado.';
$string['practice_review_wrong'] = 'Repasar falladas ({$a})';
$string['practice_retry_all'] = 'Repetir todas';
$string['practice_back_to_summary'] = 'Volver al resumen';
$string['practice_your_answer'] = 'Tu respuesta';
$string['practice_right_option'] = 'Respuesta correcta';
$string['practice_round_review'] = 'Repasando falladas';
$string['progress_summary'] = '{$a->watched} de {$a->total} clases vistas';
$string['recordings_filter_all'] = 'Todas';
$string['recordings_filter_pending'] = 'Pendientes ({$a})';
$string['recordings_pending_filter_aria'] = 'Filtrar por estado de visionado';
$string['recordings_pending_none'] = 'Has visto todas las clases. ¡Buen trabajo!';
$string['recordings_pagination_aria'] = 'Paginación de lecciones';
$string['hero_progress_title'] = 'Tu progreso';
$string['hero_recorded_count'] = '{$a} clases grabadas';
$string['hub_study_aside_aria'] = 'Estudia esta clase';
$string['hub_cta_practice_title'] = 'Ponte a prueba';
$string['hub_cta_practice_text'] = '{$a} preguntas tipo examen sobre esta clase.';
$string['hub_cta_practice_button'] = 'Empezar a practicar';
$string['hub_cta_materials_button'] = 'Ver materiales de la clase';
$string['hub_print_summary'] = 'Imprimir resumen';
