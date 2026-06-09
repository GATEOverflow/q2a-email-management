<?php
/**
 * Standalone email-preferences page.
 * Accessible without login — authenticated by an encrypted code parameter.
 * URL: {site}/email-preferences?code=ENCRYPTED_BASE64
 */
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

require_once QA_EMAIL_MGMT_DIR . 'qa-email-helpers.php';

class qa_email_unsubscribe_page
{
    public function match_request($request)
    {
        return ($request === 'email-preferences');
    }

    public function process_request($request)
    {
        require_once QA_INCLUDE_DIR . 'db/metas.php';

        $code = trim((string)qa_get('code'));

        $qa_content          = qa_content_prepare();
        $qa_content['title'] = qa_lang_html('emailopt/email_notifications_header');

        /* --------------------------------------------------
           Decrypt code → uid + token
        -------------------------------------------------- */
        $decoded = null;
        if ($code !== '') {
            $decoded = em_decrypt_uid($code);
        }

        if ($decoded === null) {
            $qa_content['error'] = qa_lang_html('emailopt/unsub_invalid_link');
            return $qa_content;
        }

        $uid   = $decoded['uid'];
        $token = $decoded['token'];

        /* --------------------------------------------------
           Validate token against stored value
        -------------------------------------------------- */
        $stored = qa_db_usermeta_get($uid, 'emailtoken');

        if (!$stored || !hash_equals($stored, $token)) {
            $qa_content['error'] = qa_lang_html('emailopt/unsub_invalid_link');
            return $qa_content;
        }

        /* --------------------------------------------------
           One-click "unsubscribe all" (GET action)
        -------------------------------------------------- */
        if ((string)qa_get('action') === 'unsubscribe_all') {
            qa_db_usermeta_set($uid, 'emailprefs', '');
            $qa_content['custom'] =
                '<p style="color:#155724;background:#d4edda;padding:12px;border-radius:4px;">'
                . qa_lang_html('emailopt/unsub_all_done')
                . '</p>';
            return $qa_content;
        }

        /* --------------------------------------------------
           Load manageable events (active, not forced)
        -------------------------------------------------- */
        $events = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT eventid, user_title, active, forced
                 FROM ^email_events
                 ORDER BY eventid ASC'
            )
        );

        $manageable = array();
        foreach ($events as $ev) {
            if ((int)$ev['active'] === 1 && (int)$ev['forced'] === 0) {
                $manageable[] = $ev;
            }
        }

        /* --------------------------------------------------
           Handle form POST save
        -------------------------------------------------- */
        $saved_ok = false;
        if (qa_clicked('save_emailprefs_unsub')) {
            $vals = qa_post_array('emailprefs');
            $csv  = is_array($vals)
                ? implode(',', array_map('intval', $vals))
                : '';
            qa_db_usermeta_set($uid, 'emailprefs', $csv);
            $saved_ok    = true;
            $csv_current = $csv;
        } else {
            $csv_current = qa_db_usermeta_get($uid, 'emailprefs');
        }

        $saved_prefs = is_string($csv_current) ? explode(',', $csv_current) : array();
        $is_new_user = ($csv_current === null && !$saved_ok);

        /* --------------------------------------------------
           Build form HTML
        -------------------------------------------------- */
        $notice = '';
        if ($saved_ok) {
            $notice = '<p style="color:#155724;background:#d4edda;padding:10px;'
                . 'border-radius:4px;margin-bottom:15px;">'
                . qa_lang_html('emailopt/pref_saved')
                . '</p>';
        }

        $rows = '';
        foreach ($manageable as $ev) {
            $eid     = (int)$ev['eventid'];
            $checked = $is_new_user
                ? true
                : in_array((string)$eid, $saved_prefs, true);

            $rows .= '<div style="margin:6px 0;"><label>'
                . '<input type="checkbox" name="emailprefs[]" value="' . $eid . '"'
                . ($checked ? ' checked' : '') . '> '
                . qa_html($ev['user_title'])
                . '</label></div>';
        }

        // Virtual option: eventid = 0 → any other (unmanaged) emails
        $checked0 = $is_new_user ? true : in_array('0', $saved_prefs, true);
        $rows .= '<div style="margin:6px 0;"><label>'
            . '<input type="checkbox" name="emailprefs[]" value="0"'
            . ($checked0 ? ' checked' : '') . '> '
            . qa_lang_html('emailopt/other_mails')
            . '</label></div>';

        // "Unsubscribe from all" convenience link
        $unsub_all_url = qa_path_absolute('email-preferences', array(
            'code'   => $code,
            'action' => 'unsubscribe_all',
        ));

        $unsubscribe_link = '<p style="margin-top:14px;font-size:13px;">'
            . '<a href="' . qa_html($unsub_all_url) . '" '
            . 'onclick="return confirm(' . json_encode(qa_lang('emailopt/unsub_all_confirm')) . ');">'
            . qa_lang_html('emailopt/unsub_all_link')
            . '</a></p>';

        $body_html = $notice
            . '<p style="margin-bottom:14px;">' . qa_lang_html('emailopt/unsub_page_intro') . '</p>'
            . $rows
            . $unsubscribe_link;

        // Form action preserves the code param across the POST round-trip
        $form_action = htmlspecialchars(
            qa_path('email-preferences', array('code' => $code)),
            ENT_QUOTES,
            'UTF-8'
        );

        $qa_content['form'] = array(
            'tags'    => 'method="post" action="' . $form_action . '"',
            'style'   => 'wide',
            'fields'  => array(
                array('type' => 'static', 'value' => $body_html),
            ),
            'buttons' => array(
                array(
                    'label' => qa_lang_html('emailopt/save_email_preferences'),
                    'tags'  => 'name="save_emailprefs_unsub"',
                ),
            ),
        );

        return $qa_content;
    }
}
