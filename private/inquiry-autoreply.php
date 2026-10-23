<?php
declare(strict_types=1);

/**
 * Inquiry auto-reply (KS-04).
 *
 * Every accepted inquiry gets this confirmation, including repeated inquiries
 * from the same mailbox: abuse is stopped before acceptance (origin, size,
 * per-IP rate, Turnstile, attachments, fields in send-mail.php), never by
 * skipping the reply afterwards. The message is rendered at acceptance and
 * sent through the durable outbox (private/inquiry-outbox.php). The greeting
 * carries the submitted name only when it looks like a name.
 */

const KSSMI_INQUIRY_AUTOREPLY_NAME_MAX = 60;

/**
 * The submitted name as plain text when it is fit for a greeting, else ''.
 * send-mail.php HTML-escapes form fields for the HTML notification; this
 * confirmation is plain text, so that one layer is decoded first. Only
 * letters, combining marks, spaces and . ' - are accepted, at most 60
 * characters and 6 words, and a dot may not join two words, so the greeting
 * cannot carry a link, an address, a number or a sentence.
 */
function kssmi_inquiry_autoreply_name($name): string {
    if (!is_string($name)) return '';
    $text = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (preg_match('/^.{1,' . KSSMI_INQUIRY_AUTOREPLY_NAME_MAX . '}$/u', $text) !== 1) return '';
    if (preg_match("/^\\p{L}[\\p{L}\\p{M} .'\\x{2019}-]*$/u", $text) !== 1) return '';
    if (preg_match('/\.\p{L}/u', $text) === 1 || substr_count($text, ' ') > 5) return '';
    return $text;
}

/**
 * Build the short, plain-text confirmation that is sent to an inquiry sender.
 * It deliberately excludes internal IDs and product references so the reply
 * reads as a simple conversation starter in every site language. The
 * submitted name is used only when kssmi_inquiry_autoreply_name() accepts it.
 */
function kssmi_inquiry_autoreply_build(array $data): array {
    $translations = [
        'en' => ['subject' => 'We received your inquiry — Kssmi Eyewear', 'greeting' => 'Hello {name},', 'greeting_generic' => 'Hello,', 'received' => 'We have received your inquiry. Reply directly to this email to continue the conversation, or chat with us on WhatsApp:', 'follow_up' => 'Our team will follow up within one business day.', 'signoff' => 'Best regards,', 'team' => 'Kssmi Eyewear Team'],
        'it' => ['subject' => 'Abbiamo ricevuto la tua richiesta — Kssmi Eyewear', 'greeting' => 'Ciao {name},', 'greeting_generic' => 'Ciao,', 'received' => 'Abbiamo ricevuto la tua richiesta. Rispondi direttamente a questa email per continuare la conversazione oppure contattaci su WhatsApp:', 'follow_up' => 'Il nostro team ti risponderà entro un giorno lavorativo.', 'signoff' => 'Cordiali saluti,', 'team' => 'Team Kssmi Eyewear'],
        'es' => ['subject' => 'Hemos recibido su consulta — Kssmi Eyewear', 'greeting' => 'Hola {name},', 'greeting_generic' => 'Hola,', 'received' => 'Hemos recibido su consulta. Responda directamente a este correo para continuar la conversación o escríbanos por WhatsApp:', 'follow_up' => 'Nuestro equipo responderá en un día laborable.', 'signoff' => 'Saludos cordiales,', 'team' => 'Equipo Kssmi Eyewear'],
        'fr' => ['subject' => 'Nous avons reçu votre demande — Kssmi Eyewear', 'greeting' => 'Bonjour {name},', 'greeting_generic' => 'Bonjour,', 'received' => 'Nous avons reçu votre demande. Répondez directement à cet e-mail pour poursuivre la conversation, ou contactez-nous sur WhatsApp :', 'follow_up' => 'Notre équipe vous répondra sous un jour ouvré.', 'signoff' => 'Cordialement,', 'team' => 'Équipe Kssmi Eyewear'],
        'de' => ['subject' => 'Wir haben Ihre Anfrage erhalten — Kssmi Eyewear', 'greeting' => 'Hallo {name},', 'greeting_generic' => 'Hallo,', 'received' => 'Wir haben Ihre Anfrage erhalten. Antworten Sie direkt auf diese E-Mail, um das Gespräch fortzusetzen, oder schreiben Sie uns über WhatsApp:', 'follow_up' => 'Unser Team meldet sich innerhalb eines Werktags.', 'signoff' => 'Freundliche Grüße,', 'team' => 'Kssmi Eyewear Team'],
        'pt' => ['subject' => 'Recebemos a sua solicitação — Kssmi Eyewear', 'greeting' => 'Olá {name},', 'greeting_generic' => 'Olá,', 'received' => 'Recebemos a sua solicitação. Responda diretamente a este e-mail para continuar a conversa ou fale connosco pelo WhatsApp:', 'follow_up' => 'A nossa equipa responderá dentro de um dia útil.', 'signoff' => 'Com os melhores cumprimentos,', 'team' => 'Equipa Kssmi Eyewear'],
        'ru' => ['subject' => 'Мы получили ваш запрос — Kssmi Eyewear', 'greeting' => 'Здравствуйте, {name},', 'greeting_generic' => 'Здравствуйте,', 'received' => 'Мы получили ваш запрос. Ответьте прямо на это письмо, чтобы продолжить общение, или напишите нам в WhatsApp:', 'follow_up' => 'Наша команда ответит в течение одного рабочего дня.', 'signoff' => 'С уважением,', 'team' => 'Команда Kssmi Eyewear'],
        'ja' => ['subject' => 'お問い合わせを受け付けました — Kssmi Eyewear', 'greeting' => '{name} 様', 'greeting_generic' => 'お客様', 'received' => 'お問い合わせを受け付けました。このメールに直接返信するか、WhatsAppでお問い合わせください。', 'follow_up' => '担当チームが1営業日以内にご連絡します。', 'signoff' => 'よろしくお願いいたします。', 'team' => 'Kssmi Eyewear チーム'],
        'tr' => ['subject' => 'Talebinizi aldık — Kssmi Eyewear', 'greeting' => 'Merhaba {name},', 'greeting_generic' => 'Merhaba,', 'received' => 'Talebinizi aldık. Görüşmeye devam etmek için bu e-postayı doğrudan yanıtlayın veya WhatsApp üzerinden bize yazın:', 'follow_up' => 'Ekibimiz bir iş günü içinde size dönüş yapacaktır.', 'signoff' => 'Saygılarımızla,', 'team' => 'Kssmi Eyewear Ekibi'],
        'ar' => ['subject' => 'لقد تلقينا استفسارك — Kssmi Eyewear', 'greeting' => 'مرحباً {name}،', 'greeting_generic' => 'مرحباً،', 'received' => 'لقد تلقينا استفسارك. يمكنك الرد مباشرةً على هذا البريد لمتابعة المحادثة أو مراسلتنا عبر واتساب:', 'follow_up' => 'سيتابع فريقنا معك خلال يوم عمل واحد.', 'signoff' => 'مع أطيب التحيات،', 'team' => 'فريق Kssmi Eyewear'],
        'ko' => ['subject' => '문의가 접수되었습니다 — Kssmi Eyewear', 'greeting' => '{name}님, 안녕하세요.', 'greeting_generic' => '안녕하세요.', 'received' => '문의가 접수되었습니다. 대화를 계속하려면 이 이메일에 직접 회신하거나 WhatsApp으로 문의해 주세요:', 'follow_up' => '저희 팀이 영업일 기준 1일 이내에 연락드리겠습니다.', 'signoff' => '감사합니다.', 'team' => 'Kssmi Eyewear 팀'],
        'zh' => ['subject' => '我们已收到您的询盘 — Kssmi Eyewear', 'greeting' => '{name}，您好：', 'greeting_generic' => '您好：', 'received' => '我们已收到您的询盘。您可以直接回复此邮件继续沟通，或通过 WhatsApp 联系我们：', 'follow_up' => '我们的团队将在一个工作日内跟进。', 'signoff' => '此致，', 'team' => 'Kssmi Eyewear 团队'],
        'hi' => ['subject' => 'हमें आपकी पूछताछ मिल गई है — Kssmi Eyewear', 'greeting' => 'नमस्ते {name},', 'greeting_generic' => 'नमस्ते,', 'received' => 'हमें आपकी पूछताछ मिल गई है। बातचीत जारी रखने के लिए इस ईमेल का सीधे उत्तर दें या WhatsApp पर हमें संदेश भेजें:', 'follow_up' => 'हमारी टीम एक कार्य दिवस के भीतर आपसे संपर्क करेगी।', 'signoff' => 'सादर,', 'team' => 'Kssmi Eyewear टीम'],
        'vi' => ['subject' => 'Chúng tôi đã nhận được yêu cầu của bạn — Kssmi Eyewear', 'greeting' => 'Xin chào {name},', 'greeting_generic' => 'Xin chào,', 'received' => 'Chúng tôi đã nhận được yêu cầu của bạn. Hãy trả lời trực tiếp email này để tiếp tục trao đổi hoặc nhắn cho chúng tôi qua WhatsApp:', 'follow_up' => 'Đội ngũ của chúng tôi sẽ phản hồi trong một ngày làm việc.', 'signoff' => 'Trân trọng,', 'team' => 'Đội ngũ Kssmi Eyewear'],
        'jv' => ['subject' => 'Panjaluk sampeyan wis ditampa — Kssmi Eyewear', 'greeting' => 'Halo {name},', 'greeting_generic' => 'Halo,', 'received' => 'Panjaluk sampeyan wis ditampa. Wangsulana email iki langsung kanggo nerusake obrolan utawa hubungi kami liwat WhatsApp:', 'follow_up' => 'Tim kami bakal nanggapi sajrone siji dina kerja.', 'signoff' => 'Salam,', 'team' => 'Tim Kssmi Eyewear'],
        'ms' => ['subject' => 'Kami telah menerima pertanyaan anda — Kssmi Eyewear', 'greeting' => 'Helo {name},', 'greeting_generic' => 'Helo,', 'received' => 'Kami telah menerima pertanyaan anda. Balas terus e-mel ini untuk meneruskan perbualan atau hubungi kami melalui WhatsApp:', 'follow_up' => 'Pasukan kami akan menghubungi anda dalam satu hari bekerja.', 'signoff' => 'Salam hormat,', 'team' => 'Pasukan Kssmi Eyewear'],
        'tg' => ['subject' => 'Мо дархости шуморо қабул кардем — Kssmi Eyewear', 'greeting' => 'Салом {name},', 'greeting_generic' => 'Салом,', 'received' => 'Мо дархости шуморо қабул кардем. Барои идомаи суҳбат ба ин нома мустақиман ҷавоб диҳед ё тавассути WhatsApp ба мо нависед:', 'follow_up' => 'Гурӯҳи мо дар давоми як рӯзи корӣ бо шумо тамос мегирад.', 'signoff' => 'Бо эҳтиром,', 'team' => 'Гурӯҳи Kssmi Eyewear'],
    ];

    $language = $data['language'] ?? 'en';
    $copy = $translations[$language] ?? $translations['en'];
    $name = kssmi_inquiry_autoreply_name($data['name'] ?? '');
    $greeting = $name !== '' ? str_replace('{name}', $name, $copy['greeting']) : $copy['greeting_generic'];

    return [
        'subject' => $copy['subject'],
        'body' => implode("\n", [
            $greeting,
            '',
            $copy['received'],
            'https://wa.me/8613510532553',
            '',
            $copy['follow_up'],
            '',
            $copy['signoff'],
            $copy['team'],
            'sales@kssmi.com',
        ]),
        'to' => (string)($data['email'] ?? ''),
        'recipient_name' => $name,
    ];
}
