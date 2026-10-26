---
lang: tr
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Çerezler ve iletişim etkinlikleri"
title: "Çerezler, analitik ve iletişim etkileşimleri"
---

İletişim ölçümü ve ziyaretçi yolculuğu analizi, analiz rızası gerektirir. WhatsApp ve e-posta isteğe bağlı takip olmadan kullanılabilir.

### 1. İletişim etkinlikleri

Yalnızca analiz rızasıyla WhatsApp veya e-posta bağlantısı seçimi şu verilerle olay kaydedebilir:

- seçilen kanal;
- bir `open_intent` olay türü;
- sunucu saati;
- ilgili site içi sayfa yolu;
- bağlantı yerleşimi;
- ilgili durumlarda ürün SKU'su;
- site dili; ve
- bir niyet (`intent`) durumu.

Analiz rızası olmadan iletişim ölçümü yetki istemez, ölçüm çerezi ayarlamaz veya açılış istatistiği kaydetmez. Rızayla olay aşağıdaki yolculuğa bağlanabilir. Ayrı güvenlik işlemesi istenen iletişimi koruyabilir.

Bir `open_intent` kaydı, yalnızca web sitesi iletişim bağlantısının tetiklendiği anlamına gelir. Bir cihazın WhatsApp'ı veya bir e-posta istemcisini başarıyla açtığını, ziyaretçinin bir mesaj gönderdiğini veya Kssmi'nin bir mesaj aldığını kanıtlamaz.

`submission_success`, sitenin başvuruyu kuyruk içerebilen gönderim sürecine kabul ettiğini belirtir. E-postanın teslim edildiğini, belirli kişiye ulaştığını, okunduğunu veya yanıtlandığını göstermez.

### 2. Ziyaretçi yolculuğu izleme (VJT)

Analitik onayı ile VJT, sayfa ziyaretlerini ve iletişim etkinliklerini onaylanmış tek bir yolculukla ilişkilendirmek için birinci taraf bir ziyaretçi tanımlayıcısı ve kısa ömürlü bir oturum tanımlayıcısı kullanabilir. Etkin yapılandırmaya bağlı olarak yolculuk verileri şunları içerebilir:

- sayfa URL'leri ve başlıkları;
- ziyaret ve etkileşim süreleri;
- yönlendiren ve kampanya parametreleri;
- tarayıcı, cihaz, ekran, dil ve saat dilimi bilgileri;
- IP'den türetilmiş ülke veya şehir;
- kaydırma ve etkileşim ölçümleri; ve
- sorgu veya iletişim etkinliği ilişkilendirmesi.

Ziyaretçi analitik onayı verene kadar analitik yolculuk devre dışı kalmalıdır. Her onay yeni bir onay döngüsü başlatır ve VJT tanımlayıcıları bir döngüden diğerine taşınmaz. Onay geri çekildiğinde web sitesi tarayıcıda yeni analitik toplamayı ve tanımlayıcı düzenlemeyi durdurur, VJT değerlerini tarayıcı depolamasından siler ve sunucudan HttpOnly kimlik çerezinin süresini sona erdirmesini ister. Bu silme işlemi sınırlı sayıda yeniden denenir ve örneğin tarayıcı çevrimdışıysa veya istek engellenirse başarısız olabilir; kalan bir kimlik çerezi önceki yolculuğu sürdürmek için kullanılmaz ve onay yeniden verilirse değiştirilir. Geri çekmeden önce gönderilmiş veriler yine de alınabilir. Onay döngüleri kullanılmaya başlanmadan önce oluşturulan tanımlayıcılar bir kez değiştirilir. Onayı sekmeler arasında koordine etmek için gereken özelliklere sahip olmayan tarayıcılar VJT kullanmaz.

### 3. Reklam ve üçüncü taraf analitiği

Google Analytics, Google Ads, Google Tag Manager veya benzer teknolojiler seçilen rıza kategorileri ve sitenin etkin yapılandırmasına göre çalışır. Analiz ve reklam depolaması ilgili rıza verilene kadar reddedilmiş kalır.

### 4. Çerezler ve tarayıcı depolama

Başlıca tarayıcı depolama öğeleri aşağıdadır. Üçüncü taraf tanımlayıcıları sağlayıcıya, tarayıcıya, rıza seçimine ve etkin yapılandırmaya göre değişir.

| Ad | Sağlayıcı | Amaç | Kategori | Süre | Depolama türü |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ziyaretçinin analitik ve reklam tercihlerini hatırlamak | Gerekli | Seçim değiştirilene veya tarayıcı depolaması temizlenene kadar | Yerel depolama |
| `vjt_visitor_id` | Kssmi | Onaylanmış ziyaretleri bir ziyaretçi yolculuğu ile ilişkilendirmek | Analitik | Çerez: yaklaşık 365 güne kadar; yerel kopya: Analiz izni geri çekilene veya tarayıcı depolaması temizlenene kadar | Çerez ve yerel depolama |
| `vjt_session_id` | Kssmi | Bir oturum içindeki onaylanmış sayfa etkinliklerini ilişkilendirmek | Analitik | Yaklaşık 30 dakika | Çerez |
| `KSSMI_VJT_IDENTITY` | Kssmi | Onaylanmış ziyaretleri tek bir onay döngüsü içinde ilişkilendiren imzalı sunucu kimliği; sayfa betikleri tarafından okunamaz | Analitik | Yaklaşık 365 güne kadar; geri çekmeden sonra tarayıcının izin verdiği ölçüde süresi sona erdirilir | Çerez (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Rızalı iletişim istatistiklerini koruyan, mevcut rıza döngüsüne bağlı imzalı oturum; yalnızca analiz rızasıyla verilir | Analitik | Yaklaşık 30 dakika, gezinme sırasında yenilenir | Çerez (HttpOnly) |
| Diğer Google/üçüncü taraf tanımlayıcıları | Google / ilgili üçüncü taraf | Analitik veya reklam | Analitik/reklam | Sağlayıcıya ve yapılandırmaya göre değişir | Çerez veya benzer teknoloji |

Çerez envanteri, onay başlığı ve canlı uygulama uyuşmalıdır. Bir izleyiciyi yeniden adlandırmak veya bir tanımlayıcıyı bir çerezden yerel depolamaya taşımak, teknolojiyi tek başına onaydan muaf kılmaz.

### 5. Onay seçimlerini değiştirme

Ziyaretçiler Çerez Ayarları'nı yeniden açabilmeli ve analitik ve reklam onaylarını, verdikleri zamanki kadar kolay bir şekilde değiştirebilmeli veya geri çekebilmelidir. Geri çekme, geri çekilmeden önce yasal olan işlemeyi etkilemez. Bir seçim yapıldıktan sonra her sayfada bir Çerez Ayarları düğmesi kullanılabilir olmaya devam eder.

### 6. Anonim sayfa görüntüleme sayımı
Yukarıda açıklanan izne dayalı analizden ayrı olarak, web sitesi sayfa görüntülemelerini toplu biçimde sayar: her takvim günü (Pekin saatine göre) ve sayfa yolu için yalnızca toplam görüntüleme sayısını saklar. Bu sayım tablosu çerez, tarayıcı deposu veya ziyaretçi/oturum tanımlayıcısı kullanmaz ve IP adresleri, kullanıcı aracıları, yönlendirenler veya tek tek ziyaret zaman damgaları içermez. Hız sınırlama, bot filtreleme ve sunucu günlükleri kendi bölümlerinde açıklanan ayrı güvenlik işlemleridir; bu toplu tablonun parçası değildir. Sunucu, yönetici trafiğini saymamak için imzalı bir yönetici hariç tutma işaretini ayrıca okuyabilir; bu işaret anonim toplu tabloda saklanmaz.
