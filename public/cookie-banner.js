/**
 * Cookie Banner — Deferred loader
 * Loaded via bootstrap script only when consent not yet given.
 */
(function () {
  var STORAGE_KEY = 'cookie-consent';
  var INITIALIZED = false;

  // === i18n ===
  var I18N = {
  en: {
    title: 'Cookie Consent',
    message: 'We use cookies to enhance your browsing experience, serve personalized content, and analyze our traffic. By clicking "Accept All", you consent to our use of cookies.',
    accept: 'Accept All', reject: 'Reject All', preferences: 'Preferences', save: 'Save Preferences',
    essentialLabel: 'Essential', essentialDesc: 'Required for the website to function properly. Cannot be disabled.',
    analyticsLabel: 'Analytics', analyticsDesc: 'Help us understand how visitors interact with our website.',
    adsLabel: 'Marketing', adsDesc: 'Used to deliver relevant advertisements and track campaign performance.',
    cancel: 'Cancel',
  },
  it: {
    title: 'Consenso Cookie', message: 'Utilizziamo i cookie per migliorare la tua esperienza di navigazione, fornire contenuti personalizzati e analizzare il nostro traffico. Cliccando "Accetta Tutti", acconsenti al nostro utilizzo dei cookie.',
    accept: 'Accetta Tutti', reject: 'Rifiuta Tutti', preferences: 'Preferenze', save: 'Salva Preferenze',
    essentialLabel: 'Essenziali', essentialDesc: 'Necessari per il corretto funzionamento del sito. Non possono essere disabilitati.',
    analyticsLabel: 'Analitici', analyticsDesc: 'Ci aiutano a capire come i visitatori interagiscono con il nostro sito.',
    adsLabel: 'Marketing', adsDesc: 'Utilizzati per fornire pubblicità pertinenti e monitorare le prestazioni delle campagne.',
    cancel: 'Annulla',
  },
  es: {
    title: 'Consentimiento de Cookies', message: 'Utilizamos cookies para mejorar su experiencia de navegación, ofrecer contenido personalizado y analizar nuestro tráfico. Al hacer clic en "Aceptar Todo", usted consiente nuestro uso de cookies.',
    accept: 'Aceptar Todo', reject: 'Rechazar Todo', preferences: 'Preferencias', save: 'Guardar Preferencias',
    essentialLabel: 'Esenciales', essentialDesc: 'Necesarias para el funcionamiento del sitio. No se pueden desactivar.',
    analyticsLabel: 'Analíticas', analyticsDesc: 'Nos ayudan a entender cómo los visitantes interactúan con nuestro sitio.',
    adsLabel: 'Marketing', adsDesc: 'Utilizadas para mostrar anuncios relevantes y realizar seguimiento de campañas.',
    cancel: 'Cancelar',
  },
  fr: {
    title: 'Consentement aux Cookies', message: 'Nous utilisons des cookies pour améliorer votre expérience de navigation, proposer du contenu personnalisé et analyser notre trafic. En cliquant sur "Tout Accepter", vous consentez à notre utilisation des cookies.',
    accept: 'Tout Accepter', reject: 'Tout Refuser', preferences: 'Préférences', save: 'Enregistrer',
    essentialLabel: 'Essentiels', essentialDesc: 'Nécessaires au bon fonctionnement du site. Ne peuvent pas être désactivés.',
    analyticsLabel: 'Analytiques', analyticsDesc: 'Nous aident à comprendre comment les visiteurs interagissent avec notre site.',
    adsLabel: 'Marketing', adsDesc: 'Utilisés pour diffuser des publicités pertinentes et suivre les performances des campagnes.',
    cancel: 'Annuler',
  },
  de: {
    title: 'Cookie-Einwilligung', message: 'Wir verwenden Cookies, um Ihr Surferlebnis zu verbessern, personalisierte Inhalte bereitzustellen und unseren Datenverkehr zu analysieren. Mit einem Klick auf "Alle Akzeptieren" willigen Sie in die Verwendung von Cookies ein.',
    accept: 'Alle Akzeptieren', reject: 'Alle Ablehnen', preferences: 'Einstellungen', save: 'Einstellungen Speichern',
    essentialLabel: 'Notwendig', essentialDesc: 'Für den Betrieb der Website erforderlich. Können nicht deaktiviert werden.',
    analyticsLabel: 'Analyse', analyticsDesc: 'Helfen uns zu verstehen, wie Besucher mit unserer Website interagieren.',
    adsLabel: 'Marketing', adsDesc: 'Werden verwendet, um relevante Werbung anzuzeigen und Kampagnenleistung zu messen.',
    cancel: 'Abbrechen',
  },
  pt: {
    title: 'Consentimento de Cookies', message: 'Utilizamos cookies para melhorar sua experiência de navegação, fornecer conteúdo personalizado e analisar nosso tráfego. Ao clicar em "Aceitar Tudo", você consente com o uso de cookies.',
    accept: 'Aceitar Tudo', reject: 'Rejeitar Tudo', preferences: 'Preferências', save: 'Salvar Preferências',
    essentialLabel: 'Essenciais', essentialDesc: 'Necessários para o funcionamento do site. Não podem ser desativados.',
    analyticsLabel: 'Analíticos', analyticsDesc: 'Ajudam-nos a entender como os visitantes interagem com o nosso site.',
    adsLabel: 'Marketing', adsDesc: 'Usados para exibir anúncios relevantes e acompanhar o desempenho das campanhas.',
    cancel: 'Cancelar',
  },
  ru: {
    title: 'Согласие на Файлы Cookie', message: 'Мы используем файлы cookie для улучшения вашего опыта, предоставления персонализированного контента и анализа трафика. Нажимая «Принять Все», вы даете согласие на использование файлов cookie.',
    accept: 'Принять Все', reject: 'Отклонить Все', preferences: 'Настройки', save: 'Сохранить Настройки',
    essentialLabel: 'Необходимые', essentialDesc: 'Требуются для работы сайта. Не могут быть отключены.',
    analyticsLabel: 'Аналитика', analyticsDesc: 'Помогают понять, как посетители взаимодействуют с сайтом.',
    adsLabel: 'Маркетинг', adsDesc: 'Используются для показа релевантной рекламы и отслеживания эффективности кампаний.',
    cancel: 'Отмена',
  },
  ja: {
    title: 'Cookieの同意', message: '当サイトでは、閲覧体験の向上、パーソナライズされたコンテンツの提供、トラフィック分析のためにCookieを使用しています。「すべて同意する」をクリックすると、Cookieの使用に同意したことになります。',
    accept: 'すべて同意する', reject: 'すべて拒否する', preferences: '設定', save: '設定を保存',
    essentialLabel: '必須', essentialDesc: 'サイトの動作に必要です。無効にすることはできません。',
    analyticsLabel: '分析', analyticsDesc: '訪問者がサイトとどのように対話しているかを理解するのに役立ちます。',
    adsLabel: 'マーケティング', adsDesc: '関連性の高い広告の配信とキャンペーンのパフォーマンス追跡に使用されます。',
    cancel: 'キャンセル',
  },
  tr: {
    title: 'Çerez Onayı', message: 'Tarama deneyiminizi geliştirmek, kişiselleştirilmiş içerik sunmak ve trafiğimizi analiz etmek için çerezler kullanıyoruz. "Tümünü Kabul Et" seçeneğine tıklayarak çerez kullanımımıza onay vermiş olursunuz.',
    accept: 'Tümünü Kabul Et', reject: 'Tümünü Reddet', preferences: 'Tercihler', save: 'Tercihleri Kaydet',
    essentialLabel: 'Gerekli', essentialDesc: 'Sitenin düzgün çalışması için gereklidir. Devre dışı bırakılamaz.',
    analyticsLabel: 'Analitik', analyticsDesc: 'Ziyaretçilerin sitemizle nasıl etkileşim kurduğunu anlamamıza yardımcı olur.',
    adsLabel: 'Pazarlama', adsDesc: 'İlgili reklamları sunmak ve kampanya performansını izlemek için kullanılır.',
    cancel: 'İptal',
  },
  ar: {
    title: 'الموافقة على ملفات تعريف الارتباط', message: 'نحن نستخدم ملفات تعريف الارتباط لتحسين تجربة التصفح الخاصة بك، وتقديم محتوى مخصص، وتحليل حركة المرور لدينا. بالنقر على "قبول الكل"، فإنك توافق على استخدامنا لملفات تعريف الارتباط.',
    accept: 'قبول الكل', reject: 'رفض الكل', preferences: 'التفضيلات', save: 'حفظ التفضيلات',
    essentialLabel: 'ضرورية', essentialDesc: 'مطلوبة لعمل الموقع بشكل صحيح. لا يمكن تعطيلها.',
    analyticsLabel: 'تحليلات', analyticsDesc: 'تساعدنا على فهم كيفية تفاعل الزوار مع موقعنا.',
    adsLabel: 'تسويق', adsDesc: 'تُستخدم لتقديم إعلانات ذات صلة وتتبع أداء الحملات.',
    cancel: 'إلغاء',
  },
  ko: {
    title: '쿠키 동의', message: '당사는 browsing 경험을 향상시키고, 맞춤형 콘텐츠를 제공하며, 트래픽을 분석하기 위해 쿠키를 사용합니다. "모두 동의"를 클릭하면 쿠키 사용에 동의하는 것입니다.',
    accept: '모두 동의', reject: '모두 거부', preferences: '환경 설정', save: '설정 저장',
    essentialLabel: '필수', essentialDesc: '웹사이트의 올바른 작동에 필요합니다. 비활성화할 수 없습니다.',
    analyticsLabel: '분석', analyticsDesc: '방문자가 웹사이트와 상호 작용하는 방식을 이해하는 데 도움이 됩니다.',
    adsLabel: '마케팅', adsDesc: '관련 광고를 제공하고 캠페인 성과를 추적하는 데 사용됩니다.',
    cancel: '취소',
  },
  zh: {
    title: 'Cookie 同意', message: '我们使用 Cookie 来增强您的浏览体验、提供个性化内容并分析我们的流量。点击"全部接受"即表示您同意我们使用 Cookie。',
    accept: '全部接受', reject: '全部拒绝', preferences: '偏好设置', save: '保存设置',
    essentialLabel: '必要', essentialDesc: '网站正常运行所必需。无法禁用。',
    analyticsLabel: '分析', analyticsDesc: '帮助我们了解访客如何与我们的网站互动。',
    adsLabel: '营销', adsDesc: '用于投放相关广告并跟踪广告活动效果。',
    cancel: '取消',
  },
  hi: {
    title: 'कुकी सहमति', message: 'हम आपके ब्राउज़िंग अनुभव को बेहतर बनाने, वैयक्तिकृत सामग्री प्रदान करने और अपने ट्रैफ़िक का विश्लेषण करने के लिए कुकीज़ का उपयोग करते हैं। "सभी स्वीकार करें" पर क्लिक करके, आप कुकीज़ के हमारे उपयोग के लिए सहमति देते हैं।',
    accept: 'सभी स्वीकार करें', reject: 'सभी अस्वीकार करें', preferences: 'प्राथमिकताएं', save: 'प्राथमिकताएं सहेजें',
    essentialLabel: 'आवश्यक', essentialDesc: 'वेबसाइट के ठीक से काम करने के लिए आवश्यक। अक्षम नहीं किया जा सकता।',
    analyticsLabel: 'विश्लेषण', analyticsDesc: 'हमें यह समझने में मदद करते हैं कि आगंतुक हमारी वेबसाइट के साथ कैसे बातचीत करते हैं।',
    adsLabel: 'विपणन', adsDesc: 'प्रासंगिक विज्ञापन देने और अभियान प्रदर्शन को ट्रैक करने के लिए उपयोग किया जाता है।',
    cancel: 'रद्द करें',
  },
  vi: {
    title: 'Đồng Ý Cookie', message: 'Chúng tôi sử dụng cookie để nâng cao trải nghiệm duyệt web của bạn, cung cấp nội dung được cá nhân hóa và phân tích lưu lượng truy cập. Bằng cách nhấp vào "Chấp Nhận Tất Cả", bạn đồng ý với việc chúng tôi sử dụng cookie.',
    accept: 'Chấp Nhận Tất Cả', reject: 'Từ Chối Tất Cả', preferences: 'Tùy Chọn', save: 'Lưu Tùy Chọn',
    essentialLabel: 'Thiết Yếu', essentialDesc: 'Cần thiết để trang web hoạt động bình thường. Không thể vô hiệu hóa.',
    analyticsLabel: 'Phân Tích', analyticsDesc: 'Giúp chúng tôi hiểu cách khách truy cập tương tác với trang web.',
    adsLabel: 'Tiếp Thị', adsDesc: 'Được sử dụng để phân phối quảng cáo phù hợp và theo dõi hiệu suất chiến dịch.',
    cancel: 'Hủy',
  },
  jv: {
    title: 'Idin Cookie', message: 'Kita nggunakake cookie kanggo nambah pengalaman browsing, nyedhiyakake konten sing dipersonalisasi, lan nganalisa lalu lintas. Kanthi ngeklik "Terima Kabeh", sampeyan setuju karo panggunaan cookie.',
    accept: 'Terima Kabeh', reject: 'Tolak Kabeh', preferences: 'Preferensi', save: 'Simpen Preferensi',
    essentialLabel: 'Penting', essentialDesc: 'Dibutuhake supaya situs web bisa mlaku kanthi bener. Ora bisa dipateni.',
    analyticsLabel: 'Analitik', analyticsDesc: 'Mbantu kita ngerti carane pengunjung interaksi karo situs web kita.',
    adsLabel: 'Pemasaran', adsDesc: 'Digunakake kanggo ngirim iklan sing relevan lan nglacak kinerja kampanye.',
    cancel: 'Batal',
  },
  ms: {
    title: 'Persetujuan Cookie', message: 'Kami menggunakan cookie untuk meningkatkan pengalaman melayari anda, menyediakan kandungan yang diperibadikan, dan menganalisis trafik kami. Dengan mengklik "Terima Semua", anda bersetuju dengan penggunaan cookie kami.',
    accept: 'Terima Semua', reject: 'Tolak Semua', preferences: 'Keutamaan', save: 'Simpan Keutamaan',
    essentialLabel: 'Penting', essentialDesc: 'Diperlukan untuk laman web berfungsi dengan betul. Tidak boleh dinyahaktifkan.',
    analyticsLabel: 'Analitik', analyticsDesc: 'Membantu kami memahami cara pelawat berinteraksi dengan laman web kami.',
    adsLabel: 'Pemasaran', adsDesc: 'Digunakan untuk menyampaikan iklan yang relevan dan menjejak prestasi kempen.',
    cancel: 'Batal',
  },
  tg: {
    title: 'Розигии Cookie', message: 'Мо кукиҳоро барои беҳтар кардани таҷрибаи browsing, пешниҳоди мундариҷаи фардӣ ва таҳлили трафик истифода мебарем. Бо клик кардани "Ҳамаро Қабул Кардан", шумо ба истифодаи кукиҳо розигӣ медиҳед.',
    accept: 'Ҳамаро Қабул Кардан', reject: 'Ҳамаро Рад Кардан', preferences: 'Танзимот', save: 'Сабти Танзимот',
    essentialLabel: 'Ҳатмӣ', essentialDesc: 'Барои дуруст кор кардани сомона зарур аст. Хомӯш карда намешавад.',
    analyticsLabel: 'Таҳлилӣ', analyticsDesc: 'Ба мо барои фаҳмидани тарзи муносибати меҳмонон бо сомона кӯмак мерасонад.',
    adsLabel: 'Маркетинг', adsDesc: 'Барои пешниҳоди таблиғоти мувофиқ ва пайгирии самаранокии маъракаҳо истифода мешавад.',
    cancel: 'Бекор Кардан',
  },
  };

  function getLang() {
    // Normalise <html lang> (e.g. 'EN', 'zh-CN') to a supported i18n key so each
    // language reliably gets its own banner text; fall back to base code, then 'en'.
    var l = (document.documentElement.lang || 'en').toLowerCase();
    if (I18N[l]) return l;
    l = l.split('-')[0];
    return I18N[l] ? l : 'en';
  }

  function t() {
    return I18N[getLang()] || I18N.en;
  }

  function isRTL() {
    return getLang() === 'ar';
  }

  // Helps
  function getConsent() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch (_) { return null; }
  }
  function setConsent(value) { localStorage.setItem(STORAGE_KEY, JSON.stringify(value)); }
  function gtagConsentUpdate(analytics, ads) {
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { dataLayer.push(arguments); };
    gtag('consent', 'update', {
      ad_storage: ads ? 'granted' : 'denied',
      ad_user_data: ads ? 'granted' : 'denied',
      ad_personalization: ads ? 'granted' : 'denied',
      analytics_storage: analytics ? 'granted' : 'denied',
    });
  }
  function vjtConsentUpdate(analytics) {
    if (window.VJTTracker) { window.VJTTracker.enabled = !!analytics; }
    // When consent is granted, start tracking the current page right away
    // (the tracker skips init while disabled, so it needs a kick on opt-in).
    if (analytics && typeof window.VJT_init === 'function') {
      try { window.VJT_init(); } catch (e) {}
    }
  }

  // Build DOM
  var div = document.createElement('div');
  div.id = 'cookie-banner';
  div.setAttribute('dir', isRTL() ? 'rtl' : 'ltr');
  div.innerHTML = '<div id="cookie-banner-main" class="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 bg-white/95 backdrop-blur-md"><div class="mx-auto flex w-[95%] lg:w-[92%] 2xl:w-[87%] max-w-[1860px] flex-col gap-4 px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:py-4"><div class="max-w-3xl"><p class="text-sm leading-relaxed text-gray-600">' + t().message + '</p></div><div class="flex flex-shrink-0 flex-wrap items-center gap-3"><button id="cookie-btn-preferences" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100">' + t().preferences + '</button><button id="cookie-btn-reject" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100">' + t().reject + '</button><button id="cookie-btn-accept" class="rounded-lg bg-[#5D4E37] px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-[#4A3D2E]">' + t().accept + '</button></div></div></div><div id="cookie-modal-overlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50"><div id="cookie-modal" class="mx-4 w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="cookie-modal-title"><h3 id="cookie-modal-title" class="text-lg font-semibold text-gray-900">' + t().title + '</h3><p class="mt-2 text-sm text-gray-500">' + t().message + '</p><div class="mt-5 space-y-3"><label class="flex cursor-not-allowed items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3"><input type="checkbox" checked disabled class="mt-0.5 h-4 w-4 accent-[#8B7355]" /><div><span class="text-sm font-medium text-gray-900">' + t().essentialLabel + '</span><p class="text-xs text-gray-500">' + t().essentialDesc + '</p></div></label><label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition-colors hover:bg-gray-50"><input id="cookie-check-analytics" type="checkbox" checked class="mt-0.5 h-4 w-4 accent-[#8B7355]" /><div><span class="text-sm font-medium text-gray-900">' + t().analyticsLabel + '</span><p class="text-xs text-gray-500">' + t().analyticsDesc + '</p></div></label><label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition-colors hover:bg-gray-50"><input id="cookie-check-ads" type="checkbox" checked class="mt-0.5 h-4 w-4 accent-[#8B7355]" /><div><span class="text-sm font-medium text-gray-900">' + t().adsLabel + '</span><p class="text-xs text-gray-500">' + t().adsDesc + '</p></div></label></div><div class="mt-6 flex justify-end gap-3"><button id="cookie-modal-cancel" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100">' + t().cancel + '</button><button id="cookie-modal-save" class="rounded-lg bg-[#5D4E37] px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-[#4A3D2E]">' + t().save + '</button></div></div></div>';
  document.body.appendChild(div);

  function initBanner() {
    if (INITIALIZED) return;
    var banner = document.getElementById('cookie-banner');
    var overlay = document.getElementById('cookie-modal-overlay');
    var checkAnalytics = document.getElementById('cookie-check-analytics');
    var checkAds = document.getElementById('cookie-check-ads');
    if (!banner || !overlay) return;
    INITIALIZED = true;
    banner.classList.remove('hidden');
    function closeModal() { overlay.classList.add('hidden'); overlay.classList.remove('flex'); }
    document.getElementById('cookie-btn-accept').addEventListener('click', function () { setConsent({ analytics: true, ads: true }); gtagConsentUpdate(true, true); vjtConsentUpdate(true); banner.classList.add('hidden'); });
    document.getElementById('cookie-btn-reject').addEventListener('click', function () { setConsent({ analytics: false, ads: false }); gtagConsentUpdate(false, false); vjtConsentUpdate(false); banner.classList.add('hidden'); });
    document.getElementById('cookie-btn-preferences').addEventListener('click', function () { overlay.classList.remove('hidden'); overlay.classList.add('flex'); });
    document.getElementById('cookie-modal-cancel').addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.classList.contains('hidden')) closeModal(); });
    document.getElementById('cookie-modal-save').addEventListener('click', function () { var a = checkAnalytics.checked; var d = checkAds.checked; setConsent({ analytics: a, ads: d }); gtagConsentUpdate(a, d); vjtConsentUpdate(a); banner.classList.add('hidden'); closeModal(); });
  }

  function applyExistingConsent() {
    var existing = getConsent();
    if (existing) { gtagConsentUpdate(existing.analytics || false, existing.ads || false); vjtConsentUpdate(existing.analytics || false); return true; }
    return false;
  }

  if (applyExistingConsent()) return;
  initBanner();
  document.addEventListener('astro:page-load', function () { if (applyExistingConsent()) return; INITIALIZED = false; initBanner(); });
})();
