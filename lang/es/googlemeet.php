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

// New strings for UI redesign (v2.2.0-custom).
$string['event_status_live'] = 'En directo';
$string['event_status_soon'] = 'Comienza pronto';
$string['event_status_scheduled'] = 'Programado';
$string['event_starts_in'] = 'Comienza en {$a}';
$string['event_started_ago'] = 'Comenzó hace {$a}';
$string['event_join_now'] = 'Unirse ahora';
$string['event_time_minutes'] = '{$a} min';
$string['event_time_hours'] = '{$a}h';
$string['event_time_days'] = '{$a}d';
$string['event_no_upcoming'] = 'No hay sesiones programadas';

$string['recordings_count'] = '{$a} grabación(es)';
$string['recording_watch'] = 'Ver grabación';
$string['recording_hidden'] = 'Oculto para estudiantes';

$string['sync_settings'] = 'Configuración de sincronización';
$string['sync_help_title'] = 'Cómo funciona la sincronización';
$string['sync_new_recordings'] = '{$a} grabación(es) nueva(s) añadida(s)';
$string['sync_updated_recordings'] = '{$a} grabación(es) actualizada(s)';
$string['sync_deleted_recordings'] = '{$a} grabación(es) eliminada(s)';
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
$string['recordingsorder_asc'] = 'Más antiguas primero';
$string['recordingfilter'] = 'Filtro de nombre de grabación';
$string['recordingfilter_placeholder'] = 'ej. Clase de oposiciones CSIF';
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
$string['recordings_pagination_info'] = 'Mostrando {$a->start} a {$a->end} de {$a->total} grabaciones';
$string['recordings_page_previous'] = 'Anterior';
$string['recordings_page_next'] = 'Siguiente';
$string['recordings_sort_by'] = 'Ordenar por';
$string['recordings_showing'] = 'Mostrando';

// AI features strings.
$string['ai_settings'] = 'Funciones IA (Gemini)';
$string['ai_settings_desc'] = 'Configura las funciones impulsadas por IA usando Google Gemini para generar automáticamente resúmenes, puntos clave y transcripciones de las grabaciones.';
$string['enableai'] = 'Habilitar análisis IA';
$string['enableai_desc'] = 'Habilitar análisis de grabaciones con IA usando Google Gemini.';
$string['googlemeet:generateai'] = 'Generar análisis IA';
$string['ai_autogenerate'] = 'Auto-generar análisis';
$string['ai_autogenerate_desc'] = 'Generar automáticamente el análisis IA cuando se sincronicen nuevas grabaciones.';
$string['ai_analysis'] = 'Análisis IA';
$string['ai_summary'] = 'Resumen';
$string['ai_keypoints'] = 'Puntos clave';
$string['ai_transcript'] = 'Transcripción';
$string['ai_topics'] = 'Temas';
$string['ai_generate'] = 'Generar análisis IA';
$string['ai_regenerate'] = 'Regenerar';
$string['ai_generating'] = 'Generando análisis...';
$string['ai_status_pending'] = 'Pendiente';
$string['ai_status_processing'] = 'Procesando';
$string['ai_status_completed'] = 'Completado';
$string['ai_status_failed'] = 'Fallido';
$string['ai_noanalysis'] = 'No hay análisis IA disponible todavía.';
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
$string['ai_transcript_loading'] = 'Cargando transcripción...';
$string['ai_error_unknown'] = 'Ha ocurrido un error desconocido. Por favor, inténtalo de nuevo.';
$string['ai_subtitles_unavailable'] = 'No se han encontrado subtítulos automáticos para esta grabación. Pulsa "Transcribir desde vídeo" para descargar el vídeo completo y generar la transcripción a partir del audio.';
$string['ai_transcribe_from_video'] = 'Transcribir desde vídeo';
$string['ai_transcribe_from_video_desc'] = 'Descarga el vídeo completo de Google Drive y deja que Gemini genere la transcripción. Puede tardar varios minutos y usar hasta varios GB de espacio temporal en disco.';
$string['ai_transcribe_from_video_confirm'] = 'Esto descargará el vídeo completo (puede pesar varios GB). ¿Quieres continuar?';
$string['ai_processing_background'] = 'Análisis en progreso';
$string['ai_processing_background_hint'] = 'El vídeo se está descargando y analizando. Esto puede tardar varios minutos para vídeos largos. La página se actualizará automáticamente cuando termine.';
$string['ai_check_status'] = 'Comprobar estado';
$string['ai_process_video_task'] = 'Procesar análisis IA de vídeo';
$string['ai_edit_manual'] = 'Editar manualmente';
$string['ai_edit_manual_title'] = 'Editar análisis';
$string['ai_edit_summary_placeholder'] = 'Introduce un resumen de la reunión...';
$string['ai_edit_keypoints_placeholder'] = 'Introduce los puntos clave (uno por línea)...';
$string['ai_edit_topics_placeholder'] = 'Introduce los temas (separados por comas o líneas)...';
$string['ai_edit_transcript_placeholder'] = 'Pega la transcripción aquí...';
$string['ai_edit_save'] = 'Guardar';
$string['ai_edit_cancel'] = 'Cancelar';
$string['ai_edit_saving'] = 'Guardando...';
$string['ai_edit_saved'] = 'Análisis guardado correctamente';
$string['ai_edit_error'] = 'Error guardando análisis: {$a}';
$string['ai_analyze_with_gemini'] = 'Analizar con Gemini';
$string['ai_analyze_transcript_hint'] = 'Pega la transcripción de Google Meet y haz clic en "Analizar con Gemini" para generar automáticamente el resumen, puntos clave y temas.';
$string['ai_analyze_empty_transcript'] = 'Por favor, pega primero una transcripción';
$string['ai_analyzing'] = 'Analizando...';
$string['ai_analyze_success'] = '¡Análisis completado! Revisa y guarda los resultados.';
$string['googlemeet:managequestions'] = 'Gestionar preguntas de práctica con IA de las grabaciones';
$string['hub_back_to_recordings'] = 'Volver a grabaciones';
$string['hub_tab_summary'] = 'Resumen IA';
$string['hub_tab_questions'] = 'Preguntas';
$string['hub_tab_transcript'] = 'Transcripción';
$string['openindrive'] = 'Abrir en Drive';
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
$string['privacy:metadata:googlemeet_ai_analysis:transcript'] = 'Una transcripción de la grabación, que puede contener los nombres o las voces de los participantes de la sesión.';
$string['privacy:metadata:googlemeet_recordings'] = 'Información sobre las grabaciones sincronizadas desde Google Drive, incluida la transcripción original de Meet. La transcripción puede contener los nombres o el habla de los participantes de la sesión. Estos datos están asociados a una grabación, no a un usuario concreto de Moodle.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'El nombre del archivo de la grabación.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'El enlace de Google Drive utilizado para ver la grabación.';
$string['privacy:metadata:googlemeet_recordings:transcripttext'] = 'La transcripción original de Google Meet de la grabación, que puede contener los nombres o el habla de los participantes de la sesión.';
$string['privacy:metadata:googlemeet_recordings:transcriptfileid'] = 'El identificador del archivo de Google Drive de la transcripción de la grabación.';
$string['privacy:metadata:google_gemini'] = 'Las transcripciones de las grabaciones y, como alternativa, el vídeo completo de la grabación se envían a la API de Google Gemini para generar el análisis. Pueden contener datos personales como los nombres y las voces de los participantes.';
$string['privacy:metadata:google_gemini:transcript'] = 'El texto de la transcripción de la grabación enviado para su análisis.';
$string['privacy:metadata:google_gemini:video'] = 'El archivo de vídeo completo de la grabación, enviado para su análisis cuando no hay transcripción disponible.';
$string['privacy:metadata:google_drive'] = 'Las grabaciones se leen del Google Drive del usuario en su nombre mediante su autorización OAuth 2.';
$string['privacy:metadata:google_drive:userid'] = 'La identidad del usuario autenticado se envía a Google Drive para acceder a sus grabaciones.';
$string['privacy:metadata:google_calendar'] = 'Los eventos de calendario y la sala de Meet se crean y leen en el Google Calendar del usuario en su nombre mediante su autorización OAuth 2.';
$string['privacy:metadata:google_calendar:userid'] = 'La identidad del usuario autenticado se envía a Google Calendar para gestionar sus eventos y la sala de Meet.';
$string['subtitlelanguage'] = 'Idioma de los subtítulos';
$string['subtitlelanguage_desc'] = 'Código de idioma usado al extraer los subtítulos generados automáticamente de las grabaciones de Google Drive (p. ej. es, en, pt-BR). El valor por defecto es es.';
$string['googlemeet:subscriberecordings'] = 'Suscribirse a notificaciones de nuevas grabaciones';
$string['messageprovider:recordingavailable'] = 'Grabación de Google Meet disponible';
$string['privacy:metadata:googlemeet_recording_subs'] = 'Almacena los usuarios suscritos a notificaciones cuando hay nuevas grabaciones disponibles en una actividad Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:googlemeetid'] = 'El ID de la actividad Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:userid'] = 'El ID del usuario.';
$string['privacy:metadata:googlemeet_recording_subs:timecreated'] = 'La marca de tiempo que indica cuándo el usuario se suscribió a las notificaciones de grabaciones.';
$string['recordingavailable_body'] = 'Hay {$a->count} nueva(s) grabación(es) disponible(s) en {$a->name}.' . "\n\n" . 'Abre la actividad: {$a->url}';
$string['recordingavailable_subject'] = '{$a->count} nueva(s) grabación(es) disponible(s): {$a->name}';
$string['recordingnotificationsubscription'] = 'Suscripción a notificaciones de grabaciones';
$string['subscriberecordings'] = 'Avisarme cuando haya nuevas grabaciones disponibles';
$string['unsubscriberecordings'] = 'Dejar de avisarme';
