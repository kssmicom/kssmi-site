---
lang: en
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookies and contact events"
title: "Cookies, analytics and contact interactions"
---

Kssmi separates a minimal contact-event function from the consent-based visitor-journey analytics function. They serve different purposes and must not be described as one system with one legal basis.

### 1. Contact events

When a visitor deliberately selects a WhatsApp or email link, the website may record a minimal event showing that the contact entry point was opened. Without analytics consent, this event is designed to contain only:

- the selected channel;
- an `open_intent` event type;
- server time;
- the relevant on-site page path;
- link placement;
- product SKU where relevant;
- site language; and
- an `intent` status.

Without analytics consent, this record must not create or read a VJT visitor/session identifier and must not contain a reconstructed browsing journey, full referrer URL, campaign parameters, IP address, user agent or geolocation. Separate short-lived security processing may occur for rate limiting.

An `open_intent` record means only that the website contact link was triggered. It does not prove that a device successfully opened WhatsApp or an email client, that the visitor sent a message, or that Kssmi received one.

For an inquiry form, a `submission_success` event means that the website's configured sending process reported success. It does not prove that a recipient read or replied to the email.

### 2. Visitor journey tracking (VJT)

With analytics consent, VJT may use a first-party visitor identifier and a short-lived session identifier to associate page visits and contact events with one consented journey. Depending on the active configuration, journey data may include:

- page URLs and titles;
- visit and interaction times;
- referrer and campaign parameters;
- browser, device, screen, language and time-zone information;
- IP-derived country or city;
- scroll and engagement measurements; and
- inquiry or contact-event attribution.

The analytics journey must remain disabled until the visitor grants analytics consent. If consent is withdrawn, subsequent analytics collection must stop and VJT identifiers stored in the browser must be removed in accordance with the implemented withdrawal process.

### 3. Advertising and third-party analytics

Google Analytics, Google Ads, Google Tag Manager or comparable measurement technology operates according to the visitor's selected consent categories and the site's active configuration. Analytics and advertising storage remain denied until the corresponding consent is granted.

### 4. Cookies and browser storage

The principal browser-storage items used by the website are listed below. Third-party identifiers may vary by provider, browser, consent choice and active configuration.

| Name | Provider | Purpose | Category | Duration | Storage type |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Remember the visitor's necessary, analytics and advertising choices | Necessary | Until the choice is changed or browser storage is cleared | Local storage |
| `vjt_visitor_id` | Kssmi | Associate consented visits with a visitor journey | Analytics | Cookie: up to approximately 365 days; local storage: until consent is withdrawn or browser storage is cleared | Cookie and local storage |
| `vjt_session_id` | Kssmi | Associate consented page events within a session | Analytics | Approximately 30 minutes | Cookie |
| Google or other configured third-party identifiers | Relevant provider | Consent-based analytics, measurement or advertising | Analytics or advertising | Varies by provider and configuration; see the relevant provider's information | Cookie or similar technology |

The cookie inventory, consent banner and live implementation must agree. Renaming a tracker or moving an identifier from a cookie to local storage does not by itself make the technology consent-exempt.

### 5. Changing consent choices

Visitors must be able to reopen Cookie Settings and change or withdraw analytics and advertising consent as easily as it was given. Withdrawal does not affect processing that was lawful before withdrawal.

### 6. Anonymous page-view counting
Separately from the consent-based analytics described above, the website counts page views in an aggregated form: for each calendar day (Beijing time) and page path it stores only the total number of views. This count table uses no cookies, no browser storage and no visitor or session identifiers, and it does not contain IP addresses, user agents, referrers or individual visit timestamps. Rate limiting, bot filtering and server logs are separate security operations described in their own sections; they are not part of this aggregate table. The server may separately read a signed administrator-exclusion marker to avoid counting admin traffic; that marker is not stored in the anonymous aggregate table.
