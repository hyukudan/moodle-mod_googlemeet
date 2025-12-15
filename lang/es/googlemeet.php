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
