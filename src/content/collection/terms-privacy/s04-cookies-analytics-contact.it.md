---
lang: it
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookie ed eventi di contatto"
title: "Cookie, analisi e interazioni di contatto"
---

Le statistiche dei contatti e le analisi del percorso richiedono entrambe il consenso all'analisi. WhatsApp e l'e-mail restano disponibili senza tracciamento facoltativo.

### 1. Eventi di contatto

Solo con il consenso all'analisi, la selezione di un link WhatsApp o e-mail può registrare un evento contenente:

- il canale selezionato;
- un tipo di evento `open_intent`;
- l'ora del server;
- il percorso della pagina pertinente sul sito;
- il posizionamento del link;
- lo SKU del prodotto ove pertinente;
- la lingua del sito; e
- uno stato di "intento" (`intent`).

Senza consenso all'analisi, le statistiche dei contatti non richiedono credenziali, non impostano il relativo cookie e non registrano aperture. Con il consenso, l'evento può essere collegato al percorso descritto sotto. Un trattamento di sicurezza separato può proteggere il contatto richiesto.

Un record `open_intent` significa solo che il link di contatto del sito web è stato attivato. Non dimostra che un dispositivo abbia aperto con successo WhatsApp o un client e-mail, che il visitatore abbia inviato un messaggio o che Kssmi ne abbia ricevuto uno.

Un evento `submission_success` indica l'accettazione della richiesta nel processo di invio, che può includere una coda. Non prova la consegna dell'e-mail, la ricezione da parte di una persona specifica, la lettura o una risposta.

### 2. Tracciamento del percorso del visitatore (VJT)

Con il consenso per l'analisi, il VJT può utilizzare un identificatore del visitatore di prima parte e un identificatore di sessione di breve durata per associare le visite alle pagine e gli eventi di contatto a un unico percorso acconsentito. A seconda della configurazione attiva, i dati del percorso possono includere:

- URL e titoli delle pagine;
- orari di visita e interazione;
- parametri del referrer e della campagna;
- informazioni su browser, dispositivo, schermo, lingua e fuso orario;
- paese o città derivati dall'IP;
- misurazioni di scorrimento e coinvolgimento; e
- attribuzione delle richieste o degli eventi di contatto.

Il percorso di analisi deve rimanere disattivato finché il visitatore non concede il consenso all'analisi. Ogni consenso avvia un nuovo ciclo di consenso e gli identificatori VJT non vengono trasferiti da un ciclo al successivo. Quando il consenso viene revocato, il sito interrompe nel browser la nuova raccolta di dati di analisi e l'emissione di identificatori, rimuove i valori VJT dalla memoria del browser e chiede al server di far scadere il cookie di identità HttpOnly. Tale eliminazione viene ritentata un numero limitato di volte e può non riuscire, ad esempio se il browser è offline o la richiesta viene bloccata; un cookie di identità rimasto non viene usato per proseguire il percorso precedente e viene sostituito se il consenso viene concesso di nuovo. I dati già inviati prima della revoca possono ancora essere ricevuti. Gli identificatori creati prima dell'introduzione dei cicli di consenso vengono sostituiti una sola volta. I browser privi delle funzionalità necessarie per coordinare il consenso tra le schede non utilizzano VJT.

### 3. Pubblicità e analisi di terze parti

Google Analytics, Google Ads, Google Tag Manager o tecnologie comparabili operano secondo le categorie di consenso scelte dal visitatore e la configurazione attiva del sito. L'archiviazione per analisi e pubblicità resta negata finché non viene concesso il relativo consenso.

### 4. Cookie e archiviazione nel browser

Di seguito sono elencate le principali voci di archiviazione nel browser. Gli identificatori di terzi possono variare secondo fornitore, browser, consenso e configurazione attiva.

| Nome | Fornitore | Finalità | Categoria | Durata | Tipo di archiviazione |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ricordare le scelte del visitatore relative ad analisi e pubblicità | Necessario | Finché la scelta non viene modificata o la memoria del browser viene cancellata | Archiviazione locale |
| `vjt_visitor_id` | Kssmi | Associare le visite acconsentite a un percorso del visitatore | Analisi | Cookie: fino a circa 365 giorni; copia locale: Fino alla revoca del consenso alle analisi o alla cancellazione della memoria del browser | Cookie e archiviazione locale |
| `vjt_session_id` | Kssmi | Associare gli eventi della pagina acconsentita all'interno di una sessione | Analisi | Circa 30 minuti | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identità server firmata che collega le visite acconsentite all'interno di un ciclo di consenso; non leggibile dagli script della pagina | Analisi | Fino a circa 365 giorni; fatto scadere dopo la revoca quando il browser lo consente | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Sessione firmata per proteggere statistiche dei contatti consentite, legata al ciclo di consenso attuale; emessa solo con consenso all'analisi | Analisi | Circa 30 minuti, rinnovata durante la navigazione | Cookie (HttpOnly) |
| Altri identificatori Google/di terze parti | Google / terza parte pertinente | Analisi o pubblicità | Analisi/pubblicità | Varia in base al fornitore e alla configurazione | Cookie o tecnologia simile |

L'inventario dei cookie, il banner di consenso e l'implementazione live devono concordare. Rinominare un tracker o spostare un identificatore da un cookie all'archiviazione locale non rende di per sé la tecnologia esente dal consenso.

### 5. Modifica delle scelte di consenso

I visitatori devono essere in grado di riaprire le Impostazioni dei cookie e modificare o revocare il consenso per l'analisi e la pubblicità con la stessa facilità con cui è stato fornito. La revoca non pregiudica il trattamento che era lecito prima della revoca. Dopo aver effettuato una scelta, su ogni pagina resta disponibile un pulsante Impostazioni dei cookie.

### 6. Conteggio anonimo delle visualizzazioni di pagina
Indipendentemente dall'analisi basata sul consenso descritta sopra, il sito conteggia le visualizzazioni di pagina in forma aggregata: per ogni giorno di calendario (ora di Pechino) e percorso di pagina, memorizza solo il numero totale di visualizzazioni. Questa tabella di conteggio non utilizza cookie, memoria del browser o identificatori di visitatore/sessione e non include indirizzi IP, user agent, referrer o timestamp di singole visite. La limitazione della frequenza, il filtraggio dei bot e i log del server sono operazioni di sicurezza separate descritte nelle rispettive sezioni; non fanno parte di questa tabella aggregata. Il server può leggere separatamente un marcatore firmato di esclusione degli amministratori per non conteggiare il traffico amministrativo; tale marcatore non è memorizzato nella tabella aggregata anonima.
