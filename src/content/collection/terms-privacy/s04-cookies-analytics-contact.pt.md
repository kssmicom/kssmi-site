---
lang: pt
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookies e eventos de contato"
title: "Cookies, análise e interações de contato"
---

A Kssmi separa uma função mínima de evento de contato da função de análise da jornada do visitante baseada em consentimento. Elas atendem a propósitos diferentes e não devem ser descritas como um sistema único com uma única base legal.

### 1. Eventos de contato

Quando um visitante seleciona deliberadamente um link do WhatsApp ou e-mail, o site pode registrar um evento mínimo mostrando que o ponto de entrada de contato foi aberto. Sem o consentimento para análise, esse evento foi projetado para conter apenas:

- o canal selecionado;
- um tipo de evento `open_intent`;
- horário do servidor;
- o caminho da página relevante no site;
- posicionamento do link;
- SKU do produto quando relevante;
- idioma do site; e
- um status de "intenção" (`intent`).

Sem o consentimento para análise, esse registro não deve criar ou ler um identificador de visitante/sessão VJT e não deve conter um histórico de navegação reconstruído, URL de referência completa, parâmetros de campanha, endereço IP, agente do usuário ou geolocalização. O processamento de segurança autônomo e de curta duração pode ocorrer para limitação de taxa.

Um registro `open_intent` significa apenas que o link de contato do site foi acionado. Não prova que um dispositivo abriu o WhatsApp ou um cliente de e-mail com sucesso, que o visitante enviou uma mensagem ou que a Kssmi recebeu uma.

Para um formulário de consulta, um evento `submission_success` significa que o processo de envio configurado no site relatou sucesso. Isso não prova que um destinatário leu ou respondeu ao e-mail.

### 2. Rastreamento da Jornada do Visitante (VJT)

Com o consentimento para análise, o VJT pode usar um identificador de visitante primário e um identificador de sessão de curta duração para associar visitas a páginas e eventos de contato a uma jornada consentida. Dependendo da configuração ativa, os dados da jornada podem incluir:

- URLs e títulos de páginas;
- horários de visita e interação;
- parâmetros de referência e campanha;
- informações sobre navegador, dispositivo, tela, idioma e fuso horário;
- país ou cidade derivados do IP;
- medições de rolagem e engajamento; e
- atribuição de consulta ou evento de contato.

A jornada de análise deve permanecer desativada até que o visitante conceda consentimento para análise. Se o consentimento for retirado, a coleta subsequente de análises deverá ser interrompida e os identificadores VJT armazenados no navegador deverão ser removidos de acordo com o processo de retirada implementado.

### 3. Publicidade e análises de terceiros

Google Analytics, Google Ads, Google Tag Manager ou tecnologia de medição comparável devem operar de acordo com as categorias de consentimento selecionadas pelo visitante e a configuração real do site. O aviso final deve descrever apenas produtos e recursos que estejam genuinamente ativados.

### 4. Cookies e armazenamento do navegador

Os seguintes períodos e critérios aplicam-se aos sistemas do site descritos neste aviso:

| Nome | Provedor | Finalidade | Categoria | Duração | Tipo de armazenamento |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Lembrar as escolhas do visitante quanto a análises e publicidade | Necessário | Até que a escolha seja alterada ou o armazenamento do navegador seja limpo | Local storage |
| `vjt_visitor_id` | Kssmi | Associar visitas consentidas com uma jornada de visitante | Análise | Cookie: up to about 365 days; local copy: Até que o consentimento para análise seja retirado ou o armazenamento do navegador seja limpo | Cookie e armazenamento local |
| `vjt_session_id` | Kssmi | Associar eventos de página consentidos dentro de uma sessão | Análise | About 30 minutes | Cookie |
| Outros identificadores do Google/terceiros | Google / relevant third party | Análise ou publicidade | Análise/publicidade | Varia conforme o fornecedor e a configuração | Cookie ou tecnologia similar |

O inventário de cookies, o banner de consentimento e a implementação ao vivo devem estar de acordo. Renomear um rastreador ou mover um identificador de um cookie para armazenamento local não isenta a tecnologia da necessidade de consentimento por si só.

### 5. Alteração das escolhas de consentimento

Os visitantes devem poder reabrir as Configurações de cookies e alterar ou retirar o consentimento para análises e publicidade com a mesma facilidade com que o concederam. A retirada não afeta o processamento que era lícito antes de ser solicitada.
