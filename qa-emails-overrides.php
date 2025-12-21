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
        return qa_send_notification_base(
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
        return qa_send_notification_base(
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
            return qa_send_notification_base(
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
        return qa_send_notification_base(
            $userid, $email, $handle, $subject, $body, $subs, $html
        );
    }

    /* ---------------------------------
       Otherwise: skip
    --------------------------------- */
    return true;
}