<?php
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
       Load all events
    --------------------------------- */
    $events = qa_db_read_all_assoc(
        qa_db_query_sub(
            'SELECT *
             FROM ^email_events'
        )
    );

    $forced_subjects = [];
    $managed_events  = []; // subject => eventid

    foreach ($events as $ev) {
        if (empty($ev['subject_key'])) {
            continue;
        }
		$original_subject = $ev['subject_key'];

		if ($ev['subject_type']) {
			// language key → translated it into exact subject
			$original_subject = qa_lang($ev['subject_key']);
		}
		
        // Active + forced → always send
        if ((int)$ev['active'] === 1 && (int)$ev['forced'] === 1) {
            $forced_subjects[] = $original_subject;
        }

        // Track managed events (active or not, forced or not)
        $managed_events[$original_subject] = (int)$ev['eventid'];
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
 * Append an unsubscribe footer to the email body, then send.
 */
function em_send_with_footer($userid, $email, $handle, $subject, $body, $subs, $html)
{
    if ($userid) {
        $token = em_get_unsubscribe_token($userid);
        $url   = qa_path_absolute('email-preferences', ['uid' => $userid, 'token' => $token]);

        if ($html) {
            $body .= '<p style="font-size:12px;color:#888;text-align:center;margin-top:20px;'
                   . 'border-top:1px solid #eee;padding-top:10px;">'
                   . 'To manage your email preferences, '
                   . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">click here</a>.'
                   . '</p>';
        } else {
            $body .= "\r\n\r\n--\r\nTo manage your email preferences, visit:\r\n" . $url;
        }
    }

    return qa_send_notification_base($userid, $email, $handle, $subject, $body, $subs, $html);
}