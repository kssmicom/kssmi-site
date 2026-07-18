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

Kssmi separa una funzione minima per gli eventi di contatto dalla funzione di analisi del percorso del visitatore basata sul consenso. Servono a scopi diversi e non devono essere descritte come un unico sistema con un'unica base giuridica.

### 1. Eventi di contatto

Quando un visitatore seleziona deliberatamente un link WhatsApp o un'e-mail, il sito web può registrare un evento minimo che indica l'apertura del punto di accesso del contatto. Senza il consenso per l'analisi, questo evento è progettato per contenere solo:

- il canale selezionato;
- un tipo di evento `open_intent`;
- l'ora del server;
- il percorso della pagina pertinente sul sito;
- il posizionamento del link;
- lo SKU del prodotto ove pertinente;
- la lingua del sito; e
- uno stato di "intento" (`intent`).

Senza il consenso per l'analisi, questo record non deve creare o leggere un identificatore del visitatore/della sessione VJT e non deve contenere un percorso di navigazione ricostruito, l'URL completo del referrer, i parametri della campagna, l'indirizzo IP, lo user agent o la geolocalizzazione. Un trattamento di sicurezza separato e di breve durata può verificarsi per la limitazione della frequenza (rate limiting).

Un record `open_intent` significa solo che il link di contatto del sito web è stato attivato. Non dimostra che un dispositivo abbia aperto con successo WhatsApp o un client e-mail, che il visitatore abbia inviato un messaggio o che Kssmi ne abbia ricevuto uno.

Per un modulo di richiesta, un evento `submission_success` significa che il processo di invio configurato dal sito web ha segnalato l'esito positivo. Non dimostra che un destinatario abbia letto o risposto all'e-mail.

### 2. Tracciamento del percorso del visitatore (VJT)

Con il consenso per l'analisi, il VJT può utilizzare un identificatore del visitatore di prima parte e un identificatore di sessione di breve durata per associare le visite alle pagine e gli eventi di contatto a un unico percorso acconsentito. A seconda della configurazione attiva, i dati del percorso possono includere:

- URL e titoli delle pagine;
- orari di visita e interazione;
- parametri del referrer e della campagna;
- informazioni su browser, dispositivo, schermo, lingua e fuso orario;
- paese o città derivati dall'IP;
- misurazioni di scorrimento e coinvolgimento; e
- attribuzione delle richieste o degli eventi di contatto.

Il percorso di analisi deve rimanere disabilitato finché il visitatore non concede il consenso per l'analisi. In caso di revoca del consenso, la successiva raccolta di dati analitici deve interrompersi e gli identificatori VJT memorizzati nel browser devono essere rimossi in conformità con la procedura di revoca implementata.

### 3. Pubblicità e analisi di terze parti

Google Analytics, Google Ads, Google Tag Manager o tecnologie di misurazione comparabili devono funzionare in base alle categorie di consenso selezionate dal visitatore e alla configurazione effettiva del sito. L'informativa finale deve descrivere solo i prodotti e le funzionalità che sono effettivamente abilitati.

### 4. Cookie e archiviazione nel browser

Ai sistemi del sito descritti nella presente informativa si applicano i seguenti periodi e criteri:

| Nome | Fornitore | Finalità | Categoria | Durata | Tipo di archiviazione |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ricordare le scelte del visitatore relative ad analisi e pubblicità | Necessario | Finché la scelta non viene modificata o la memoria del browser viene cancellata | Local storage |
| `vjt_visitor_id` | Kssmi | Associare le visite acconsentite a un percorso del visitatore | Analisi | Cookie: up to about 365 days; local copy: Fino alla revoca del consenso alle analisi o alla cancellazione della memoria del browser | Cookie e archiviazione locale |
| `vjt_session_id` | Kssmi | Associare gli eventi della pagina acconsentita all'interno di una sessione | Analisi | About 30 minutes | Cookie |
| Altri identificatori Google/di terze parti | Google / relevant third party | Analisi o pubblicità | Analisi/pubblicità | Varia in base al fornitore e alla configurazione | Cookie o tecnologia simile |

L'inventario dei cookie, il banner di consenso e l'implementazione live devono concordare. Rinominare un tracker o spostare un identificatore da un cookie all'archiviazione locale non rende di per sé la tecnologia esente dal consenso.

### 5. Modifica delle scelte di consenso

I visitatori devono essere in grado di riaprire le Impostazioni dei cookie e modificare o revocare il consenso per l'analisi e la pubblicità con la stessa facilità con cui è stato fornito. La revoca non pregiudica il trattamento che era lecito prima della revoca.
