---
lang: de
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookies und Kontaktereignisse"
title: "Cookies, Analytik und Kontaktinteraktionen"
---

Kssmi trennt eine minimale Kontaktereignisfunktion von der einwilligungsbasierten Analytikfunktion für die Besucher-Journey. Sie dienen unterschiedlichen Zwecken und dürfen nicht als ein System mit einer einzigen Rechtsgrundlage beschrieben werden.

### 1. Kontaktereignisse

Wenn ein Besucher bewusst einen WhatsApp- oder E-Mail-Link auswählt, kann die Website ein minimales Ereignis aufzeichnen, das zeigt, dass der Kontakt-Einstiegspunkt geöffnet wurde. Ohne Einwilligung zur Analyse ist dieses Ereignis so konzipiert, dass es nur Folgendes enthält:

- den ausgewählten Kanal;
- einen Ereignistyp `open_intent`;
- die Serverzeit;
- den relevanten On-Site-Seitenpfad;
- die Linkplatzierung;
- die Produkt-SKU, sofern relevant;
- die Site-Sprache; und
- einen „Absichts“-Status (`intent`).

Ohne Analyse-Einwilligung darf dieser Datensatz keine VJT-Besucher-/Sitzungskennung (Visitor Journey Tracking) erstellen oder lesen und keinen rekonstruierten Browserverlauf, keine vollständige Referrer-URL, keine Kampagnenparameter, keine IP-Adresse, keinen User-Agent oder keine Geolokalisierung enthalten. Eine separate, kurzlebige Sicherheitsverarbeitung kann zur Ratenbegrenzung erfolgen.

Ein `open_intent`-Datensatz bedeutet nur, dass der Website-Kontaktlink ausgelöst wurde. Er beweist nicht, dass ein Gerät WhatsApp oder einen E-Mail-Client erfolgreich geöffnet hat, dass der Besucher eine Nachricht gesendet hat oder dass Kssmi eine erhalten hat.

Bei einem Anfrageformular bedeutet ein `submission_success`-Ereignis, dass der konfigurierte Sendevorgang der Website erfolgreich gemeldet wurde. Dies ist kein Beweis dafür, dass ein Empfänger die E-Mail gelesen oder beantwortet hat.

### 2. Visitor Journey Tracking (VJT)

Mit der Einwilligung zur Analyse kann VJT eine First-Party-Besucherkennung und eine kurzlebige Sitzungskennung verwenden, um Seitenbesuche und Kontaktereignisse mit einer mit Einwilligung durchgeführten Journey zu verknüpfen. Abhängig von der aktiven Konfiguration können Journey-Daten Folgendes umfassen:

- Seiten-URLs und Titel;
- Besuchs- und Interaktionszeiten;
- Referrer- und Kampagnenparameter;
- Browser-, Geräte-, Bildschirm-, Sprach- und Zeitzoneninformationen;
- vom IP abgeleitetes Land oder Stadt;
- Scroll- und Engagement-Messungen; und
- Attribution von Anfragen oder Kontaktereignissen.

Die Analyse-Journey muss deaktiviert bleiben, bis der Besucher seine Einwilligung zur Analyse erteilt. Wenn die Einwilligung widerrufen wird, muss die anschließende Analyseerfassung gestoppt und die im Browser gespeicherten VJT-Kennungen müssen gemäß dem implementierten Widerrufsverfahren entfernt werden.

### 3. Werbung und Drittanbieter-Analysen

Google Analytics, Google Ads, Google Tag Manager oder vergleichbare Messtechnologien werden gemäß den vom Besucher ausgewählten Einwilligungskategorien und der aktuell aktivierten Website-Konfiguration betrieben. Analyse- und Werbespeicher bleiben verweigert, bis die entsprechende Einwilligung erteilt wurde.

### 4. Cookies und Browser-Speicher

Die wichtigsten von der Website verwendeten Browser-Speicherelemente sind nachstehend aufgeführt. Kennungen Dritter können je nach Anbieter, Browser, Einwilligungsauswahl und aktueller Konfiguration variieren.

| Name | Anbieter | Zweck | Kategorie | Dauer | Speichertyp |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Speichert die Auswahl des Besuchers für notwendige, Analyse- und Werbekategorien | Notwendig | Bis die Auswahl geändert oder der Browser-Speicher gelöscht wird | Lokaler Speicher |
| `vjt_visitor_id` | Kssmi | Verknüpfung der Besuche, denen zugestimmt wurde, mit einer Besucher-Journey | Analyse | Cookie bis zu ca. 365 Tage; lokale Kopie bis zum Widerruf der Analyse-Einwilligung oder Löschen des Browser-Speichers | Cookie und lokaler Speicher |
| `vjt_session_id` | Kssmi | Verknüpfung der Seitenereignisse, denen zugestimmt wurde, innerhalb einer Sitzung | Analyse | Ca. 30 Minuten | Cookie |
| Google- oder andere konfigurierte Drittanbieter-Kennungen | Jeweiliger Anbieter | Einwilligungsbasierte Analyse, Messung oder Werbung | Analyse oder Werbung | Variiert je nach Anbieter und Konfiguration; siehe Angaben des jeweiligen Anbieters | Cookie oder ähnliche Technologie |

Das Cookie-Inventar, das Einwilligungs-Banner und die Live-Implementierung müssen übereinstimmen. Das Umbenennen eines Trackers oder das Verschieben einer Kennung von einem Cookie in den lokalen Speicher macht die Technologie an sich nicht von der Einwilligung befreit.

### 5. Änderung der Einwilligungsentscheidungen

Besucher müssen in der Lage sein, die Cookie-Einstellungen erneut zu öffnen und die Einwilligung zur Analyse und Werbung genauso einfach zu ändern oder zu widerrufen, wie sie erteilt wurde. Der Widerruf berührt nicht die Rechtmäßigkeit der vor dem Widerruf erfolgten Verarbeitung.
