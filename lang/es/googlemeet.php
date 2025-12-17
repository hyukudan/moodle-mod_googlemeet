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
