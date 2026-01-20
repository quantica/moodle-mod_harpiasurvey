<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     mod_harpiasurvey
 * @category    string
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Harpia Survey';
$string['modulename'] = 'Harpia Survey';
$string['modulenameplural'] = 'Harpia Surveys';
$string['modulename_help'] = 'O módulo Harpia Survey permite que pesquisadores criem experimentos para estudar LLMs, interajam com eles e coletem respostas de pesquisas de qualidade dos estudantes.';
$string['harpiasurveyname'] = 'Nome da pesquisa';
$string['harpiasurveyname_help'] = 'O nome desta atividade Harpia Survey.';
$string['harpiasurvey:addinstance'] = 'Adicionar nova Harpia Survey';
$string['harpiasurvey:view'] = 'Visualizar Harpia Survey';
$string['harpiasurvey:manageexperiments'] = 'Gerenciar experimentos';
$string['noharpiasurveys'] = 'Nenhuma instância de Harpia Survey';
$string['pluginadministration'] = 'Administração do Harpia Survey';
$string['eventcourse_module_viewed'] = 'Módulo do curso visualizado';
$string['experimentname'] = 'Experimento';
$string['participants'] = 'Participantes';
$string['status'] = 'Status';
$string['actions'] = 'Ações';
$string['view'] = 'Visualizar';
$string['noexperiments'] = 'Nenhum experimento disponível ainda.';
$string['createexperiment'] = 'Criar experimento';
$string['registermodel'] = 'Cadastrar modelo';
$string['viewstats'] = 'Ver estatísticas';
$string['statusrunning'] = 'Rodando';
$string['statusfinished'] = 'Finalizado';
$string['statusdraft'] = 'Rascunho';
$string['newexperiment'] = 'Novo experimento';
$string['editexperiment'] = 'Editar experimento';
$string['type'] = 'Tipo';
$string['models'] = 'Modelos';
$string['searchmodels'] = 'Buscar modelos...';
$string['validation'] = 'Validação';
$string['validationnone'] = 'Sem validação';
$string['validationcrossvalidation'] = 'Validação cruzada';
$string['available'] = 'Disponível';
$string['continue'] = 'Continuar';
$string['experimentsaved'] = 'Experimento salvo com sucesso';
$string['errormodelsrequired'] = 'Pelo menos um modelo deve ser selecionado';
$string['description'] = 'Descrição';
$string['newmodel'] = 'Novo modelo';
$string['editmodel'] = 'Editar modelo';
$string['modelname'] = 'Nome';
$string['modelidentifier'] = 'Modelo';
$string['provider'] = 'Provedor';
$string['provider_help'] = 'Selecione o provedor da API e configure os campos no formato correspondente.';
$string['provideropenai'] = 'OpenAI';
$string['provideropenrouter'] = 'OpenRouter (compatível com OpenAI)';
$string['providerazureopenai'] = 'Azure OpenAI';
$string['provideranthropic'] = 'Anthropic (Claude)';
$string['providergemini'] = 'Google Gemini';
$string['providerollama'] = 'Ollama / Local';
$string['providercustom'] = 'Personalizado';
$string['modelidentifier_help'] = 'O identificador do modelo usado pela API (ex: gpt-4o-2025712).';
$string['baseurl'] = 'URL base';
$string['baseurl_help'] = 'URL base do provedor (ou endpoint completo para provedores personalizados).';
$string['apikey'] = 'Chave de API';
$string['apikey_help'] = 'A chave de API para autenticação com o serviço.';
$string['azureresource'] = 'Recurso Azure';
$string['azureresource_help'] = 'Nome do recurso Azure OpenAI (ex: meu-recurso).';
$string['azuredeployment'] = 'Deployment Azure';
$string['azuredeployment_help'] = 'Nome do deployment do Azure OpenAI.';
$string['azureapiversion'] = 'Versão da API Azure';
$string['azureapiversion_help'] = 'Versão da API do Azure OpenAI (ex: 2024-02-15-preview).';
$string['anthropicversion'] = 'Versão da API Anthropic';
$string['anthropicversion_help'] = 'Valor do cabeçalho anthropic-version (ex: 2023-06-01).';
$string['systemprompt'] = 'Prompt de sistema';
$string['systemprompt_help'] = 'Mensagem de papel de sistema opcional enviada antes de cada requisição (ex: "Você é um assistente de pesquisa prestativo.").';
$string['systemprompt_placeholder'] = 'Exemplo: Você é um assistente de pesquisa prestativo.';
$string['customheaders'] = 'Cabeçalhos personalizados';
$string['customheaders_help'] = 'Objeto JSON ou lista de cabeçalhos (ex: {"X-Api-Key": "abc"}).';
$string['customheaders_placeholder'] = '{"X-Api-Key": "abc123"}';
$string['responsepath'] = 'Caminho da resposta';
$string['responsepath_help'] = 'Caminho em ponto para extrair o conteúdo do JSON (ex: choices.0.message.content).';
$string['extrafields'] = 'Campos extra';
$string['extrafields_help'] = 'Parâmetros adicionais em JSON (ex: {"temperature": 0}). A formatação e espaçamento serão preservados.';
$string['extrafields_placeholder'] = '{"temperature": 0.2, "max_tokens": 512}';
$string['enabled'] = 'Habilitado';
$string['addmodel'] = 'Adicionar modelo';
$string['modelsaved'] = 'Modelo salvo com sucesso';
$string['invalidurl'] = 'Formato de URL inválido';
$string['invalidjson'] = 'Formato JSON inválido';
$string['maxparticipants'] = 'Máximo de participantes';
$string['startdate'] = 'Data de início';
$string['enddate'] = 'Data de término';
$string['invalidnumber'] = 'Número inválido';
$string['unlimited'] = 'Ilimitado';
$string['pages'] = 'Páginas';
$string['addpage'] = 'Adicionar página';
$string['editingpage'] = 'Editando página: {$a}';
$string['addingpage'] = 'Adicionando uma nova página';
$string['nopages'] = 'Ainda não há páginas. Clique em "Adicionar página" para criar a primeira página.';
$string['backtocourse'] = 'Voltar ao curso';
$string['title'] = 'Título';
$string['typeopening'] = 'Abertura';
$string['typedemographic'] = 'Coleta de dados demográficos';
$string['typeinteraction'] = 'Interação com modelo';
$string['typefeedback'] = 'Feedback';
$string['typeaichat'] = 'Avaliação de modelo IA';
$string['typegeneral'] = 'Geral';
$string['pagebehavior'] = 'Modo de Avaliação';
$string['pagebehavior_help'] = 'Escolha como a avaliação do modelo IA funciona: Pergunta e resposta, Contínua (conversa contínua), Turnos (navegação por turnos com árvore de conversa), Multi-modelo (futuro).';
$string['pagebehaviorqa'] = 'Pergunta & Resposta';
$string['pagebehaviorcontinuous'] = 'Contínua';
$string['pagebehaviorturns'] = 'Turnos';
$string['pagebehaviormultimodel'] = 'Multi-modelo';
$string['save'] = 'Salvar';
$string['pagesaved'] = 'Página salva com sucesso';
$string['selectpagetoadd'] = 'Selecione uma página da lista para editar, ou clique em "Adicionar página" para criar uma nova.';
$string['questionbank'] = 'Banco de perguntas';
$string['questions'] = 'Perguntas';
$string['question'] = 'Pergunta';
$string['noquestions'] = 'Ainda não há perguntas. Clique em "Criar pergunta" para adicionar a primeira.';
$string['createquestion'] = 'Criar pergunta';
$string['editingquestion'] = 'Editando pergunta: {$a}';
$string['creatingquestion'] = 'Criando pergunta';
$string['questionname'] = 'Nome';
$string['questionshortname'] = 'Nome curto';
$string['questionsaved'] = 'Pergunta salva com sucesso';
$string['back'] = 'Voltar';
$string['savechanges'] = 'Salvar mudanças';
$string['typesinglechoice'] = 'Escolha única';
$string['typemultiplechoice'] = 'Escolha múltipla';
$string['typenumber'] = 'Número';
$string['typeshorttext'] = 'Texto curto';
$string['typelongtext'] = 'Texto longo';
$string['typeaiconversation'] = 'Conversa IA';
$string['type_help'] = 'Dependendo do tipo de pergunta criada, os campos abaixo mudam para as configurações específicas.';
$string['selectionfieldsettings'] = 'Configurações do campo de seleção';
$string['optionsoneline'] = 'Opções (uma por linha)';
$string['optionsoneline_help'] = 'Digite cada opção em uma linha separada.';
$string['defaultvalue'] = 'Valor padrão';
$string['noquestionsonpage'] = 'Ainda não há perguntas adicionadas a esta página.';
$string['savepagetoaddquestions'] = 'Salve a página primeiro para adicionar perguntas.';
$string['addquestiontopage'] = 'Adicionar pergunta à página';
$string['questionaddedtopage'] = 'Pergunta adicionada à página com sucesso';
$string['questionremovedfrompage'] = 'Pergunta removida da página com sucesso';
$string['noquestionsavailable'] = 'Não há perguntas disponíveis. Todas as perguntas já foram adicionadas a esta página.';
$string['questionalreadyonpage'] = 'Esta pergunta já está na página.';
$string['remove'] = 'Remover';
$string['add'] = 'Adicionar';
$string['stats'] = 'Estatísticas';
$string['noresponses'] = 'Ainda não há respostas.';
$string['answer'] = 'Resposta';
$string['time'] = 'Hora';
$string['typeselect'] = 'Seleção';
$string['typelikert'] = 'Likert (escala 1-5)';
$string['numberfieldsettings'] = 'Configurações do campo numérico';
$string['numbertype'] = 'Tipo de número';
$string['numbertypeinteger'] = 'Inteiro';
$string['numbertypedecimal'] = 'Decimal';
$string['numbermin'] = 'Valor mínimo';
$string['numbermin_help'] = 'Valor mínimo permitido para este campo numérico.';
$string['numbermax'] = 'Valor máximo';
$string['numbermax_help'] = 'Valor máximo permitido para este campo numérico.';
$string['numberdefault'] = 'Valor padrão';
$string['numberallownegatives'] = 'Permitir valores negativos';
$string['aiconversationsettings'] = 'Configurações de conversa IA';
$string['aimodels'] = 'Modelos';
$string['aimodels_help'] = 'Selecione um ou mais modelos de IA para usar nesta pergunta de conversa.';
$string['aibehavior'] = 'Comportamento';
$string['aibehavior_help'] = 'Escolha como a IA deve interagir: Pergunta e resposta para pergunta-resposta única, Chat para conversa contínua.';
$string['aibehaviorqa'] = 'Pergunta & Resposta';
$string['aibehaviorchat'] = 'Chat';
$string['aitemplate'] = 'Template';
$string['aitemplate_help'] = 'Prompt do sistema ou template para orientar o comportamento da IA nesta conversa.';
$string['noselection'] = 'Nenhuma seleção';
$string['aiconversationplaceholder'] = 'A interface de conversa IA será exibida aqui.';
$string['newquestion'] = 'Nova pergunta';
$string['qaplaceholder'] = 'Faça uma pergunta para obter uma resposta.';
$string['save'] = 'Salvar';
$string['saved'] = 'Salvo';
$string['responsesaved'] = 'Resposta salva com sucesso';
$string['saving'] = 'Salvando...';
$string['on'] = 'em';
$string['typeyourmessage'] = 'Digite sua mensagem aqui...';
$string['waitingforresponse'] = 'Aguardando resposta da IA...';
$string['nomodelsavailable'] = 'Nenhum modelo está disponível para esta pergunta. Por favor, entre em contato com o administrador.';
$string['responses'] = 'Respostas';
$string['conversations'] = 'Conversas';
$string['noconversations'] = 'Ainda não há conversas.';
$string['conversation'] = 'Conversa';
$string['messages'] = 'mensagens';
$string['viewconversation'] = 'Ver conversa';
$string['hideconversation'] = 'Ocultar conversa';
$string['role'] = 'Papel';
$string['messageid'] = 'ID da mensagem';
$string['parentid'] = 'ID do pai';
$string['downloadcsv'] = 'Baixar CSV';
$string['evaluatesconversation'] = 'Avalia conversa';
$string['evaluatesconversation_help'] = 'Selecione qual conversa de IA esta pergunta avalia. Deixe vazio se esta é uma pergunta geral não vinculada a uma conversa específica.';
$string['none'] = 'Nenhum';
$string['deletepage'] = 'Excluir página';
$string['deletepageconfirm'] = 'Tem certeza de que deseja excluir a página "{$a->title}"? Isso também excluirá {$a->questions} associação(ões) de pergunta(s), {$a->responses} resposta(s) e {$a->conversations} conversa(s). Esta ação não pode ser desfeita.';
$string['pagedeleted'] = 'Página excluída com sucesso';
$string['deletequestion'] = 'Excluir pergunta';
$string['deletequestionconfirm'] = 'Tem certeza de que deseja excluir a pergunta "{$a->name}"? Isso também excluirá {$a->pages} associação(ões) de página(s), {$a->options} opção(ões), {$a->responses} resposta(s), {$a->conversations} conversa(s) e {$a->evaluates} relacionamento(s) de avaliação. Esta ação não pode ser desfeita.';
$string['questiondeleted'] = 'Pergunta excluída com sucesso';
$string['turn'] = 'Turno';
$string['nextturn'] = 'Próximo turno';
$string['previousturn'] = 'Turno anterior';
$string['turncomplete'] = 'Turno completo';
$string['turnlocked'] = 'Este turno está bloqueado. Navegue para o turno atual para enviar mensagens.';
$string['gotocurrentturn'] = 'Ir para o turno atual';
$string['createnextturn'] = 'Criar próximo turno';
$string['showpreviousmessages'] = 'Mostrar mensagens anteriores';
$string['hidepreviousmessages'] = 'Ocultar mensagens anteriores';
$string['conversationtree'] = 'Árvore de Conversa';
$string['showconversationtree'] = 'Mostrar árvore de conversa';
$string['createbranch'] = 'Criar ramo';
$string['createnewroot'] = 'Criar nova raiz de conversa';
$string['branchfromturn'] = 'Ramo do turno';
$string['selectbranch'] = 'Selecionar ramo';
$string['currentbranch'] = 'Turno atual';
$string['branch'] = 'Ramo';
$string['togglepreviousmessages'] = 'Expandir/Contrair conversas anteriores';
$string['showprevious'] = 'Mostrar anteriores';
$string['hideprevious'] = 'Ocultar anteriores';
$string['newturn'] = 'Novo turno';
