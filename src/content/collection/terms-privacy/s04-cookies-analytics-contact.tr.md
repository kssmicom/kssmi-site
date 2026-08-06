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

Kssmi, asgari bir iletişim etkinliği işlevini onaya dayalı ziyaretçi yolculuğu analitiği işlevinden ayırır. Bunlar farklı amaçlara hizmet eder ve tek bir yasal dayanağa sahip tek bir sistem olarak tanımlanmamalıdır.

### 1. İletişim etkinlikleri

Bir ziyaretçi kasıtlı olarak bir WhatsApp veya e-posta bağlantısı seçtiğinde, web sitesi iletişim giriş noktasının açıldığını gösteren asgari bir olay kaydedebilir. Analitik onayı olmadan bu olay yalnızca şunları içerecek şekilde tasarlanmıştır:

- seçilen kanal;
- bir `open_intent` olay türü;
- sunucu saati;
- ilgili site içi sayfa yolu;
- bağlantı yerleşimi;
- ilgili durumlarda ürün SKU'su;
- site dili; ve
- bir niyet (`intent`) durumu.

Analitik onayı olmadan bu kayıt, bir VJT ziyaretçi/oturum tanımlayıcısı oluşturmamalı veya okumamalı ve yeniden yapılandırılmış bir gezinme yolculuğu, tam yönlendiren URL'si, kampanya parametreleri, IP adresi, kullanıcı aracısı veya coğrafi konum içermemelidir. Hız sınırlaması için ayrı, kısa ömürlü bir güvenlik işlemi gerçekleşebilir.

Bir `open_intent` kaydı, yalnızca web sitesi iletişim bağlantısının tetiklendiği anlamına gelir. Bir cihazın WhatsApp'ı veya bir e-posta istemcisini başarıyla açtığını, ziyaretçinin bir mesaj gönderdiğini veya Kssmi'nin bir mesaj aldığını kanıtlamaz.

Bir sorgu formu için, `submission_success` olayı, web sitesinin yapılandırılmış gönderme sürecinin başarılı olduğunu bildirdiği anlamına gelir. Alıcının e-postayı okuduğunu veya yanıtladığını kanıtlamaz.

### 2. Ziyaretçi yolculuğu izleme (VJT)

Analitik onayı ile VJT, sayfa ziyaretlerini ve iletişim etkinliklerini onaylanmış tek bir yolculukla ilişkilendirmek için birinci taraf bir ziyaretçi tanımlayıcısı ve kısa ömürlü bir oturum tanımlayıcısı kullanabilir. Etkin yapılandırmaya bağlı olarak yolculuk verileri şunları içerebilir:

- sayfa URL'leri ve başlıkları;
- ziyaret ve etkileşim süreleri;
- yönlendiren ve kampanya parametreleri;
- tarayıcı, cihaz, ekran, dil ve saat dilimi bilgileri;
- IP'den türetilmiş ülke veya şehir;
- kaydırma ve etkileşim ölçümleri; ve
- sorgu veya iletişim etkinliği ilişkilendirmesi.

Ziyaretçi analitik onayı verene kadar analitik yolculuğu devre dışı kalmalıdır. Onay geri çekilirse, sonraki analitik toplama durdurulmalı ve tarayıcıda depolanan VJT tanımlayıcıları uygulanan geri çekme sürecine uygun olarak kaldırılmalıdır.

### 3. Reklam ve üçüncü taraf analitiği

Google Analytics, Google Ads, Google Etiket Yöneticisi veya karşılaştırılabilir ölçüm teknolojisi, ziyaretçinin seçtiği onay kategorilerine ve sitenin gerçek yapılandırmasına göre çalışmalıdır. Nihai bildirim, yalnızca gerçekten etkinleştirilmiş olan ürünleri ve özellikleri açıklamalıdır.

### 4. Çerezler ve tarayıcı depolama

Bu bildirimde açıklanan web sitesi sistemleri için aşağıdaki süreler ve ölçütler geçerlidir:

| Ad | Sağlayıcı | Amaç | Kategori | Süre | Depolama türü |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ziyaretçinin analitik ve reklam tercihlerini hatırlamak | Gerekli | Seçim değiştirilene veya tarayıcı depolaması temizlenene kadar | Local storage |
| `vjt_visitor_id` | Kssmi | Onaylanmış ziyaretleri bir ziyaretçi yolculuğu ile ilişkilendirmek | Analitik | Cookie: up to about 365 days; local copy: Analiz izni geri çekilene veya tarayıcı depolaması temizlenene kadar | Çerez ve yerel depolama |
| `vjt_session_id` | Kssmi | Bir oturum içindeki onaylanmış sayfa etkinliklerini ilişkilendirmek | Analitik | About 30 minutes | Çerez |
| Diğer Google/üçüncü taraf tanımlayıcıları | Google / relevant third party | Analitik veya reklam | Analitik/reklam | Sağlayıcıya ve yapılandırmaya göre değişir | Çerez veya benzer teknoloji |

Çerez envanteri, onay başlığı ve canlı uygulama uyuşmalıdır. Bir izleyiciyi yeniden adlandırmak veya bir tanımlayıcıyı bir çerezden yerel depolamaya taşımak, teknolojiyi tek başına onaydan muaf kılmaz.

### 5. Onay seçimlerini değiştirme

Ziyaretçiler Çerez Ayarları'nı yeniden açabilmeli ve analitik ve reklam onaylarını, verdikleri zamanki kadar kolay bir şekilde değiştirebilmeli veya geri çekebilmelidir. Geri çekme, geri çekilmeden önce yasal olan işlemeyi etkilemez.

### 6. Anonim sayfa görüntüleme sayımı
Yukarıda açıklanan izne dayalı analizden ayrı olarak, web sitesi sayfa görüntülemelerini toplu biçimde sayar: her takvim günü (Pekin saatine göre) ve sayfa yolu için yalnızca toplam görüntüleme sayısını saklar. Bu sayım tablosu çerez, tarayıcı deposu veya ziyaretçi/oturum tanımlayıcısı kullanmaz ve IP adresleri, kullanıcı aracıları, yönlendirenler veya tek tek ziyaret zaman damgaları içermez. Hız sınırlama, bot filtreleme ve sunucu günlükleri kendi bölümlerinde açıklanan ayrı güvenlik işlemleridir; bu toplu tablonun parçası değildir. Sunucu, yönetici trafiğini saymamak için imzalı bir yönetici hariç tutma işaretini ayrıca okuyabilir; bu işaret anonim toplu tabloda saklanmaz.
