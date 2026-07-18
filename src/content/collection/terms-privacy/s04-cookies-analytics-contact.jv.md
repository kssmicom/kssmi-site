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

Kssmi misahake fungsi acara kontak minimal saka fungsi analitik perjalanan pengunjung adhedhasar idin. Kabeh kasebut nduweni tujuan sing beda-beda lan ora kudu diterangake minangka siji sistem kanthi siji dhasar hukum.

### 1. Acara kontak

Yen pengunjung kanthi sengaja milih tautan WhatsApp utawa email, situs web bisa nyathet acara minimal sing nuduhake yen titik entri kontak wis dibukak. Tanpa idin analitik, acara iki dirancang supaya mung ngemot:

- saluran sing dipilih;
- jinis acara `open_intent`;
- wektu server;
- jalur kaca ing situs sing relevan;
- penempatan tautan;
- SKU produk sing relevan;
- basa situs; lan
- status "niat" (`intent`).

Tanpa idin analitik, rekaman iki ora kudu nggawe utawa maca pengenal pengunjung/sesi VJT lan ora kudu ngemot perjalanan telusuran sing direkonstruksi, URL perujuk lengkap, parameter kampanye, alamat IP, agen pangguna utawa geolokasi. Pangolahan keamanan sing beda lan jangka pendek bisa kedadeyan kanggo mbatesi tarif.

Rekaman `open_intent` mung ateges manawa tautan kontak situs web wis dipicu. Iku ora mbuktekake manawa piranti wis kasil mbukak WhatsApp utawa klien email, yen pengunjung ngirim pesen, utawa yen Kssmi nampa pesen kasebut.

Kanggo formulir pitakon, acara `submission_success` tegese proses pangiriman sing dikonfigurasi dening situs web nglaporake sukses. Iku ora mbuktekake manawa sing nampa pesen maca utawa mangsuli email kasebut.

### 2. Pelacakan perjalanan pengunjung (VJT)

Kanthi idin analitik, VJT bisa nggunakake pengenal pengunjung pihak pertama lan pengenal sesi jangka pendek kanggo nggandhengake kunjungan kaca lan acara kontak menyang siji perjalanan sing disetujoni. Gumantung ing konfigurasi aktif, data perjalanan bisa ngemot:

- URL lan judhul kaca;
- wektu kunjungan lan interaksi;
- perujuk lan parameter kampanye;
- browser, piranti, layar, basa lan informasi zona wektu;
- negara utawa kutha asal-IP;
- pangukuran nggulung lan keterlibatan; lan
- atribusi pitakon utawa acara kontak.

Perjalanan analitik kudu tetep dipateni nganti pengunjung menehi idin analitik. Yen idin ditarik maneh, koleksi analitik sakteruse kudu mandheg lan pengenal VJT sing disimpen ing browser kudu dibusak sesuai karo proses penarikan sing dileksanakake.

### 3. Iklan lan analitik pihak katelu

Google Analytics, Google Ads, Google Tag Manager utawa teknologi pangukuran sing bisa dibandhingake kudu digunakake miturut kategori idin sing dipilih pengunjung lan konfigurasi situs sing bener. Kabar pungkasan mung kudu nerangake produk lan fitur sing bener-bener diaktifake.

### 4. Cookie lan panyimpenan browser

Wektu lan kritéria ing ngisor iki ditrapake kanggo sistem situs web sing diterangake ing kabar iki:

| Jeneng | Panyedhiya | Tujuan | Kategori | Durasi | Jinis panyimpenan |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ngelingi pilihan analitik lan iklan saka pengunjung | Perlu | Nganti pilihan diganti utawa panyimpenan browser dibusak | Local storage |
| `vjt_visitor_id` | Kssmi | Nggandhengake kunjungan sing disetujoni menyang perjalanan pengunjung | Analitik | Cookie: up to about 365 days; local copy: Nganti idin analitik ditarik utawa panyimpenan browser dibusak | Cookie lan panyimpenan lokal |
| `vjt_session_id` | Kssmi | Nggandhengake acara kaca sing disetujoni sajrone siji sesi | Analitik | About 30 minutes | Cookie |
| Pengenal Google/pihak katelu liyane | Google / relevant third party | Analitik utawa iklan | Analitik/iklan | Beda-beda miturut panyedhiya lan konfigurasi | Cookie utawa teknologi sing padha |

Inventaris cookie, spanduk idin lan implementasi langsung kudu padha. Ngganti jeneng pelacak utawa mindhah pengenal saka cookie menyang panyimpenan lokal kanthi sendirine ora nggawe teknologi kasebut dibebasake saka idin.

### 5. Ngganti pilihan idin

Pengunjung kudu bisa mbukak maneh Pengaturan Cookie lan ngganti utawa narik idin analitik lan iklan kanthi gampang kaya nalika diwenehake. Penarikan ora mengaruhi pangolahan sing sah sadurunge ditarik maneh.
