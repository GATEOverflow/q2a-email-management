<?php
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

require_once QA_EMAIL_MGMT_DIR . 'qa-email-helpers.php';

// ──────────────────────────────────────────────────────────────
//  EMAIL TEMPLATE CONFIGURATION  ← edit these values
// ──────────────────────────────────────────────────────────────

if (!defined('QA_EMAIL_LOGO_URL'))    define('QA_EMAIL_LOGO_URL',    qa_opt('logo_url'));                                                   // leave empty to hide logo
if (!defined('QA_EMAIL_LOGO_HEIGHT')) define('QA_EMAIL_LOGO_HEIGHT', 48);
if (!defined('QA_EMAIL_HEADER_BG'))   define('QA_EMAIL_HEADER_BG',   '#1a1a2e');
if (!defined('QA_EMAIL_HEADER_TEXT')) define('QA_EMAIL_HEADER_TEXT', '#ffffff');
if (!defined('QA_EMAIL_ACCENT'))      define('QA_EMAIL_ACCENT',      '#4a90d9');
if (!defined('QA_EMAIL_FOOTER_TEXT')) define('QA_EMAIL_FOOTER_TEXT', qa_opt('em_footer_text') ?: 'You are receiving this email because you are registered on our site.');

// ──────────────────────────────────────────────────────────────

/**
 * Load and cache (per-request) the event configuration from the DB.
 *
 * Returns three maps keyed by the resolved subject string:
 *   forced  – subjects that always send regardless of user preference
 *   managed – subject → eventid
 *   bodies  – subject → custom body template
 *
 * @return array{forced: string[], managed: array<string,int>, bodies: array<string,string>}
 */
function em_get_events_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows  = qa_db_read_all_assoc(qa_db_query_sub('SELECT * FROM ^email_events'));
    $cache = ['forced' => [], 'managed' => [], 'bodies' => []];

    foreach ($rows as $ev) {
        if (empty($ev['subject_key'])) {
            continue;
        }
        $subject = $ev['subject_type'] ? qa_lang($ev['subject_key']) : $ev['subject_key'];

        if ((int)$ev['active'] === 1 && (int)$ev['forced'] === 1) {
            $cache['forced'][] = $subject;
        }
        $cache['managed'][$subject] = (int)$ev['eventid'];
        if (isset($ev['custom_body']) && trim($ev['custom_body']) !== '') {
            $cache['bodies'][$subject] = $ev['custom_body'];
        }
    }

    return $cache;
}

/**
 * Overriding core mail sender: skip if user opted out.
 */
function qa_send_notification($userid, $email, $handle, $subject, $body, $subs, $html = false)
{
    if (!$userid) {
        return qa_send_notification_base($userid, $email, $handle, $subject, $body, $subs, $html);
    }

    /* Send email if:
     - event is active AND forced
     - event is unmanaged AND user selected "other mails" (eventid = 0)
     - event is managed AND user selected that eventid
    */

    /* ---------------------------------
       Load all events (cached per-request)
    --------------------------------- */
    [
        'forced'  => $forced_subjects,
        'managed' => $managed_events,
        'bodies'  => $event_bodies,
    ] = em_get_events_config();

    // Override body with custom template if one exists for this subject
    if (isset($event_bodies[$subject])) {
        $body = $event_bodies[$subject];
    }

    /* ---------------------------------
       Forced & active → always send
    --------------------------------- */
    if (in_array($subject, $forced_subjects, true)) {
        return em_send_with_footer(
            $userid, $email, $handle, $subject, $body, $subs, $html
        );
    }

    /* ---------------------------------
       Load user preferences
    --------------------------------- */
    require_once QA_INCLUDE_DIR . 'db/metas.php';
    $prefs_csv = qa_db_usermeta_get($userid, 'emailprefs');

    // New user → allow (except forced already handled above)
    if ($prefs_csv === null) {
        return em_send_with_footer(
            $userid, $email, $handle, $subject, $body, $subs, $html
        );
    }

    // User explicitly disabled everything
    if ($prefs_csv === '') {
        return true;
    }

    $prefs = explode(',', $prefs_csv); //every event id converted into string instead of int.

    /* ---------------------------------
       Case 1: unmanaged event
       → send only if user enabled eventid = 0
    --------------------------------- */
    if (!isset($managed_events[$subject])) {
        if (in_array('0', $prefs, true)) {
            return em_send_with_footer(
                $userid, $email, $handle, $subject, $body, $subs, $html
            );
        }
		error_log("un managed not selected");
        return true;
    }

    /* ---------------------------------
       Case 2: managed but NOT forced
       → send only if user enabled that eventid
    --------------------------------- */
    $eventid = (string)$managed_events[$subject];

    if (in_array($eventid, $prefs, true)) {
        return em_send_with_footer(
            $userid, $email, $handle, $subject, $body, $subs, $html
        );
    }

    /* ---------------------------------
       Otherwise: skip
    --------------------------------- */
    return true;
}

/**
 * Retrieve (or lazily create) the per-user unsubscribe token.
 */
function em_get_unsubscribe_token($userid)
{
    require_once QA_INCLUDE_DIR . 'db/metas.php';
    $token = qa_db_usermeta_get($userid, 'emailtoken');
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        qa_db_usermeta_set($userid, 'emailtoken', $token);
    }
    return $token;
}

/**
 * Apply substitutions, build the HTML template, and send.
 *
 * Resolves missing email/handle from the DB, honours the global suspension
 * flag, then calls qa_send_email() directly — bypassing qa_send_notification_base
 * to avoid the plain-text "Dear [handle]," prefix it prepends before HTML bodies.
 */
function em_send_with_footer($userid, $email, $handle, $subject, $body, $subs, $html)
{
    global $qa_notifications_suspended;
    if ($qa_notifications_suspended > 0) {
        return false;
    }

    // Resolve missing email / handle from DB (mirrors core qa_send_notification logic)
    require_once QA_INCLUDE_DIR . 'util/string.php';
    if (isset($userid)) {
        $needEmail  = !qa_email_validate(@$email);
        $needHandle = empty($handle);
        if ($needEmail || $needHandle) {
            if (QA_FINAL_EXTERNAL_USERS) {
                if ($needHandle) {
                    $handles = qa_get_public_from_userids([$userid]);
                    $handle  = @$handles[$userid];
                }
                if ($needEmail) {
                    $email = qa_get_user_email($userid);
                }
            } else {
                require_once QA_INCLUDE_DIR . 'db/selects.php';
                $account = qa_db_select_with_pending([
                    'columns'   => ['email', 'handle'],
                    'source'    => '^users WHERE userid = #',
                    'arguments' => [$userid],
                    'single'    => true,
                ]);
                if ($needHandle) {
                    $handle = @$account['handle'];
                }
                if ($needEmail) {
                    $email = @$account['email'];
                }
            }
        }
    }

    if (!isset($email) || !qa_email_validate($email)) {
        return false;
    }

    // Merge passed subs with the standard Q2A substitutions
    $allSubs = array_merge([
        '^site_title' => qa_opt('site_title'),
        '^site_url'   => qa_opt('site_url'),
    ], is_array($subs) ? $subs : []);
    if ($handle !== null) {
        $allSubs['^to_handle'] = $handle;
    }

    // Override ^open/^close with safe ASCII markers so em_build_html_email
    // can render quoted content as styled blockquotes rather than plain "…".
    $allSubs['^open']  = '%%EM_OPEN%%';
    $allSubs['^close'] = '%%EM_CLOSE%%';

    // Mark the primary action URL so it becomes a CTA button in the template.
    if (!empty($allSubs['^url'])) {
        $allSubs['^url'] = '%%EM_CTA%%' . $allSubs['^url'] . '%%/EM_CTA%%';
    }

    // Mark the secondary admin/utility URL (moderate, flagged, private-message block…)
    if (!empty($allSubs['^a_url'])) {
        $allSubs['^a_url'] = '%%EM_ALT%%' . $allSubs['^a_url'] . '%%/EM_ALT%%';
    }

    $plainBody    = strtr($body,    $allSubs);
    $cleanSubject = strtr($subject, $allSubs);

    // Per-user unsubscribe URL (empty string for guest/system emails)
    $unsubUrl = '';
    if ($userid) {
        $token    = em_get_unsubscribe_token($userid);
        $code     = em_encrypt_uid($userid, $token);
        $unsubUrl = qa_path_absolute('email-preferences', array('code' => $code));
    }

    $htmlBody = em_build_html_email($cleanSubject, $plainBody, $unsubUrl);

    // Dispatch directly — subs already applied, html=true sends as text/html.
    // Bypassing qa_send_notification_base avoids the plain-text "Dear [handle],\n"
    // prefix that the base injects before every HTML body when a handle is present.
    return qa_send_email([
        'fromemail' => qa_opt('from_email'),
        'fromname'  => qa_opt('site_title'),
        'toemail'   => $email,
        'toname'    => $handle,
        'subject'   => $cleanSubject,
        'body'      => $htmlBody,
        'html'      => true,
    ]);
}

/**
 * Build the full HTML email template.
 *
 * @param string $subject   Already-substituted subject line.
 * @param string $plainBody Already-substituted plain-text body.
 * @param string $unsubUrl  Absolute unsubscribe/preferences URL (or empty).
 * @return string           Complete HTML document ready to send.
 */
function em_build_html_email($subject, $plainBody, $unsubUrl)
{
    $siteUrl   = rtrim(qa_opt('site_url'), '/') . '/';
    $siteTitle = qa_opt('site_title');

    // Convert plain-text body to safe HTML
    $bodyHtml = htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8');

    // ── shorthands from configuration ────────────────────────────────
    $logoUrl    = QA_EMAIL_LOGO_URL;
    $logoHeight = (int)QA_EMAIL_LOGO_HEIGHT;
    $headerBg   = QA_EMAIL_HEADER_BG;
    $headerText = QA_EMAIL_HEADER_TEXT;
    $accent     = QA_EMAIL_ACCENT;
    $footerNote = QA_EMAIL_FOOTER_TEXT;

    // ── 1. Styled blockquotes for ^open…^close content ───────────────
    // The markers survived htmlspecialchars unchanged (% is not an HTML special char).
    $quoteStyle = 'border-left:3px solid ' . $accent . ';background-color:#f8f9fa;'
        . 'padding:10px 16px;margin:12px 0;color:#555555;'
        . 'font-style:italic;border-radius:0 4px 4px 0;';
    $bodyHtml = preg_replace_callback(
        '/%%EM_OPEN%%(.*?)%%EM_CLOSE%%/s',
        function($m) use ($quoteStyle) {
            return '<div style="' . $quoteStyle . '">' . trim($m[1]) . '</div>';
        },
        $bodyHtml
    );

    // ── 2. Primary CTA button for ^url ───────────────────────────────
    $ctaBtnStyle = 'display:inline-block;padding:11px 24px;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;'
        . 'color:#ffffff;text-decoration:none;letter-spacing:0.3px;';
    $bodyHtml = preg_replace_callback(
        '/%%EM_CTA%%(.*?)%%\/EM_CTA%%/s',
        function($m) use ($accent, $ctaBtnStyle) {
            // $m[1] is already htmlspecialchars-encoded — correct for href="…"
            return
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:16px 0;">'
                . '<tr><td style="border-radius:4px;background-color:' . $accent . ';">'
                . '<a href="' . $m[1] . '" target="_blank" style="' . $ctaBtnStyle . '">'
                . 'View &rarr;'
                . '</a></td></tr></table>';
        },
        $bodyHtml
    );

    // ── 3. Secondary utility link for ^a_url ─────────────────────────
    $altLinkStyle = 'color:' . $accent . ';font-size:13px;';
    $bodyHtml = preg_replace_callback(
        '/%%EM_ALT%%(.*?)%%\/EM_ALT%%/s',
        function($m) use ($altLinkStyle) {
            return '<a href="' . $m[1] . '" target="_blank" style="' . $altLinkStyle . '">'
                . $m[1]
                . '</a>';
        },
        $bodyHtml
    );

    $bodyHtml = em_email_linkify($bodyHtml);
    $bodyHtml = nl2br($bodyHtml);

    $siteTitleHtml = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
    $siteUrlHtml   = htmlspecialchars($siteUrl,   ENT_QUOTES, 'UTF-8');
    $subjectHtml   = htmlspecialchars($subject,   ENT_QUOTES, 'UTF-8');
    $prefsUrl      = $unsubUrl !== ''
        ? htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8')
        : $siteUrlHtml . 'account';

    // ── optional logo block ───────────────────────────────────────────
    $logoBlock = '';
    if ($logoUrl !== '') {
        $logoUrlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $logoBlock  =
            '<a href="' . $siteUrlHtml . '" target="_blank"'
            . ' style="display:inline-block;text-decoration:none;">'
            . '<img src="' . $logoUrlEsc . '" alt="' . $siteTitleHtml . '"'
            . ' height="' . $logoHeight . '"'
            . ' style="display:block;border:0;max-height:' . $logoHeight . 'px;height:auto;" /></a>';
    }

    // ── build HTML ────────────────────────────────────────────────────
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{$subjectHtml}</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    @media only screen and (max-width:620px) {
      .em-outer > tbody > tr > td { padding:24px 8px !important; }
      .em-card   { border-radius:0 !important; }
      .em-header { padding:20px !important; }
      .em-body   { padding:24px 20px !important; }
      .em-footer { padding:18px 20px !important; }
    }
    @media (prefers-color-scheme:dark) {
      .em-body   { background-color:#1e1e2e !important; color:#d4d4d4 !important; }
      .em-footer { background-color:#16161e !important; border-top-color:#3a3a4a !important; }
    }
  </style>
</head>

<body style="margin:0;padding:0;background-color:#f2f2f2;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  <!-- Preheader text (visible in inbox preview, hidden in body) -->
  <div style="display:none;font-size:1px;color:#f2f2f2;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    {$subjectHtml} &mdash; {$siteTitleHtml}
  </div>

  <!-- Outer wrapper -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         class="em-outer" style="background-color:#f2f2f2;">
    <tr>
      <td align="center" style="padding:40px 16px;">

        <!-- Email card (600 px max) -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               class="em-card" style="max-width:600px;width:100%;border-radius:8px;overflow:hidden;
                      box-shadow:0 2px 8px rgba(0,0,0,0.08);">


          <!-- ╔══════════════════════╗
               ║        HEADER        ║
               ╚══════════════════════╝ -->
          <tr>
            <td align="center" class="em-header"
                style="background-color:{$headerBg};padding:28px 32px 24px;">

              {$logoBlock}

              <p style="margin:16px 0 0;
                        font-family:Arial,Helvetica,sans-serif;
                        font-size:16px;
                        font-weight:bold;
                        color:{$headerText};
                        letter-spacing:0.3px;
                        line-height:1.4;">
                {$subjectHtml}
              </p>

            </td>
          </tr>


          <!-- ╔══════════════════════╗
               ║         BODY         ║
               ╚══════════════════════╝ -->
          <tr>
            <td class="em-body" style="background-color:#ffffff;padding:36px 40px;
                       font-family:Arial,Helvetica,sans-serif;
                       font-size:15px;
                       line-height:1.75;
                       color:#333333;">
              {$bodyHtml}
            </td>
          </tr>


          <!-- ╔══════════════════════╗
               ║        FOOTER        ║
               ╚══════════════════════╝ -->
          <tr>
            <td align="center" class="em-footer"
                style="background-color:#f7f7f7;
                       border-top:1px solid #e0e0e0;
                       padding:24px 32px;
                       font-family:Arial,Helvetica,sans-serif;
                       font-size:12px;
                       color:#888888;
                       line-height:1.6;">

              <!-- Site link -->
              <p style="margin:0 0 8px;">
                <a href="{$siteUrlHtml}" target="_blank"
                   style="color:{$accent};text-decoration:none;font-weight:bold;">
                  {$siteTitleHtml}
                </a>
              </p>

              <!-- Footer note -->
              <p style="margin:0 0 8px;color:#aaaaaa;">
                {$footerNote}
              </p>

              <!-- Unsubscribe / preferences -->
              <p style="margin:0;">
                <a href="{$prefsUrl}" target="_blank"
                   style="color:#bbbbbb;text-decoration:underline;font-size:11px;">
                  Manage email preferences
                </a>
              </p>

            </td>
          </tr>


        </table>
        <!-- /Email card -->

      </td>
    </tr>
  </table>
  <!-- /Outer wrapper -->

</body>
</html>
HTML;
}

/**
 * Turn bare http/https URLs in already-escaped HTML into clickable links.
 * Operates on htmlspecialchars-encoded text, so & in query strings appear
 * as &amp; — the regex deliberately allows these through.
 */
function em_email_linkify($html)
{
    $style = 'color:' . QA_EMAIL_ACCENT . ';word-break:break-all;';
    // Lookbehind excludes URLs already inside an HTML attribute (href="…", src="…" etc.)
    return preg_replace(
        '#(?<![=\'"])https?://[^\s<>"\']+#i',
        '<a href="$0" target="_blank" style="' . $style . '">$0</a>',
        $html
    );
}