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

Kssmi memisahkan fungsi acara kenalan yang minimum daripada fungsi analitik perjalanan pelawat berasaskan persetujuan. Mereka mempunyai tujuan yang berbeza dan tidak boleh digambarkan sebagai satu sistem dengan satu asas undang-undang.

### 1. Acara kenalan

Apabila pelawat sengaja memilih pautan WhatsApp atau e-mel, tapak web mungkin merekodkan peristiwa minimum yang menunjukkan bahawa titik kemasukan kenalan dibuka. Tanpa persetujuan analitis, acara ini direka bentuk untuk mengandungi sahaja:

- saluran yang dipilih;
- jenis acara `open_intent`;
- masa pelayan;
- laluan halaman di tapak yang berkaitan;
- peletakan pautan;
- SKU produk di mana relevan;
- bahasa tapak; dan
- status "niat" (`intent`).

Tanpa kebenaran analitik, rekod ini tidak boleh membuat atau membaca pengecam pelawat/sesi VJT dan tidak boleh mengandungi perjalanan penyemakan imbas yang dibina semula, URL perujuk penuh, parameter kempen, alamat IP, ejen pengguna atau geolokasi. Pemprosesan keselamatan jangka pendek yang berasingan mungkin berlaku untuk pengehadan kadar.

Rekod `open_intent` hanya bermakna pautan kenalan tapak web telah dicetuskan. Ia tidak membuktikan peranti berjaya membuka WhatsApp atau pelanggan e-mel, pelawat menghantar mesej, atau Kssmi menerimanya.

Untuk borang pertanyaan, peristiwa `submission_success` bermakna proses penghantaran yang dikonfigurasikan oleh tapak web melaporkan kejayaan. Ia tidak membuktikan bahawa penerima membaca atau membalas e-mel tersebut.

### 2. Penjejakan perjalanan pelawat (VJT)

Dengan kebenaran analitis, VJT mungkin menggunakan pengecam pelawat pihak pertama dan pengecam sesi jangka pendek untuk mengaitkan lawatan halaman dan acara kenalan dengan satu perjalanan yang dipersetujui. Bergantung pada konfigurasi aktif, data perjalanan mungkin termasuk:

- URL dan tajuk halaman;
- masa melawat dan interaksi;
- perujuk dan parameter kempen;
- pelayar, peranti, skrin, bahasa dan maklumat zon masa;
- negara atau bandar yang diperoleh daripada IP;
- ukuran tatal dan penglibatan; dan
- kaitan acara pertanyaan atau kenalan.

Perjalanan analitis mesti kekal dilumpuhkan sehingga pelawat memberikan kebenaran analitis. Jika persetujuan ditarik balik, pengumpulan analitis seterusnya mesti dihentikan dan pengecam VJT yang disimpan dalam penyemak imbas mesti dialih keluar mengikut proses pengeluaran yang dilaksanakan.

### 3. Pengiklanan dan analisis pihak ketiga

Analitis Google, Iklan Google, Pengurus Teg Google atau teknologi pengukuran setanding mesti beroperasi mengikut kategori persetujuan pilihan pelawat dan konfigurasi sebenar tapak. Notis akhir mesti menerangkan hanya produk dan ciri yang benar-benar didayakan.

### 4. Kuki dan storan penyemak imbas

Tempoh dan kriteria berikut terpakai pada sistem laman web yang diterangkan dalam notis ini:

| Nama | Penyedia | Tujuan | Kategori | Tempoh | Jenis storan |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ingat pilihan analitis dan pengiklanan pelawat | Perlu | Sehingga pilihan diubah atau storan pelayar dikosongkan | Local storage |
| `vjt_visitor_id` | Kssmi | Mengaitkan lawatan yang dipersetujui dengan perjalanan pelawat | Analitis | Cookie: up to about 365 days; local copy: Sehingga persetujuan analitik ditarik balik atau storan pelayar dikosongkan | Kuki dan storan tempatan |
| `vjt_session_id` | Kssmi | Mengaitkan acara halaman yang dipersetujui dalam satu sesi | Analitis | About 30 minutes | Kuki |
| Pengecam Google/pihak ketiga lain | Google / relevant third party | Analitis atau pengiklanan | Analitis/pengiklanan | Berbeza mengikut penyedia dan konfigurasi | Kuki atau teknologi yang serupa |

Inventori kuki, sepanduk persetujuan dan pelaksanaan langsung mesti bersetuju. Menamakan semula penjejak atau mengalihkan pengecam daripada kuki ke storan tempatan tidak semestinya menjadikan teknologi itu dikecualikan dengan sendirinya.

### 5. Menukar pilihan kebenaran

Pelawat mesti boleh membuka semula Tetapan Kuki dan menukar atau menarik balik persetujuan analitis dan pengiklanan semudah yang diberikan. Penarikan balik tidak menjejaskan pemprosesan yang sah sebelum penarikan balik.
