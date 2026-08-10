<?php
/**
 * A minimal SMTP client — no Composer, no PHPMailer, same spirit as the rest
 * of the app. Speaks enough SMTP to authenticate and send one UTF-8 message
 * with a plain-text and an HTML part.
 *
 * Configured entirely from .env:
 *   MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_FROM, MAIL_FROM_NAME
 *
 * Port 465 opens an implicit TLS socket; 587 connects in the clear and then
 * issues STARTTLS. Hostinger supports both.
 */

function mail_enabled(): bool
{
    return env('MAIL_HOST') !== null && env('MAIL_USER') !== null;
}

function mail_config(): array
{
    $port = (int) env('MAIL_PORT', 465);
    // Port 465 is implicit TLS, everything else assumes STARTTLS. MAIL_SECURE
    // overrides that guess — 'none' is only sensible for local debugging.
    $secure = strtolower((string) env('MAIL_SECURE', $port === 465 ? 'ssl' : 'tls'));
    if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
        $secure = 'tls';
    }
    return [
        'host'      => (string) env('MAIL_HOST', ''),
        'port'      => $port,
        'user'      => (string) env('MAIL_USER', ''),
        'pass'      => (string) env('MAIL_PASS', ''),
        'from'      => (string) env('MAIL_FROM', (string) env('MAIL_USER', '')),
        'from_name' => (string) env('MAIL_FROM_NAME', (string) cfg('app_name')),
        'secure'    => $secure,
        'timeout'   => (int) env('MAIL_TIMEOUT', 12),
    ];
}

/** Open an authenticated SMTP session. Throws on any failure. */
function smtp_open(array $c)
{
    $target = ($c['secure'] === 'ssl' ? 'ssl://' : '') . $c['host'] . ':' . $c['port'];
    $ctx    = stream_context_create(['ssl' => ['SNI_enabled' => true]]);
    $fp     = @stream_socket_client($target, $errNo, $errStr, $c['timeout'],
                                    STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Could not reach {$c['host']}:{$c['port']} — $errStr");
    }
    stream_set_timeout($fp, $c['timeout']);
    smtp_expect($fp, 220);

    $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    smtp_cmd($fp, "EHLO $ehlo", 250);

    if ($c['secure'] === 'tls') {
        smtp_cmd($fp, 'STARTTLS', 220);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }
        smtp_cmd($fp, "EHLO $ehlo", 250);      // must re-introduce after TLS
    }

    smtp_cmd($fp, 'AUTH LOGIN', 334);
    smtp_cmd($fp, base64_encode($c['user']), 334);
    smtp_cmd($fp, base64_encode($c['pass']), 235);
    return $fp;
}

/** Push one message down an already-open session. */
function smtp_send_one($fp, array $c, string $toEmail, string $toName,
                       string $subject, string $html, string $text): void
{
    smtp_cmd($fp, 'MAIL FROM:<' . $c['from'] . '>', 250);
    smtp_cmd($fp, 'RCPT TO:<' . $toEmail . '>', 250);
    smtp_cmd($fp, 'DATA', 354);
    fwrite($fp, mail_body($c, $toEmail, $toName, $subject, $html, $text) . "\r\n.\r\n");
    smtp_expect($fp, 250);
}

/**
 * Send one message. Returns [ok, errorMessage].
 * Never throws — a broken mail server must never break the app.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): array
{
    if (!mail_enabled()) {
        return [false, 'Mail is not configured (MAIL_HOST / MAIL_USER missing from .env).'];
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Not a valid email address: ' . $toEmail];
    }
    $c  = mail_config();
    $fp = null;
    try {
        $fp = smtp_open($c);
        smtp_send_one($fp, $c, $toEmail, $toName, $subject, $html, $text);
        smtp_cmd($fp, 'QUIT', 221, false);
        fclose($fp);
        return [true, ''];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fclose($fp);
        }
        error_log('[spine mail] ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}

/**
 * Send a personalised message to several people over ONE connection.
 * $recipients: [['email'=>…,'name'=>…,'subject'=>…,'html'=>…,'text'=>…], …]
 * Returns how many went out. Never throws.
 */
function send_bulk(array $recipients): int
{
    if (!mail_enabled() || !$recipients) {
        return 0;
    }
    $c    = mail_config();
    $fp   = null;
    $sent = 0;
    try {
        $fp = smtp_open($c);
        foreach ($recipients as $r) {
            if (!filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            try {
                smtp_send_one($fp, $c, $r['email'], $r['name'] ?? '',
                              $r['subject'], $r['html'], $r['text']);
                $sent++;
            } catch (Throwable $inner) {
                // One bad address must not cost us the rest of the batch.
                error_log('[spine mail] skipped ' . $r['email'] . ': ' . $inner->getMessage());
                try { smtp_cmd($fp, 'RSET', 250); } catch (Throwable $e) { throw $inner; }
            }
        }
        smtp_cmd($fp, 'QUIT', 221, false);
        fclose($fp);
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fclose($fp);
        }
        error_log('[spine mail] bulk: ' . $e->getMessage());
    }
    return $sent;
}

function smtp_cmd($fp, string $line, int $expect, bool $check = true): string
{
    fwrite($fp, $line . "\r\n");
    return $check ? smtp_expect($fp, $expect) : '';
}

/** Read a (possibly multi-line) reply and assert its status code. */
function smtp_expect($fp, int $expect): string
{
    $reply = '';
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        if ($line === false) {
            break;
        }
        $reply .= $line;
        // Continuation lines look like "250-STARTTLS"; the last is "250 OK".
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    $code = (int) substr(ltrim($reply), 0, 3);
    if ($code !== $expect) {
        throw new RuntimeException("SMTP expected $expect, got: " . trim($reply));
    }
    return $reply;
}

function mail_header_encode(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s)
        ? '=?UTF-8?B?' . base64_encode($s) . '?='
        : $s;
}

function mail_body(array $c, string $toEmail, string $toName, string $subject,
                   string $html, string $text): string
{
    $boundary = 'spine' . bin2hex(random_bytes(12));
    $from     = mail_header_encode($c['from_name']) . ' <' . $c['from'] . '>';
    $to       = $toName !== '' ? mail_header_encode($toName) . " <$toEmail>" : $toEmail;

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . $from,
        'To: ' . $to,
        'Subject: ' . mail_header_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($c['host'] ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Auto-Submitted: auto-generated',
    ];

    $part = function (string $type, string $content) use ($boundary) {
        return "--$boundary\r\n"
             . "Content-Type: $type; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($content), 76, "\r\n");
    };

    return implode("\r\n", $headers) . "\r\n\r\n"
         . $part('text/plain', $text)
         . $part('text/html', $html)
         . "--$boundary--";
}

/** Everyone except one person, who has an address and wants notifications. */
function notifiable_people(int $exceptId = 0): array
{
    $st = db()->prepare(
        "SELECT * FROM people WHERE active = 1 AND notify = 1 AND email <> '' AND id <> ?"
    );
    $st->execute([$exceptId]);
    return $st->fetchAll();
}

/* ===================================================================== *
 *  Templates
 *
 *  Email HTML is not web HTML. Everything here is table-based with inline
 *  styles, because Outlook ignores divs for layout and Gmail strips most
 *  stylesheets. The <style> block only carries the small-screen tweaks that
 *  clients supporting media queries will honour; the inline styles alone
 *  already render correctly everywhere else.
 * ===================================================================== */

function me(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Keep subject lines scannable in a crowded inbox. */
function mail_clip(string $s, int $max = 58): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) <= $max ? $s : rtrim(mb_substr($s, 0, $max - 1)) . '…';
}

/**
 * "Prefix: title", with the title trimmed to whatever budget the prefix
 * leaves. A phone shows roughly 40 characters, a desktop client 65 — so the
 * prefix is kept short and the title takes the rest.
 */
function mail_subject(string $prefix, string $title, int $max = 64): string
{
    $prefix = trim($prefix);
    $budget = max(20, $max - mb_strlen($prefix) - 2);
    return $prefix . ': ' . mail_clip($title, $budget);
}

/** Full document: dark, centred, 560px, degrades to full width on a phone. */
function mail_page(string $preheader, string $body): string
{
    $app = me((string) cfg('app_name'));

    return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" '
      . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
      . '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
      . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
      . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
      . '<meta name="color-scheme" content="dark light" />'
      . '<meta name="supported-color-schemes" content="dark light" />'
      . '<title>' . $app . '</title>'
      . '<style type="text/css">'
      . 'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;'
      . '-ms-text-size-adjust:100%}'
      . 'table{border-collapse:collapse}'
      . 'img{border:0;line-height:100%;outline:none;text-decoration:none}'
      . 'a{color:#ffb454}'
      . '@media only screen and (max-width:600px){'
      . '.sp-wrap{padding:14px 10px!important}'
      . '.sp-card{padding:20px 18px!important;border-radius:14px!important}'
      . '.sp-title{font-size:19px!important;line-height:1.3!important}'
      . '.sp-quote{padding:14px!important}'
      . '.sp-btn a{display:block!important;padding:14px 16px!important}'
      . '}'
      . '</style></head>'
      . '<body style="margin:0;padding:0;background:#0f1116">'
      // Preview line shown next to the subject in most inboxes.
      . '<div style="display:none;font-size:1px;color:#0f1116;line-height:1px;max-height:0;'
      . 'max-width:0;opacity:0;overflow:hidden">' . me($preheader)
      . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
      . 'style="background:#0f1116">'
      . '<tr><td class="sp-wrap" align="center" style="padding:28px 14px">'
      . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" '
      . 'style="width:100%;max-width:560px">'

      // Brand line
      . '<tr><td style="padding:0 4px 14px">'
      . '<span style="display:inline-block;width:20px;height:20px;background:#ff9647;'
      . 'border-radius:6px;color:#2b1500;font:700 12px/20px Arial,sans-serif;'
      . 'text-align:center;vertical-align:middle">&#9670;</span>'
      . '<span style="font:700 16px/20px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,'
      . 'Helvetica,Arial,sans-serif;color:#e9ebf1;letter-spacing:-.3px;padding-left:8px;'
      . 'vertical-align:middle">' . $app . '</span></td></tr>'

      // Card
      . '<tr><td class="sp-card" style="background:#14161d;border:1px solid #23262f;'
      . 'border-radius:18px;padding:26px;font-family:-apple-system,BlinkMacSystemFont,'
      . '\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#e9ebf1">'
      . $body
      . '</td></tr>'

      // Footer
      . '<tr><td style="padding:16px 8px 0;text-align:center;font:400 11px/1.5 -apple-system,'
      . 'BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#676e80">'
      . 'The link above signs you in — keep it to yourself.<br />'
      . 'You are getting this because you are in ' . $app . '.'
      . '</td></tr>'

      . '</table></td></tr></table></body></html>';
}

function mail_eyebrow(string $text, string $colour = '#ffb454'): string
{
    return '<div style="font:700 11px/1.4 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,'
      . 'Helvetica,Arial,sans-serif;letter-spacing:1.4px;text-transform:uppercase;color:'
      . $colour . '">' . me($text) . '</div>';
}

function mail_p(string $html, string $colour = '#c9cedb', int $top = 12, int $size = 15): string
{
    return '<p style="margin:' . $top . 'px 0 0;font:400 ' . $size . 'px/1.6 -apple-system,'
      . 'BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:'
      . $colour . '">' . $html . '</p>';
}

/** The highlighted box that carries the actual question or topic. */
function mail_quote(string $title, string $sub = '', string $tint = '#ffb454'): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
      . 'style="margin:16px 0 0"><tr><td class="sp-quote" style="background:#1d1a15;'
      . 'border:1px solid ' . $tint . '40;border-left:3px solid ' . $tint . ';'
      . 'border-radius:12px;padding:16px">'
      . '<div class="sp-title" style="font:650 18px/1.4 -apple-system,BlinkMacSystemFont,'
      . '\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#ffd7a3">' . me($title) . '</div>'
      . ($sub !== '' ? '<div style="margin-top:8px;font:400 14px/1.6 -apple-system,'
          . 'BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#99a0b0">'
          . nl2br(me($sub)) . '</div>' : '')
      . '</td></tr></table>';
}

/** One person's answer, in the digest. */
function mail_answer_block(string $name, string $body): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
      . 'style="margin:12px 0 0"><tr><td style="background:#191c24;border:1px solid #262a34;'
      . 'border-radius:12px;padding:14px 16px">'
      . '<div style="font:700 13px/1.4 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,'
      . 'Helvetica,Arial,sans-serif;color:#ffd7a3">' . me($name) . '</div>'
      . '<div style="margin-top:7px;font:400 14px/1.65 -apple-system,BlinkMacSystemFont,'
      . '\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#c9cedb">'
      . nl2br(me($body)) . '</div>'
      . '</td></tr></table>';
}

/** Bulletproof button — a table cell, so Outlook renders it too. */
function mail_button(string $label, string $href): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
      . 'style="margin:24px 0 0" class="sp-btn"><tr>'
      . '<td align="center" bgcolor="#ff9647" style="border-radius:11px">'
      . '<a href="' . me($href) . '" style="display:block;padding:14px 22px;'
      . 'font:700 15px/1.2 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,'
      . 'Arial,sans-serif;color:#2b1500;text-decoration:none;border-radius:11px">'
      . me($label) . '</a></td></tr></table>';
}

/* ------------------------------------------------------- the messages */

/** A new round just went live — tell everyone else. */
function notify_round_open(array $round, array $author): int
{
    $verb = $round['kind'] === 'question' ? 'asked' : 'shared';
    $noun = ['thought' => 'a thought', 'idea' => 'an idea'][$round['kind']] ?? 'a question';

    $batch = [];
    foreach (notifiable_people((int) $author['id']) as $p) {
        $link = magic_link($p) . '#/round';

        $body = mail_eyebrow($author['name'] . ' ' . $verb . ' ' . $noun)
          . mail_p('Salam <b style="color:#e9ebf1">' . me($p['name']) . '</b>,', '#c9cedb', 14, 16)
          . mail_quote($round['title'], (string) $round['body'])
          . mail_p('Write yours before you read the others. That way nobody is just agreeing '
              . 'with whoever went first.', '#99a0b0', 16, 14)
          . mail_button('Add your answer', $link);

        $batch[] = [
            'email'   => $p['email'],
            'name'    => $p['name'],
            'subject' => mail_subject($author['name'] . ' ' . $verb, $round['title']),
            'text'    => "Salam {$p['name']},\n\n{$author['name']} {$verb} {$noun}:\n\n"
                       . "  {$round['title']}\n"
                       . ($round['body'] !== '' ? "  {$round['body']}\n" : '')
                       . "\nWrite yours before you read the others.\n\n{$link}\n",
            'html'    => mail_page($round['title'], $body),
        ];
    }
    return send_bulk($batch);
}

/** Enough answers landed — it is readable by everyone now. */
function notify_round_closed(array $round, int $answerCount): int
{
    $batch = [];
    foreach (notifiable_people(0) as $p) {
        $link = magic_link($p) . '#/round/' . (int) $round['id'];

        $body = mail_eyebrow('Answers unlocked', '#7ee0a5')
          . mail_p('Salam <b style="color:#e9ebf1">' . me($p['name']) . '</b>,', '#c9cedb', 14, 16)
          . mail_p('Enough people answered, so everyone can read the whole thing now.', '#99a0b0', 10, 14)
          . mail_quote($round['title'], $answerCount . ' answers', '#4ade80')
          . mail_p('The floor is free — anyone can put the next one up.', '#99a0b0', 16, 14)
          . mail_button('Read the answers', $link);

        $batch[] = [
            'email'   => $p['email'],
            'name'    => $p['name'],
            'subject' => mail_subject('Answers unlocked', $round['title']),
            'text'    => "Salam {$p['name']},\n\nEnough people answered — everyone can read it now.\n\n"
                       . "  {$round['title']}\n  {$answerCount} answers\n\n{$link}\n\n"
                       . "The floor is free, so anyone can put the next one up.\n",
            'html'    => mail_page($answerCount . ' answers are readable now', $body),
        ];
    }
    return send_bulk($batch);
}

/**
 * The digest: the question and every answer in full.
 * This one has to be worth reading without clicking anything.
 */
function notify_round_digest(array $round, array $answers): int
{
    $n     = count($answers);
    $noun  = ['thought' => 'thought', 'idea' => 'idea'][$round['kind']] ?? 'question';
    $blocks = '';
    $plain  = '';
    foreach ($answers as $a) {
        $blocks .= mail_answer_block($a['name'], $a['body']);
        $plain  .= "\n{$a['name']}\n{$a['body']}\n";
    }

    $batch = [];
    foreach (notifiable_people(0) as $p) {
        $link = magic_link($p) . '#/round/' . (int) $round['id'];

        $body = mail_eyebrow($n . ' ' . ($n === 1 ? 'answer' : 'answers'))
          . mail_p('Salam <b style="color:#e9ebf1">' . me($p['name']) . '</b>,', '#c9cedb', 14, 16)
          . mail_p('This ' . $noun . ' has run its course. Here is everything people said.', '#99a0b0', 10, 14)
          . mail_quote($round['title'])
          . $blocks
          . mail_p('Did not get to it? You can still add yours — it stays open in the archive.',
              '#676e80', 18, 13)
          . mail_button('Open the archive', $link);

        $batch[] = [
            'email'   => $p['email'],
            'name'    => $p['name'],
            'subject' => mail_subject($n . ' ' . ($n === 1 ? 'answer' : 'answers'), $round['title']),
            'text'    => "Salam {$p['name']},\n\nThis {$noun} has run its course. "
                       . "Here is everything people said.\n\n  {$round['title']}\n"
                       . str_repeat('-', 46) . "\n{$plain}" . str_repeat('-', 46)
                       . "\n\nYou can still add yours:\n{$link}\n",
            'html'    => mail_page($round['title'] . ' — ' . $n . ' answers', $body),
        ];
    }
    return send_bulk($batch);
}

/** Sent to the other person the moment a spark is created. */
function notify_spark(array $from, array $to, string $topic, string $message): array
{
    if (($to['email'] ?? '') === '' || (int) ($to['notify'] ?? 1) !== 1) {
        return [false, 'no address or notifications muted'];
    }

    $link = magic_link($to) . '#/sparks';
    $body = mail_eyebrow('New spark')
      . mail_p('Salam <b style="color:#e9ebf1">' . me($to['name']) . '</b>,', '#c9cedb', 14, 16)
      . mail_p('<b style="color:#e9ebf1">' . me($from['name']) . '</b> wants to talk to you about:',
          '#c9cedb', 10, 15)
      . mail_quote($topic, $message !== '' ? '“' . $message . '”' : '')
      . mail_p('A spark is a small promise that you two will actually talk. A voice note counts. '
          . 'A ten-minute call counts.', '#99a0b0', 16, 14)
      . mail_button('Open it', $link);

    return send_mail(
        (string) $to['email'],
        (string) $to['name'],
        mail_subject($from['name'] . ' wants to talk', $topic),
        mail_page($from['name'] . ' — ' . $topic, $body),
        "Salam {$to['name']},\n\n{$from['name']} wants to talk to you about:\n\n  {$topic}\n"
          . ($message !== '' ? "  \"{$message}\"\n" : '')
          . "\nA spark is a small promise that you two will actually talk. "
          . "A voice note counts.\n\n{$link}\n"
    );
}
