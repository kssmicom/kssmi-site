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

Kontaktmessung und Besucheranalyse benötigen beide die Analyse-Einwilligung. WhatsApp und E-Mail bleiben ohne optionales Tracking nutzbar.

### 1. Kontaktereignisse

Nur mit Analyse-Einwilligung kann die Auswahl eines WhatsApp- oder E-Mail-Links ein Ereignis mit folgenden Daten erfassen:

- den ausgewählten Kanal;
- einen Ereignistyp `open_intent`;
- die Serverzeit;
- den relevanten On-Site-Seitenpfad;
- die Linkplatzierung;
- die Produkt-SKU, sofern relevant;
- die Site-Sprache; und
- einen „Absichts“-Status (`intent`).

Ohne Analyse-Einwilligung fordert die Kontaktmessung keine Berechtigungen an, setzt kein Mess-Cookie und erfasst keine Öffnungsstatistik. Mit Einwilligung kann das Ereignis der unten beschriebenen Besuchsfolge zugeordnet werden. Eine getrennte Sicherheitsverarbeitung kann den angeforderten Kontakt schützen.

Ein `open_intent`-Datensatz bedeutet nur, dass der Website-Kontaktlink ausgelöst wurde. Er beweist nicht, dass ein Gerät WhatsApp oder einen E-Mail-Client erfolgreich geöffnet hat, dass der Besucher eine Nachricht gesendet hat oder dass Kssmi eine erhalten hat.

Ein `submission_success`-Ereignis bedeutet, dass die Website die Anfrage in ihren Versandprozess einschließlich einer möglichen Warteschlange aufgenommen hat. Es bestätigt weder die E-Mail-Zustellung noch den Empfang durch eine bestimmte Person, das Lesen oder eine Antwort.

### 2. Visitor Journey Tracking (VJT)

Mit der Einwilligung zur Analyse kann VJT eine First-Party-Besucherkennung und eine kurzlebige Sitzungskennung verwenden, um Seitenbesuche und Kontaktereignisse mit einer mit Einwilligung durchgeführten Journey zu verknüpfen. Abhängig von der aktiven Konfiguration können Journey-Daten Folgendes umfassen:

- Seiten-URLs und Titel;
- Besuchs- und Interaktionszeiten;
- Referrer- und Kampagnenparameter;
- Browser-, Geräte-, Bildschirm-, Sprach- und Zeitzoneninformationen;
- vom IP abgeleitetes Land oder Stadt;
- Scroll- und Engagement-Messungen; und
- Attribution von Anfragen oder Kontaktereignissen.

Die Analyse-Journey muss deaktiviert bleiben, bis der Besucher in die Analyse einwilligt. Jede Einwilligung beginnt einen neuen Einwilligungszyklus; VJT-Kennungen werden nicht von einem Zyklus in den nächsten übernommen. Wird die Einwilligung widerrufen, beendet die Website im Browser die weitere Analyseerfassung und die Ausgabe neuer Kennungen, entfernt VJT-Werte aus dem Browserspeicher und fordert den Server auf, das HttpOnly-Identitäts-Cookie ablaufen zu lassen. Diese Löschung wird begrenzt oft wiederholt und kann fehlschlagen, etwa wenn der Browser offline ist oder die Anfrage blockiert wird; ein verbleibendes Identitäts-Cookie wird nicht zur Fortsetzung der früheren Journey verwendet und bei einer erneuten Einwilligung ersetzt. Bereits vor dem Widerruf gesendete Daten können noch empfangen werden. Kennungen aus der Zeit vor Einführung der Einwilligungszyklen werden einmalig ersetzt. Browser ohne die Funktionen, die zur Abstimmung der Einwilligung über mehrere Tabs nötig sind, verwenden VJT nicht.

### 3. Werbung und Drittanbieter-Analysen

Google Analytics, Google Ads, Google Tag Manager oder vergleichbare Messtechnologien werden gemäß den vom Besucher ausgewählten Einwilligungskategorien und der aktuell aktivierten Website-Konfiguration betrieben. Analyse- und Werbespeicher bleiben verweigert, bis die entsprechende Einwilligung erteilt wurde.

### 4. Cookies und Browser-Speicher

Die wichtigsten von der Website verwendeten Browser-Speicherelemente sind nachstehend aufgeführt. Kennungen Dritter können je nach Anbieter, Browser, Einwilligungsauswahl und aktueller Konfiguration variieren.

| Name | Anbieter | Zweck | Kategorie | Dauer | Speichertyp |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Speichert die Auswahl des Besuchers für notwendige, Analyse- und Werbekategorien | Notwendig | Bis die Auswahl geändert oder der Browser-Speicher gelöscht wird | Lokaler Speicher |
| `vjt_visitor_id` | Kssmi | Verknüpfung der Besuche, denen zugestimmt wurde, mit einer Besucher-Journey | Analyse | Cookie bis zu ca. 365 Tage; lokale Kopie bis zum Widerruf der Analyse-Einwilligung oder Löschen des Browser-Speichers | Cookie und lokaler Speicher |
| `vjt_session_id` | Kssmi | Verknüpfung der Seitenereignisse, denen zugestimmt wurde, innerhalb einer Sitzung | Analyse | Ca. 30 Minuten | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Signierte Server-Identität, die Besuche mit Einwilligung innerhalb eines Einwilligungszyklus verknüpft; für Seitenskripte nicht lesbar | Analyse | Bis zu ca. 365 Tage; läuft nach einem Widerruf ab, soweit der Browser dies zulässt | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Signierte Sitzung zum Schutz eingewilligter Kontaktstatistiken, an den aktuellen Einwilligungszyklus gebunden; nur mit Analyse-Einwilligung ausgestellt | Analyse | Ca. 30 Minuten, wird während des Besuchs verlängert | Cookie (HttpOnly) |
| Google- oder andere konfigurierte Drittanbieter-Kennungen | Jeweiliger Anbieter | Einwilligungsbasierte Analyse, Messung oder Werbung | Analyse oder Werbung | Variiert je nach Anbieter und Konfiguration; siehe Angaben des jeweiligen Anbieters | Cookie oder ähnliche Technologie |

Das Cookie-Inventar, das Einwilligungs-Banner und die Live-Implementierung müssen übereinstimmen. Das Umbenennen eines Trackers oder das Verschieben einer Kennung von einem Cookie in den lokalen Speicher macht die Technologie an sich nicht von der Einwilligung befreit.

### 5. Änderung der Einwilligungsentscheidungen

Besucher müssen in der Lage sein, die Cookie-Einstellungen erneut zu öffnen und die Einwilligung zur Analyse und Werbung genauso einfach zu ändern oder zu widerrufen, wie sie erteilt wurde. Der Widerruf berührt nicht die Rechtmäßigkeit der vor dem Widerruf erfolgten Verarbeitung. Nachdem eine Auswahl getroffen wurde, bleibt auf jeder Seite eine Schaltfläche für die Cookie-Einstellungen verfügbar.

### 6. Anonyme Seitenaufruf-Zählung
Unabhängig von der oben beschriebenen einwilligungsbasierten Analyse zählt die Website Seitenaufrufe in aggregierter Form: Für jeden Kalendertag (Pekinger Zeit) und jeden Seitenpfad wird nur die Gesamtzahl der Aufrufe gespeichert. Diese Zähltabelle verwendet keine Cookies, keinen Browser-Speicher und keine Besucher- oder Sitzungskennungen und enthält weder IP-Adressen, User-Agents, Referrer noch einzelne Besuchszeitstempel. Ratenbegrenzung, Bot-Filterung und Server-Logs sind getrennte Sicherheitsvorgänge, die in ihren eigenen Abschnitten beschrieben sind und nicht Teil dieser Aggregattabelle sind. Der Server kann separat ein signiertes Administrations-Ausschlusskennzeichen lesen, um Administrationsverkehr nicht zu zählen; dieses Kennzeichen wird nicht in der anonymen Aggregattabelle gespeichert.
