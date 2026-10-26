---
lang: jv
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookie lan acara kontak"
title: "Cookie, analitik lan interaksi kontak"
---

Pangukuran kontak lan analisis perjalanan pengunjung loro-lorone mbutuhake idin analisis. WhatsApp lan email tetep bisa tanpa pelacakan opsional.

### 1. Acara kontak

Mung kanthi idin analisis, milih pranala WhatsApp utawa email bisa nyathet acara sing ngemot:

- saluran sing dipilih;
- jinis acara `open_intent`;
- wektu server;
- jalur kaca ing situs sing relevan;
- penempatan tautan;
- SKU produk sing relevan;
- basa situs; lan
- status "niat" (`intent`).

Tanpa idin analisis, pangukuran kontak ora njaluk wewenang, nyetel cookie pangukuran utawa nyathet pambukaan. Kanthi idin, acara bisa digandhengake karo perjalanan ing ngisor. Pangolahan keamanan kapisah bisa nglindhungi kontak sing dijaluk.

Rekaman `open_intent` mung ateges manawa tautan kontak situs web wis dipicu. Iku ora mbuktekake manawa piranti wis kasil mbukak WhatsApp utawa klien email, yen pengunjung ngirim pesen, utawa yen Kssmi nampa pesen kasebut.

Acara `submission_success` ateges situs wis nampa pitakon ing proses pangiriman, sing bisa kalebu antrean. Iki ora mbuktekake email tekan, ditampa wong tartamtu, diwaca utawa diwales.

### 2. Pelacakan perjalanan pengunjung (VJT)

Kanthi idin analitik, VJT bisa nggunakake pengenal pengunjung pihak pertama lan pengenal sesi jangka pendek kanggo nggandhengake kunjungan kaca lan acara kontak menyang siji perjalanan sing disetujoni. Gumantung ing konfigurasi aktif, data perjalanan bisa ngemot:

- URL lan judhul kaca;
- wektu kunjungan lan interaksi;
- perujuk lan parameter kampanye;
- browser, piranti, layar, basa lan informasi zona wektu;
- negara utawa kutha asal-IP;
- pangukuran nggulung lan keterlibatan; lan
- atribusi pitakon utawa acara kontak.

Perjalanan analitik kudu tetep dipateni nganti pengunjung menehi idin analitik. Saben idin miwiti siklus idin anyar, lan pengenal VJT ora digawa saka siji siklus menyang siklus sabanjure. Nalika idin ditarik, situs mandhegake pangumpulan analitik anyar lan penerbitan pengenal ing browser, mbusak nilai VJT saka panyimpenan browser, lan njaluk server supaya cookie identitas HttpOnly kadaluwarsa. Pambusakan iki dicoba maneh kanthi jumlah winates lan bisa gagal, umpamane yen browser offline utawa panjaluk diblokir; cookie identitas sing isih ana ora digunakake kanggo nerusake perjalanan sadurunge lan diganti yen idin diwenehake maneh. Data sing wis dikirim sadurunge penarikan isih bisa ditampa. Pengenal sing digawe sadurunge siklus idin diwiwiti bakal diganti sapisan. Browser sing ora nduweni fitur sing dibutuhake kanggo ngoordinasi idin antarane tab ora nggunakake VJT.

### 3. Iklan lan analitik pihak katelu

Google Analytics, Google Ads, Google Tag Manager utawa teknologi padha mlaku manut kategori idin sing dipilih lan konfigurasi aktif. Panyimpenan analisis lan iklan tetep ditolak nganti idin sing cocog diwenehake.

### 4. Cookie lan panyimpenan browser

Barang panyimpenan browser utama ana ing ngisor iki. Identifikasi pihak katelu bisa beda manut panyedhiya, browser, pilihan idin lan konfigurasi aktif.

| Jeneng | Panyedhiya | Tujuan | Kategori | Durasi | Jinis panyimpenan |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ngelingi pilihan analitik lan iklan saka pengunjung | Perlu | Nganti pilihan diganti utawa panyimpenan browser dibusak | Panyimpenan lokal |
| `vjt_visitor_id` | Kssmi | Nggandhengake kunjungan sing disetujoni menyang perjalanan pengunjung | Analitik | Cookie: nganti udakara 365 dina; salinan lokal: Nganti idin analitik ditarik utawa panyimpenan browser dibusak | Cookie lan panyimpenan lokal |
| `vjt_session_id` | Kssmi | Nggandhengake acara kaca sing disetujoni sajrone siji sesi | Analitik | Udakara 30 menit | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identitas server sing ditandatangani kanggo nggandhengake kunjungan sing disetujoni sajrone siji siklus idin; ora bisa diwaca skrip kaca | Analitik | Nganti kira-kira 365 dina; kadaluwarsa sawise penarikan yen browser ngidini | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Sesi ditandhatangani kanggo nglindhungi statistik kontak sing diidini, kaiket siklus saiki; mung diterbitake kanthi idin analisis | Analitik | Kira-kira 30 menit, dianyari nalika browsing | Cookie (HttpOnly) |
| Pengenal Google/pihak katelu liyane | Google / pihak katelu sing cocog | Analitik utawa iklan | Analitik/iklan | Beda-beda miturut panyedhiya lan konfigurasi | Cookie utawa teknologi sing padha |

Inventaris cookie, spanduk idin lan implementasi langsung kudu padha. Ngganti jeneng pelacak utawa mindhah pengenal saka cookie menyang panyimpenan lokal kanthi sendirine ora nggawe teknologi kasebut dibebasake saka idin.

### 5. Ngganti pilihan idin

Pengunjung kudu bisa mbukak maneh Pengaturan Cookie lan ngganti utawa narik idin analitik lan iklan kanthi gampang kaya nalika diwenehake. Penarikan ora mengaruhi pangolahan sing sah sadurunge ditarik maneh. Sawise milih, tombol Pengaturan Cookie tetep kasedhiya ing saben kaca.

### 6. Etungan tampilan kaca tanpa jeneng (anonim)
Kajaba saka analitik adhedhasar idin sing diterangake ing ndhuwur, situs web ngetung tampilan kaca kanthi wujud gabungan: kanggo saben dina tanggalan (wektu Beijing) lan path kaca, mung nyimpen gunggunge tampilan. Tabel etungan iki ora nganggo cookie, panyimpenan browser, utawa pengenal pengunjung/sesi, lan ora ngemot alamat IP, agen pangguna, referrer, utawa stempel wektu kunjungan individu. Watesan tingkat, panyaring bot, lan log server minangka operasi keamanan sing kapisah sing diterangake ing bagean dhewe-dhewe; iku dudu bagean saka tabel gabungan iki. Server bisa maca penanda pengecualian admin sing ditandatangani kanthi kapisah supaya ora ngetung lalu lintas admin; penanda kasebut ora disimpen ing tabel gabungan anonim.
