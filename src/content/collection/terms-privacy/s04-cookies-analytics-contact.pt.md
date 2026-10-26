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

A medição de contactos e a análise do percurso exigem consentimento de análise. WhatsApp e e-mail continuam disponíveis sem rastreio opcional.

### 1. Eventos de contato

Só com consentimento de análise, selecionar um link WhatsApp ou e-mail pode registar um evento contendo:

- o canal selecionado;
- um tipo de evento `open_intent`;
- horário do servidor;
- o caminho da página relevante no site;
- posicionamento do link;
- SKU do produto quando relevante;
- idioma do site; e
- um status de "intenção" (`intent`).

Sem consentimento de análise não são pedidos tokens, definido o cookie de medição de contactos ou registadas aberturas. Com consentimento, o evento pode ligar-se ao percurso descrito abaixo. Um tratamento de segurança separado pode proteger o contacto solicitado.

Um registro `open_intent` significa apenas que o link de contato do site foi acionado. Não prova que um dispositivo abriu o WhatsApp ou um cliente de e-mail com sucesso, que o visitante enviou uma mensagem ou que a Kssmi recebeu uma.

Um evento `submission_success` significa que o site aceitou o pedido no processo de envio, que pode incluir uma fila. Não comprova a entrega do e-mail, a receção por uma pessoa específica, a leitura ou uma resposta.

### 2. Rastreamento da Jornada do Visitante (VJT)

Com o consentimento para análise, o VJT pode usar um identificador de visitante primário e um identificador de sessão de curta duração para associar visitas a páginas e eventos de contato a uma jornada consentida. Dependendo da configuração ativa, os dados da jornada podem incluir:

- URLs e títulos de páginas;
- horários de visita e interação;
- parâmetros de referência e campanha;
- informações sobre navegador, dispositivo, tela, idioma e fuso horário;
- país ou cidade derivados do IP;
- medições de rolagem e engajamento; e
- atribuição de consulta ou evento de contato.

A jornada de análise deve permanecer desativada até que o visitante conceda o consentimento para análises. Cada consentimento inicia um novo ciclo de consentimento, e os identificadores VJT não passam de um ciclo para o seguinte. Quando o consentimento é retirado, o site interrompe no navegador a nova recolha de análises e a emissão de identificadores, remove os valores VJT do armazenamento do navegador e pede ao servidor que faça expirar o cookie de identidade HttpOnly. Essa eliminação é repetida um número limitado de vezes e pode falhar, por exemplo, se o navegador estiver offline ou o pedido for bloqueado; um cookie de identidade que permaneça não é usado para continuar a jornada anterior e é substituído se o consentimento for concedido novamente. Os dados já enviados antes da retirada ainda podem ser recebidos. Os identificadores criados antes da introdução dos ciclos de consentimento são substituídos uma vez. Os navegadores sem os recursos necessários para coordenar o consentimento entre separadores não utilizam o VJT.

### 3. Publicidade e análises de terceiros

Google Analytics, Google Ads, Google Tag Manager ou tecnologias comparáveis funcionam segundo as categorias de consentimento escolhidas e a configuração ativa do site. O armazenamento para análise e publicidade permanece recusado até ao consentimento correspondente.

### 4. Cookies e armazenamento do navegador

Os principais elementos de armazenamento do navegador são listados abaixo. Os identificadores de terceiros variam conforme fornecedor, navegador, consentimento e configuração ativa.

| Nome | Provedor | Finalidade | Categoria | Duração | Tipo de armazenamento |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Lembrar as escolhas do visitante quanto a análises e publicidade | Necessário | Até que a escolha seja alterada ou o armazenamento do navegador seja limpo | Armazenamento local |
| `vjt_visitor_id` | Kssmi | Associar visitas consentidas com uma jornada de visitante | Análise | Cookie: até cerca de 365 dias; cópia local: Até que o consentimento para análise seja retirado ou o armazenamento do navegador seja limpo | Cookie e armazenamento local |
| `vjt_session_id` | Kssmi | Associar eventos de página consentidos dentro de uma sessão | Análise | Cerca de 30 minutos | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identidade de servidor assinada que associa as visitas consentidas dentro de um ciclo de consentimento; não pode ser lida pelos scripts da página | Análise | Até aproximadamente 365 dias; expirado após a retirada quando o navegador o permite | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Sessão assinada que protege estatísticas de contactos consentidas, ligada ao ciclo atual; emitida apenas com consentimento de análise | Análise | Aproximadamente 30 minutos, renovada durante a navegação | Cookie (HttpOnly) |
| Outros identificadores do Google/terceiros | Google / terceiro relevante | Análise ou publicidade | Análise/publicidade | Varia conforme o fornecedor e a configuração | Cookie ou tecnologia similar |

O inventário de cookies, o banner de consentimento e a implementação ao vivo devem estar de acordo. Renomear um rastreador ou mover um identificador de um cookie para armazenamento local não isenta a tecnologia da necessidade de consentimento por si só.

### 5. Alteração das escolhas de consentimento

Os visitantes devem poder reabrir as Configurações de cookies e alterar ou retirar o consentimento para análises e publicidade com a mesma facilidade com que o concederam. A retirada não afeta o processamento que era lícito antes de ser solicitada. Depois de feita uma escolha, um botão de Configurações de cookies permanece disponível em todas as páginas.

### 6. Contagem anônima de visualizações de página
Independentemente da análise baseada em consentimento descrita acima, o site conta as visualizações de páginas de forma agregada: para cada dia do calendário (horário de Pequim) e caminho de página, armazena apenas o número total de visualizações. Essa tabela de contagem não usa cookies, armazenamento do navegador ou identificadores de visitante/sessão e não inclui endereços IP, user agents, referentes nem registros de horário de visitas individuais. A limitação de taxa, a filtragem de bots e os registros do servidor são operações de segurança separadas, descritas em suas próprias seções; não fazem parte dessa tabela agregada. O servidor pode ler separadamente um marcador assinado de exclusão de administradores para não contar o tráfego de administração; esse marcador não é armazenado na tabela agregada anônima.
