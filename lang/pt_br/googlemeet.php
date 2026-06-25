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

$string['attachmentsheader'] = 'Anexos';
$string['attachments'] = 'Arquivos para os alunos baixarem';
$string['attachments_help'] = 'Envie arquivos aqui (PDFs, slides, documentos, etc.). Os alunos inscritos os verão como uma lista de download na página da atividade. Deixe vazio para não exibir nada.';
$string['at'] = 'às';
$string['issuerid'] = 'Serviço OAuth';
$string['issuerid_desc'] = '<a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/Como-criar-o-ID-do-cliente-e-a-Chave-secreta-do-cliente" target="_blank">Como configurar um Serviço OAuth</a>';
$string['calendareventname'] = '{$a} está agendado para';
$string['checkweekdays'] = 'Selecione os dias da semana que se enquadram no intervalo de datas selecionado.';
$string['creatoremail'] = 'E-mail do organizador';
$string['creatoremail_error'] = 'Digite um e-mail válido';
$string['creatoremail_help'] = 'E-mail do organizador do evento';
$string['date'] = 'Data';
$string['duration'] = 'Duração';
$string['earlierto'] = 'A data do evento não pode ser anterior à data de início do curso ({$a}).';
$string['emailcontent'] = 'Conteúdo do e-mail';
$string['emailcontent_default'] = '<p>Olá %userfirstname%,</p>
<p>Este lembrete é para lembrar você de que haverá um evento do Google Meet em %coursename%</p>
<p><b>%googlemeetname%</b></p>
<p>Quando: %eventdate% %duration% %timezone%</p>
<p>Link de acesso: %url%</p>';
$string['emailcontent_help'] = 'Quando uma notificação é enviada a um aluno, ele obtém o conteúdo do email desse campo. Os seguintes curingas podem ser usados:
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
$string['entertheroom'] = 'Entrar na sala';
$string['eventdate'] = 'Data do evento';
$string['eventdetails'] = 'Detalhes do evento';
$string['from'] = 'das';
$string['googlemeet:addinstance'] = 'Adicionar novo Google Meet™ para Moodle';
$string['googlemeet:editrecording'] = 'Editar as gravações';
$string['googlemeet:removerecording'] = 'Remover as gravações';
$string['googlemeet:syncgoogledrive'] = 'Sincronizar com o Google Drive';
$string['googlemeet:view'] = 'Ver Google Meet™ para Moodle';
$string['hide'] = 'Ocultar';
$string['invalideventenddate'] = 'Esta data não pode ser anterior à "Data do evento"';
$string['invalideventendtime'] = 'O horário de término deve ser maior que o horário de início';
$string['invalidissuerid'] = 'O serviço OAuth selecionado nas configurações do "Google Meet™ para Moodle" não é suportado pelo Google';
$string['invalidstoredurl'] = 'Não é possível exibir este recurso, a URL do Google Meet é inválida.';
$string['isnotcreatoremail'] = 'Entre com a conta do organizador ou altere o e-mail do organizador nas configurações para sincronizar as gravações.';
$string['jstableinfo'] = 'Mostrando {start} a {end} de {rows} gravações';
$string['jstableinfofiltered'] = 'Mostrando {start} a {end} de {rows} gravações (filtrado de {rowsTotal} gravações)';
$string['jstableloading'] = 'Carregando...';
$string['jstablenorows'] = 'Nenhuma gravação encontrada';
$string['jstableperpage'] = '{select} gravações por página';
$string['jstablesearch'] = 'Procurar...';
$string['lastsync'] = 'Última sincronização:';
$string['loading'] = 'Carregando';
$string['logintoaccount'] = 'Faça login na sua conta do Google';
$string['logintoyourgoogleaccount'] = 'Faça login na sua conta do Google para que a URL do Google Meet seja criada automaticamente';
$string['loggedinaccount'] = 'Conta do Google conectada';
$string['logout'] = 'Sair';
$string['manage'] = 'Gerenciar';
$string['messageprovider:notification'] = 'Lembrete de início do evento do Google Meet';
$string['minutesbefore'] = 'Minutos antes';
$string['minutesbefore_help'] = 'Número de minutos antes do início do evento quando a notificação deve ser enviada.';
$string['modulename'] = 'Google Meet™ para Moodle';
$string['modulename_help'] = 'O módulo Google Meet™ para Moodle permite ao professor criar uma sala do Google Meet como recurso do curso e, após as reuniões, disponibilizar as gravações aos alunos, salvas no Google Drive.
<p>©2018 Google LLC All rights reserved.<br/>
Google Meet and the Google Meet logo are registered trademarks of Google LLC.</p>';
$string['modulenameplural'] = 'Instâncias do Google Meet™ para Moodle';
$string['multieventdateexpanded'] = 'Recorrência da data do evento expandido';
$string['multieventdateexpanded_desc'] = 'Mostrar as configurações de "Recorrência da data do evento" expandidas por padrão ao criar uma nova Sala.';
$string['name'] = 'Nome';
$string['never'] = 'Nunca';
$string['notification'] = 'Notificação';
$string['notificationexpanded'] = 'Notificação expandida';
$string['notify'] = 'Enviar notificação para o estudante';
$string['notify_help'] = 'Se marcada, uma notificação será enviada ao aluno sobre a data de início do evento.';
$string['notifycationexpanded_desc'] = 'Mostrar as configurações de "Notificação" expandidas por padrão ao criar uma nova sala.';
$string['notifytask'] = 'Tarefa de notificação do Google Meet™ para Moodle';
$string['autosynchours'] = 'Horas após o fim da sessão para auto-sincronizar';
$string['autosynchours_help'] = 'Horas a aguardar após o término de uma sessão programada antes de sincronizar automaticamente as gravações do Google Drive. Use 0 para desativar (o professor pode sincronizar manualmente a qualquer momento). Cada sessão é tentada apenas uma vez; se o Google Drive ainda não publicou a gravação, o professor terá que sincronizar manualmente.';
$string['autosynchours_default'] = 'Auto-sincronização: horas padrão';
$string['autosynchours_default_desc'] = 'Valor padrão para "Horas após o fim da sessão para auto-sincronizar" ao criar novas atividades do Google Meet.';
$string['process_autosync_task'] = 'Auto-sincronizar gravações do Google Meet após o fim das sessões';
$string['or'] = 'ou';
$string['play'] = 'Reproduzir';
$string['pluginadministration'] = 'Administração do Google Meet™ para Moodle';
$string['pluginname'] = 'Google Meet™ para Moodle';
$string['privacy:metadata:googlemeet_notify_done'] = 'Registra notificações enviadas aos usuários sobre o início dos eventos. Esses dados são temporários e são excluídos após a data de início do evento.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'O ID do evento';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'O ID do usuário';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'O timestamp indicando quando o usuário recebeu uma notificação';
$string['recording'] = 'Gravação';
$string['recordings'] = 'Gravações';
$string['recordings_count'] = '{$a} gravação(ões)';
$string['recording_watch'] = 'Assistir gravação';
$string['recording_hidden'] = 'Oculto para alunos';
$string['recordingswiththename'] = 'Gravações com o nome:';
$string['recurrenceeventdate'] = 'Recorrência da data do evento';
$string['recurrenceeventdate_help'] = 'Esta função possibilita a criação de várias recorrências da data do evento.
<br>* <strong>Repetir</strong>: Selecione os dias da semana em que sua classe se reunirá (por exemplo, segunda-feira / quarta-feira / sexta-feira).
<br>* <strong>Repetir a cada</strong>: Isso permite uma configuração de frequência. Se sua classe se reunirá todas as semanas, selecione 1; se reunirá a cada duas semanas, selecione 2; a cada 3 semanas, selecione 3, e assim por diante.
<br>* <strong>Repetir até</strong>: Selecione o último dia de reunião (o último dia que você deseja levar a recorrência da data do evento).';
$string['repeatasfollows'] = 'Repita a data do evento acima da seguinte forma';
$string['repeatevery'] = 'Repetir a cada';
$string['repeaton'] = 'Repetir';
$string['repeatuntil'] = 'Repetir até';
$string['roomcreator'] = 'Organizador:';
$string['roomname'] = 'Nome da sala';
$string['roomurl'] = 'URL da sala';
$string['roomurl_caution'] = '<strong>Cuidado!</strong> Se a URL da sala ou o E-mail do organizador for alterado, as gravações já sincronizadas podem ser removidas na próxima sincronização.';
$string['roomurl_desc'] = 'A URL da sala será gerada automaticamente.';
$string['roomurlexpanded'] = 'URL da sala expandido';
$string['roomurlexpanded_desc'] = 'Mostrar as configurações de "URL da sala" expandidas por padrão ao criar uma nova sala.';
$string['servicenotenabled'] = 'Acesso não configurado. Certifique-se de que os serviços \'Google Drive API\' e \'Google Calendar API\' estejam ativados.';
$string['sessionexpired'] = 'A sessão da sua conta do Google expirou no meio do processo, faça login novamente.';
$string['show'] = 'Mostrar';
$string['strftimedm'] = '%a. %d %b.';
$string['strftimedmy'] = '%a. %d %b. %Y';
$string['strftimedmyhm'] = '%a. %d %b. %Y %H:%M';
$string['strftimehm'] = '%H:%M';
$string['syncwithgoogledrive'] = 'Sincronizar com o Google Drive';
$string['sync_settings'] = 'Configurações de sincronização';
$string['sync_help_title'] = 'Como funciona a sincronização';
$string['sync_info'] = 'Aguarde ao menos 10 minutos para que o arquivo da gravação seja gerado e salvo em "Meu Drive > Meet Recordings" do organizador.
<p></p>
Para remover uma gravação primeiro exclua o arquivo da gravação do Google Drive e depois clique no botão sincronizar acima.
<p></p>
Para gravar uma reunião, confira se:
<ul>
    <li>você não atingiu sua cota pessoal do Drive;</li>
    <li>sua organização não atingiu a cota do Drive.</li>
</ul>
Não será possível gravar a reunião se a organização não tiver espaço no Drive, mesmo que você tenha.
<p></p>
Para mais informações, veja esse artigo da Central de Ajuda:
<br>
<a href="https://notifications.google.com/g/p/APNL1TjJltVk6EcLPyFTJ8V_9ty1FeTAD0XSSJVLiaWPezIaQKfIPd1kGURFUMVV3I5yHgVZoOgxkl4gySV-4SCf2pZ27Vk8Iy9DnHSQBqtK51uG3Gyz" target="_blank" rel="nofollow noopener">https://support.google.com/meet/answer/9308681</a>';
$string['sync_notloggedin'] = 'Faça login na sua conta do Google para sincronizar a gravação do Google Meet com o Moodle';
$string['sync_new_recordings'] = '{$a} nova(s) gravação(ões) adicionada(s)';
$string['sync_updated_recordings'] = '{$a} gravação(ões) atualizada(s)';
$string['sync_deleted_recordings'] = '{$a} gravação(ões) removida(s)';
$string['sync_no_changes'] = 'Sincronização concluída. {$a} gravação(ões) já estavam atualizadas';
$string['sync_no_recordings_found'] = 'Sincronização concluída. Nenhuma gravação encontrada no Google Drive para esta reunião';
$string['thereisnorecordingtoshow'] = 'Ainda não há gravações.';
$string['timeahead'] = 'Não é possível criar várias recorrências da data do evento que excedam um ano, ajuste as datas de início e término.';
$string['timedate'] = '%d/%m/%Y %H:%M';
$string['to'] = 'até';
$string['today'] = 'Hoje';
$string['upcomingevents'] = 'Próximos eventos';
$string['event_status_live'] = 'Ao vivo agora';
$string['event_status_soon'] = 'Começa em breve';
$string['event_status_scheduled'] = 'Agendado';
$string['event_starts_in'] = 'Começa em {$a}';
$string['event_started_ago'] = 'Começou há {$a}';
$string['event_join_now'] = 'Entrar agora';
$string['event_time_minutes'] = '{$a} min';
$string['event_time_hours'] = '{$a}h';
$string['event_time_days'] = '{$a}d';
$string['event_no_upcoming'] = 'Nenhuma sessão programada';
$string['url'] = '';
$string['url_failed'] = 'É obrigatório uma URL válida do Google Meet';
$string['url_help'] = 'Ex. https://meet.google.com/aaa-aaaa-aaa';
$string['visible'] = 'Visível';
$string['week'] = 'Semana(s)';

// AI analyze transcript strings.
$string['ai_analyze_with_gemini'] = 'Analisar com Gemini';
$string['ai_analyze_transcript_hint'] = 'Cole a transcrição do Google Meet e clique em "Analisar com Gemini" para gerar automaticamente o resumo, pontos-chave e tópicos.';
$string['ai_analyze_empty_transcript'] = 'Por favor, cole uma transcrição primeiro';
$string['ai_analyzing'] = 'Analisando…';
$string['ai_analyze_success'] = 'Análise concluída! Revise e salve os resultados.';
$string['googlemeet:managequestions'] = 'Gerenciar perguntas de prática com IA das gravações';
$string['hub_back_to_recordings'] = 'Voltar às gravações';
$string['hub_tab_summary'] = 'Resumo';
$string['hub_tab_questions'] = 'Perguntas';
$string['hub_tab_transcript'] = 'Transcrição';
$string['hub_tab_materials'] = 'Materiais';
$string['hub_tab_notes'] = 'Notas';
$string['material_saved'] = 'Materiais salvos';
$string['materials_empty_teacher'] = 'Ainda não há materiais; adicione o primeiro.';
$string['materials_manage'] = 'Gerenciar materiais';
$string['materials_none'] = 'Não há materiais disponíveis para esta gravação.';
$string['materials_upload'] = 'Materiais';
$string['openindrive'] = 'Abrir no Drive';
$string['question_advanced_edit'] = 'Edição avançada no banco de questões';
$string['question_bulk_discard'] = 'Descartar selecionadas';
$string['question_bulk_publish'] = 'Publicar selecionadas';
$string['question_category_name'] = 'Google Meet: {$a}';
$string['question_correct_answer'] = 'Resposta correta';
$string['question_discard'] = 'Descartar';
$string['question_discard_ready_error'] = 'Somente perguntas em rascunho podem ser descartadas.';
$string['question_edit'] = 'Editar';
$string['question_empty_student'] = 'Ainda não há perguntas publicadas para esta aula.';
$string['question_empty_teacher'] = 'Gere perguntas com IA para criar rascunhos para revisão docente.';
$string['question_explanation'] = 'Explicação e referência';
$string['question_generate_ai'] = 'Gerar perguntas com IA';
$string['question_generate_more'] = 'Gerar mais';
$string['question_generate_task'] = 'Gerar perguntas IA do Google Meet';
$string['question_generating'] = 'Gerando perguntas...';
$string['question_no_reference'] = 'sem referência detectada';
$string['question_no_transcript_error'] = 'Não há transcrição para gerar perguntas.';
$string['question_publish'] = 'Publicar';
$string['question_reference_label'] = 'Referência:';
$string['question_status_draft'] = 'Rascunho';
$string['question_status_published'] = 'Publicado';
$string['question_stem'] = 'Pergunta';
$string['question_student_phase1'] = 'Há perguntas publicadas. O player de prática para estudantes estará disponível em uma atualização posterior.';
$string['question_unpublish'] = 'Despublicar';
$string['question_ai_draft_note'] = 'Gerado com IA, pendente de revisão';
$string['question_ai_reviewed_note'] = 'Gerado com IA, revisado pelo corpo docente';
$string['practice_check'] = 'Verificar';
$string['practice_correct'] = 'Correto';
$string['practice_correct_answer'] = 'Resposta correta:';
$string['practice_finish'] = 'Finalizar';
$string['practice_finished'] = 'Prática concluída';
$string['practice_incorrect'] = 'Incorreto';
$string['practice_loading'] = 'Carregando perguntas...';
$string['practice_next'] = 'Próxima';
$string['practice_retry'] = 'Tentar novamente';
$string['practice_title'] = 'Perguntas de prática';
$string['ai_notconfigured'] = 'A análise por IA não está habilitada neste site.';
$string['practice_progress'] = 'Pergunta {$a->current} de {$a->total}';
$string['showhide'] = 'Mostrar/ocultar';
$string['recording_hide_from_students'] = 'Ocultar dos estudantes';
$string['recording_show_to_students'] = 'Mostrar aos estudantes';
$string['ai_subtitles_unavailable'] = 'Não foram encontradas legendas geradas automaticamente para esta gravação. Clique em "Transcrever a partir do vídeo" para baixar a gravação completa e gerar uma transcrição.';
$string['ai_transcribe_from_video'] = 'Transcrever a partir do vídeo';
$string['ai_transcribe_from_video_desc'] = 'Baixa a gravação completa do Google Drive e deixa o Gemini gerar a transcrição. Pode demorar vários minutos e usar até vários GB de espaço temporário em disco.';
$string['ai_transcribe_from_video_confirm'] = 'Isto irá baixar o vídeo completo (pode ocupar vários GB). Deseja continuar?';

// Tratamento de erros e privacidade (revisão de segurança/qualidade 2026-05-30).
$string['ai_error_generic'] = 'Erro ao gerar a análise. Tente novamente mais tarde ou contate o administrador.';
$string['ai_invalid_analysis'] = 'A IA retornou uma análise que não pôde ser processada: {$a}';
$string['ai_video_not_public'] = 'Não é possível baixar a gravação para a análise de vídeo completo. Essa opção exige que a gravação esteja compartilhada publicamente ("Qualquer pessoa com o link"), o que é controlado pela configuração "Tornar gravações públicas", ou que haja legendas geradas automaticamente disponíveis. O conteúdo baixado era uma página de erro, não um vídeo.';
$string['makerecordingspublic'] = 'Tornar as gravações acessíveis publicamente';
$string['makerecordingspublic_desc'] = 'Quando ativado, as gravações sincronizadas recebem acesso de leitura "qualquer pessoa com o link" no Google Drive para que os estudantes inscritos (que não são os proprietários no Drive) possam reproduzir a gravação incorporada. Desativar isso melhora a privacidade, mas impede a reprodução para todos, exceto a conta do Google proprietária das gravações.';
$string['noeventswithperiod'] = 'Com os dias selecionados e o período de "Repetir a cada N semanas", nenhum evento seria gerado no intervalo de datas. Reduza o período ou estenda a data de término.';
$string['privacy:metadata:core_oauth2'] = 'A atividade Google Meet utiliza o subsistema OAuth 2 para autenticar os usuários nos serviços do Google.';
$string['privacy:metadata:googlemeet'] = 'Informações sobre as instâncias da atividade Google Meet.';
$string['privacy:metadata:googlemeet:creatoremail'] = 'O endereço de e-mail da conta Google usada para criar a sala do Meet. Identifica o criador, mas é armazenado como propriedade da atividade, não vinculado a uma conta de usuário do Moodle.';
$string['privacy:metadata:googlemeet_ai_analysis'] = 'Análise gerada por IA das gravações. A transcrição pode conter incidentalmente os nomes ou as vozes dos participantes da sessão. Esses dados estão associados a uma gravação, não a um usuário específico do Moodle.';
$string['privacy:metadata:googlemeet_ai_analysis:summary'] = 'Um resumo do conteúdo da gravação gerado por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:keypoints'] = 'Pontos-chave extraídos do conteúdo da gravação gerados por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:topics'] = 'Tópicos abordados no conteúdo da gravação gerados por IA.';
$string['privacy:metadata:googlemeet_ai_analysis:transcript'] = 'Uma transcrição da gravação, que pode conter os nomes ou as vozes dos participantes da sessão.';
$string['privacy:metadata:googlemeet_recordings'] = 'Informações sobre as gravações sincronizadas do Google Drive, incluindo a transcrição original do Meet. A transcrição pode conter os nomes ou a fala dos participantes da sessão. Esses dados estão associados a uma gravação, não a um usuário individual do Moodle.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'O nome do arquivo da gravação.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'O link do Google Drive usado para ver a gravação.';
$string['privacy:metadata:googlemeet_recordings:transcripttext'] = 'A transcrição original do Google Meet da gravação, que pode conter os nomes ou a fala dos participantes da sessão.';
$string['privacy:metadata:googlemeet_recordings:transcriptfileid'] = 'O identificador do arquivo do Google Drive da transcrição da gravação.';
$string['privacy:metadata:googlemeet_recordings:notestext'] = 'As notas da reunião geradas pelo Gemini da gravação, que podem conter os nomes ou as contribuições dos participantes da sessão.';
$string['privacy:metadata:googlemeet_recordings:notesdocid'] = 'O identificador do documento do Google Drive das notas da gravação.';
$string['privacy:metadata:google_gemini'] = 'As transcrições das gravações e, como alternativa, o vídeo completo da gravação são enviados à API do Google Gemini para gerar a análise. Podem conter dados pessoais como nomes e vozes dos participantes.';
$string['privacy:metadata:google_gemini:transcript'] = 'O texto da transcrição da gravação enviado para análise.';
$string['privacy:metadata:google_gemini:video'] = 'O arquivo de vídeo completo da gravação, enviado para análise quando não há transcrição disponível.';
$string['privacy:metadata:google_drive'] = 'As gravações são lidas do Google Drive do usuário em seu nome usando a autorização OAuth 2.';
$string['privacy:metadata:google_drive:userid'] = 'A identidade do usuário autenticado é enviada ao Google Drive para acessar suas gravações.';
$string['privacy:metadata:google_calendar'] = 'Os eventos de calendário e a sala do Meet são criados e lidos no Google Calendar do usuário em seu nome usando a autorização OAuth 2.';
$string['privacy:metadata:google_calendar:userid'] = 'A identidade do usuário autenticado é enviada ao Google Calendar para gerenciar seus eventos e a sala do Meet.';
$string['subtitlelanguage'] = 'Idioma das legendas';
$string['subtitlelanguage_desc'] = 'Código de idioma usado ao extrair as legendas geradas automaticamente das gravações do Google Drive (ex.: es, en, pt-BR). O padrão é es.';
$string['googlemeet:subscriberecordings'] = 'Assinar notificações de novas gravações';
$string['messageprovider:recordingavailable'] = 'Gravação do Google Meet disponível';
$string['privacy:metadata:googlemeet_recording_subs'] = 'Armazena os usuários inscritos para receber notificações quando novas gravações estão disponíveis em uma atividade Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:googlemeetid'] = 'O ID da atividade Google Meet.';
$string['privacy:metadata:googlemeet_recording_subs:userid'] = 'O ID do usuário.';
$string['privacy:metadata:googlemeet_recording_subs:timecreated'] = 'O timestamp indicando quando o usuário assinou as notificações de gravações.';
$string['recordingavailable_body'] = '{$a->count} nova(s) gravação(ões) estão disponíveis em {$a->name}.' . "\n\n" . 'Abra a atividade: {$a->url}';
$string['recordingavailable_subject'] = '{$a->count} nova(s) gravação(ões) disponível(is): {$a->name}';
$string['recordingnotificationsubscription'] = 'Assinatura de notificações de gravações';
$string['subscriberecordings'] = 'Receber avisos';
$string['unsubscriberecordings'] = 'Parar avisos';

// Recordings card/list UI.
$string['ai_status_chip_processing'] = 'Análise em andamento';
$string['ai_status_chip_pending'] = 'Análise na fila';
$string['ai_status_chip_failed_student'] = 'Análise indisponível';
$string['ai_status_chip_failed_teacher'] = 'Erro de análise';
$string['ai_analysis'] = 'Análise com IA';
$string['ai_edit_manual'] = 'Editar análise';
$string['ai_badge_label'] = 'Análise IA';
$string['ai_analysis_available'] = 'Análise IA disponível';
$string['ai_keypoints_short'] = 'pontos-chave';
$string['ai_keypoints_count'] = '{$a} ponto-chave';
$string['ai_keypoints_count_plural'] = '{$a} pontos-chave';
$string['recording_watch_cta'] = 'Abrir aula';
$string['recording_new'] = 'Nova';
$string['recording_view_summary'] = 'Ver resumo';
$string['recording_play_aria'] = 'Abrir aula gravada';
$string['recordings_search_label'] = 'Buscar aulas';
$string['recordings_search_placeholder'] = 'Título, tópico ou resumo';
$string['recordings_search_button'] = 'Buscar';
$string['recordings_filter_clear'] = 'Limpar filtros';
$string['recordings_filter_all_topics'] = 'Todos os tópicos';
$string['recordings_no_filter_results'] = 'Nenhuma gravação corresponde aos filtros.';
$string['recordings_topics_overflow_aria'] = 'mais {$a} tópicos';
$string['recordings_pagination_info'] = 'Mostrando {$a->start} a {$a->end} de {$a->total} gravações';
$string['recordings_sort_by'] = 'Ordenar por';
$string['recordings_view_label'] = 'Visualização';
$string['recordings_view_cards'] = 'Cartões';
$string['recordings_view_list'] = 'Lista';
$string['recordings_view_cards_aria'] = 'Ver gravações como cartões';
$string['recordings_view_list_aria'] = 'Ver gravações como lista';
