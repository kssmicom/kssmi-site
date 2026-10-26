---
lang: ms
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Kuki dan acara kenalan"
title: "Kuki, analitis dan interaksi kenalan"
---

Pengukuran hubungan dan analisis perjalanan kedua-duanya memerlukan persetujuan analisis. WhatsApp dan e-mel kekal tersedia tanpa penjejakan pilihan.

### 1. Acara kenalan

Hanya dengan persetujuan analisis, memilih pautan WhatsApp atau e-mel boleh merekod peristiwa yang mengandungi:

- saluran yang dipilih;
- jenis acara `open_intent`;
- masa pelayan;
- laluan halaman di tapak yang berkaitan;
- peletakan pautan;
- SKU produk di mana relevan;
- bahasa tapak; dan
- status "niat" (`intent`).

Tanpa persetujuan analisis, pengukuran hubungan tidak meminta token, menetapkan kuki ukuran atau merekod statistik buka. Dengan persetujuan, peristiwa boleh dikaitkan dengan perjalanan di bawah. Pemprosesan keselamatan berasingan boleh melindungi hubungan diminta.

Rekod `open_intent` hanya bermakna pautan kenalan tapak web telah dicetuskan. Ia tidak membuktikan peranti berjaya membuka WhatsApp atau pelanggan e-mel, pelawat menghantar mesej, atau Kssmi menerimanya.

Peristiwa `submission_success` bermaksud laman menerima pertanyaan ke proses penghantaran yang mungkin termasuk baris gilir. Ia tidak membuktikan e-mel dihantar, diterima individu tertentu, dibaca atau dibalas.

### 2. Penjejakan perjalanan pelawat (VJT)

Dengan kebenaran analitis, VJT mungkin menggunakan pengecam pelawat pihak pertama dan pengecam sesi jangka pendek untuk mengaitkan lawatan halaman dan acara kenalan dengan satu perjalanan yang dipersetujui. Bergantung pada konfigurasi aktif, data perjalanan mungkin termasuk:

- URL dan tajuk halaman;
- masa melawat dan interaksi;
- perujuk dan parameter kempen;
- pelayar, peranti, skrin, bahasa dan maklumat zon masa;
- negara atau bandar yang diperoleh daripada IP;
- ukuran tatal dan penglibatan; dan
- kaitan acara pertanyaan atau kenalan.

Perjalanan analitis mesti kekal dilumpuhkan sehingga pelawat memberikan persetujuan analitis. Setiap persetujuan memulakan kitaran persetujuan baharu, dan pengecam VJT tidak dibawa dari satu kitaran ke kitaran seterusnya. Apabila persetujuan ditarik balik, laman web menghentikan pengumpulan analitis baharu dan pengeluaran pengecam dalam pelayar, memadam nilai VJT daripada storan pelayar dan meminta pelayan supaya kuki identiti HttpOnly tamat tempoh. Pemadaman ini dicuba semula untuk bilangan kali yang terhad dan mungkin gagal, contohnya apabila pelayar luar talian atau permintaan disekat; kuki identiti yang masih tinggal tidak digunakan untuk meneruskan perjalanan sebelumnya dan akan diganti jika persetujuan diberikan semula. Data yang telah dihantar sebelum penarikan balik masih boleh diterima. Pengecam yang dicipta sebelum kitaran persetujuan diperkenalkan akan diganti sekali. Pelayar yang tidak mempunyai ciri yang diperlukan untuk menyelaraskan persetujuan merentas tab tidak menggunakan VJT.

### 3. Pengiklanan dan analisis pihak ketiga

Google Analytics, Google Ads, Google Tag Manager atau teknologi setanding beroperasi mengikut kategori persetujuan dipilih dan konfigurasi aktif. Penyimpanan analisis dan pengiklanan kekal ditolak sehingga persetujuan berkaitan diberikan.

### 4. Kuki dan storan penyemak imbas

Item penyimpanan pelayar utama disenaraikan di bawah. Pengecam pihak ketiga mungkin berbeza mengikut penyedia, pelayar, persetujuan dan konfigurasi aktif.

| Nama | Penyedia | Tujuan | Kategori | Tempoh | Jenis storan |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ingat pilihan analitis dan pengiklanan pelawat | Perlu | Sehingga pilihan diubah atau storan pelayar dikosongkan | Penyimpanan tempatan |
| `vjt_visitor_id` | Kssmi | Mengaitkan lawatan yang dipersetujui dengan perjalanan pelawat | Analitis | Kuki: sehingga kira-kira 365 hari; salinan tempatan: Sehingga persetujuan analitik ditarik balik atau storan pelayar dikosongkan | Kuki dan storan tempatan |
| `vjt_session_id` | Kssmi | Mengaitkan acara halaman yang dipersetujui dalam satu sesi | Analitis | Kira-kira 30 minit | Kuki |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identiti pelayan bertandatangan yang mengaitkan lawatan yang dipersetujui dalam satu kitaran persetujuan; tidak boleh dibaca oleh skrip halaman | Analitis | Sehingga kira-kira 365 hari; tamat tempoh selepas penarikan balik jika pelayar membenarkan | Kuki (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Sesi bertandatangan melindungi statistik hubungan dipersetujui, terikat kitaran semasa; dikeluarkan hanya dengan persetujuan analisis | Analitis | Kira-kira 30 minit, diperbaharui semasa menyemak imbas | Kuki (HttpOnly) |
| Pengecam Google/pihak ketiga lain | Google / pihak ketiga berkaitan | Analitis atau pengiklanan | Analitis/pengiklanan | Berbeza mengikut penyedia dan konfigurasi | Kuki atau teknologi yang serupa |

Inventori kuki, sepanduk persetujuan dan pelaksanaan langsung mesti bersetuju. Menamakan semula penjejak atau mengalihkan pengecam daripada kuki ke storan tempatan tidak semestinya menjadikan teknologi itu dikecualikan dengan sendirinya.

### 5. Menukar pilihan kebenaran

Pelawat mesti boleh membuka semula Tetapan Kuki dan menukar atau menarik balik persetujuan analitis dan pengiklanan semudah yang diberikan. Penarikan balik tidak menjejaskan pemprosesan yang sah sebelum penarikan balik. Selepas pilihan dibuat, butang Tetapan Kuki kekal tersedia pada setiap halaman.

### 6. Pengiraan paparan halaman tanpa nama
Berasingan daripada analitik berasaskan persetujuan yang diterangkan di atas, laman web mengira paparan halaman dalam bentuk agregat: bagi setiap hari kalendar (waktu Beijing) dan laluan halaman, ia hanya menyimpan jumlah paparan. Jadual pengiraan ini tidak menggunakan kuki, storan penyemak imbas atau pengecam pelawat/sesi, dan tidak mengandungi alamat IP, agen pengguna, perujuk atau cap masa lawatan individu. Had kadar, penapisan bot dan log pelayan ialah operasi keselamatan berasingan yang diterangkan dalam bahagian masing-masing; ia bukan sebahagian daripada jadual agregat ini. Pelayan boleh membaca secara berasingan penanda pengecualian pentadbir yang ditandatangani untuk mengelak daripada mengira trafik pentadbir; penanda itu tidak disimpan dalam jadual agregat tanpa nama.
