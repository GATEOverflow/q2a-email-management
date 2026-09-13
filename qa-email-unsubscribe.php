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
        $qa_content['title'] = qa_lang_html('emailopt/email_notifications_header') . ' - ' . qa_html(qa_userid_to_handle($uid));

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

            // Also opt the user out of every access-list email type.
            // Writing an empty string here would NOT do this — an empty/
            // missing value is treated as "no preference saved yet" by the
            // form below, which defaults back to fully subscribed. We need
            // an explicit listid:0 entry per list to record a real opt-out.
            $unsub_accesslists_csv = qa_db_usermeta_get($uid, 'accesslists');
            $unsub_list_ids = ($unsub_accesslists_csv && strlen(trim($unsub_accesslists_csv)) > 0)
                ? array_filter(array_map('intval', explode(',', $unsub_accesslists_csv)))
                : array();

            if (function_exists('qa_exam_get_inactive_accesslist_ids')) {
                $unsub_list_ids = array_values(array_diff(
                    $unsub_list_ids,
                    qa_exam_get_inactive_accesslist_ids()
                ));
            }

            $unsub_al_parts = array();
            foreach ($unsub_list_ids as $unsub_lid) {
                $unsub_al_parts[] = $unsub_lid . ':0';
            }
            qa_db_usermeta_set($uid, 'accesslist_emailprefs', implode(',', $unsub_al_parts));

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

            // Save access list email preferences (only if section was rendered)
            if (qa_post_text('accesslist_emailprefs_present')) {
                $al_prefs_raw = qa_post_text('accesslist_emailprefs');
                error_log("Raw access list prefs: " . $al_prefs_raw);
                $al_prefs = array();

                // Get the user's current access-list IDs.
                $user_accesslists_csv = qa_db_usermeta_get($uid, 'accesslists');

                $user_list_ids = ($user_accesslists_csv && strlen(trim($user_accesslists_csv)) > 0)
                    ? array_filter(array_map('intval', explode(',', $user_accesslists_csv)))
                    : array();
                
                // Add access lists owned by me
                $owned_list_ids = qa_db_read_all_values(
                    qa_db_query_sub(
                        "SELECT listid
                            FROM ^accesslists
                            WHERE userid = #",
                        $uid
                    )
                );

                $user_list_ids = array_values(array_unique(array_merge($user_list_ids,array_map('intval', $owned_list_ids))));
                

                // Remove inactive access lists.
                if (function_exists('qa_exam_get_inactive_accesslist_ids')) {
                    $user_list_ids = array_values(array_diff(
                        $user_list_ids,
                        qa_exam_get_inactive_accesslist_ids()
                    ));
                }

                // Convert to a lookup array for fast validation.
                $valid_user_lists = array_fill_keys($user_list_ids, true);

                $total_lists = array();

                if (!empty($user_list_ids)) {

                    $ids_str = implode(',', $user_list_ids);

                    //removing the lists that are not avialble in the access list table
                    $existing_lists = qa_db_read_all_assoc(
                        qa_db_query_sub(
                            "SELECT listid, userid AS ownerid
                            FROM ^accesslists
                            WHERE listid IN ($ids_str)
                            ORDER BY name ASC"
                        )
                    );

                    $total_lists = array();
                    
                    foreach ($existing_lists as $row) {
                        $total_lists[(int)$row['listid']] = (int)$row['ownerid'];
                    }

                }

                // Expected format: 12:31,15:27,20:17
                if ($al_prefs_raw !== '') {
                    // Validate submitted preferences.
                    foreach (explode(',', $al_prefs_raw) as $item) {

                    error_log("Processing access list pref item: " . $item);

                        $parts = explode(':', $item, 2);

                        if (count($parts) !== 2) {
                            continue;
                        }

                        $lid  = (int)$parts[0];
                        $mask = (int)$parts[1];

                        // Invalid list ID.
                        if ($lid <= 0) {
                            continue;
                        }

                        // Invalid mask.
                        if ($mask < 0 || $mask > 31) {
                            continue;
                        }

                        // List does not exist in the access list table.
                        if (!isset($total_lists[$lid])) {
                            continue;
                        }

                        //List exist but neither belong to this user's current access lists nor owned.
                        if ($uid != $total_lists[$lid] && !isset($valid_user_lists[$lid])) {
                            continue;
                        }

                        // Bit 16 (Subscriber blocked) is only available to the owner of the access list.
                        if ($uid != $total_lists[$lid]) {
                            $mask &= 15;
                        }

                        $al_prefs[$lid] = $mask;
                    }

                }

                // Every Valid listid kept to 15 for non-owners and 31 for owners if no preference was submitted for that list.
                foreach($total_lists as $lid => $ownerid){
                    if(!isset($al_prefs[$lid])){
                        $al_prefs[$lid] = ($uid == $ownerid) ? 31 : 15;
                    }
                }


                // Rebuild a clean string rather than trusting the submitted string directly.
                $al_clean_parts = array();

                foreach ($al_prefs as $lid => $mask) {
                    $al_clean_parts[] = $lid . ':' . $mask;
                }

                $al_clean_csv = implode(',', $al_clean_parts);
                error_log("Cleaned access list prefs: " . $al_clean_csv);

                qa_db_usermeta_set(
                    $uid,
                    'accesslist_emailprefs',
                    $al_clean_csv
                );
            }

            $saved_ok    = true;
            $csv_current = $csv;
        } else {
            $csv_current = qa_db_usermeta_get($uid, 'emailprefs');
        }

        $saved_prefs = is_string($csv_current) ? explode(',', $csv_current) : array();
        $is_new_user = ($csv_current === null && !$saved_ok);

        /* --------------------------------------------------
           Small helper: lowercase text safely for search attrs
        -------------------------------------------------- */
        $qaep_lc = function ($text) {
            return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        };

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

        /* ---- Main "Notifications" rows ---- */
        $rows = '';
        foreach ($manageable as $ev) {
            $eid     = (int)$ev['eventid'];
            $checked = $is_new_user
                ? true
                : in_array((string)$eid, $saved_prefs, true);

            $rows .= '<div class="qaep-row" data-search="' . qa_html($qaep_lc($ev['user_title'])) . '"><label>'
                . '<input type="checkbox" name="emailprefs[]" value="' . $eid . '"'
                . ($checked ? ' checked' : '') . '> '
                . qa_html($ev['user_title'])
                . '</label></div>';
        }

        // Virtual option: eventid = 0 → any other (unmanaged) emails
        $checked0        = $is_new_user ? true : in_array('0', $saved_prefs, true);
        $other_label_raw = qa_lang('emailopt/other_mails');
        $rows .= '<div class="qaep-row" data-search="' . qa_html($qaep_lc($other_label_raw)) . '"><label>'
            . '<input type="checkbox" name="emailprefs[]" value="0"'
            . ($checked0 ? ' checked' : '') . '> '
            . qa_html($other_label_raw)
            . '</label></div>';

        $main_section_html =
              '<div class="qaep-section" id="qaep-main-section">'
            .   '<div class="qaep-section-header">'
            .     '<span class="qaep-section-title">Notifications</span>'
            .     '<span class="qaep-bulk-actions">'
            .       '<a href="javascript:void(0)" data-qaep-action="select-all" data-qaep-scope="#qaep-main-rows input[type=checkbox]">Select all</a>'
            .       ' &middot; '
            .       '<a href="javascript:void(0)" data-qaep-action="deselect-all" data-qaep-scope="#qaep-main-rows input[type=checkbox]">Deselect all</a>'
            .     '</span>'
            .     '<span class="qaep-count" id="qaep-main-count"></span>'
            .   '</div>'
            .   '<div id="qaep-main-rows">' . $rows . '</div>'
            . '</div>';

        /* ---- Access List email preferences ---- */
        $al_rows_html          = '';
        $user_accesslists_csv  = qa_db_usermeta_get($uid, 'accesslists');
        $user_list_ids = ($user_accesslists_csv && strlen(trim($user_accesslists_csv)) > 0)
            ? array_filter(array_map('intval', explode(',', $user_accesslists_csv)))
            : [];
        
        // Add access lists owned by me
        $owned_list_ids = qa_db_read_all_values(
            qa_db_query_sub(
                "SELECT listid
                    FROM ^accesslists
                    WHERE userid = #",
                $uid
            )
        );

        $user_list_ids = array_values(array_unique(array_merge($user_list_ids,array_map('intval', $owned_list_ids))));

        // Remove inactive access lists
        if (function_exists('qa_exam_get_inactive_accesslist_ids')) {
            $user_list_ids = array_values(array_diff(
                $user_list_ids,
                qa_exam_get_inactive_accesslist_ids()
            ));
        }

        if (!empty($user_list_ids)) {

            $ids_str = implode(',', $user_list_ids);

            $al_db_rows = qa_db_read_all_assoc(
                qa_db_query_sub(
                    "SELECT listid, name, userid as ownerid
                     FROM ^accesslists
                     WHERE listid IN ($ids_str)
                     ORDER BY name ASC"
                )
            );

            if (!empty($al_db_rows)) {

                // Load user access list email prefs
                $al_prefs_csv = qa_db_usermeta_get($uid, 'accesslist_emailprefs');
                $al_is_new    = ($al_prefs_csv === null);

                $al_saved = array();

                if (!$al_is_new && is_string($al_prefs_csv) && trim($al_prefs_csv) !== '') {

                    foreach (explode(',', $al_prefs_csv) as $item) {

                        $parts = explode(':', $item, 2);

                        if (count($parts) !== 2) {
                            continue;
                        }

                        $lid  = (int)$parts[0];
                        $mask = (int)$parts[1];

                        // Valid list ID and valid 5-bit mask
                        if ($lid > 0 && $mask >= 0 && $mask <= 31) {
                            $al_saved[$lid] = $mask;
                        }
                    }
                }

                $email_types = array(
                    1  => 'Welcome Email',
                    2  => 'Addition of Exam',
                    4  => 'Custom Mails',
                    8  => 'Blocked from Access List',
                    16 => 'Subscriber blocked due to Excessive Tests on a single day',
                );

                $al_rows_html .=
                      '<div class="qaep-section" id="qaep-al-section">'
                    .   '<div class="qaep-section-header">'
                    .     '<span class="qaep-section-title">Access List Emails</span>'
                    .     '<span class="qaep-bulk-actions">'
                    .       '<a href="javascript:void(0)" data-qaep-action="select-all" data-qaep-scope=".accesslist-email-pref">Select all</a>'
                    .       ' &middot; '
                    .       '<a href="javascript:void(0)" data-qaep-action="deselect-all" data-qaep-scope=".accesslist-email-pref">Deselect all</a>'
                    .     '</span>'
                    .     '<span class="qaep-count" id="qaep-al-count"></span>'
                    .   '</div>'
                    .   '<p class="qaep-al-intro">Choose which access list emails you want to receive:</p>'
                    .   '<input type="hidden" name="accesslist_emailprefs_present" value="1">'
                    .   '<input type="hidden" name="accesslist_emailprefs" id="accesslist_emailprefs" value="">';

                foreach ($al_db_rows as $al) {

                    $lid      = (int)$al['listid'];
                    $ownerid  = (int)$al['ownerid'];
                    $is_owner = ($uid == $ownerid);

                    if (array_key_exists($lid, $al_saved)) {
                        // User has an explicitly saved preference,
                        // including mask = 0.
                        $mask = (int)$al_saved[$lid];

                        // Non-owner cannot have Subscriber blocked.
                        if (!$is_owner) {
                            $mask &= 15;
                        }

                    } else {

                        // No preference saved for this particular access list. This can happen either because:
                        // 1. The user is new, or
                        // 2. This access list was added after the user saved preferences.
                        $mask = $is_owner ? 31 : 15;

                    }

                    $al_rows_html .=
                          '<div class="qaep-al-card" data-listid="' . $lid . '" data-search="' . qa_html($qaep_lc($al['name'])) . '">'
                        .   '<div class="qaep-al-card-header">'
                        .     '<span class="qaep-al-card-title">' . qa_html($al['name']) . '</span>'
                        .     '<span class="qaep-bulk-actions">'
                        .       '<a href="javascript:void(0)" data-qaep-action="select-all" data-qaep-scope=\'.accesslist-email-pref[data-listid="' . $lid . '"]\'>Select all</a>'
                        .       ' &middot; '
                        .       '<a href="javascript:void(0)" data-qaep-action="deselect-all" data-qaep-scope=\'.accesslist-email-pref[data-listid="' . $lid . '"]\'>Deselect all</a>'
                        .     '</span>'
                        .     '<span class="qaep-count" id="qaep-al-count-' . $lid . '"></span>'
                        .   '</div>';

                    foreach ($email_types as $bit => $type_label) {

                        if (!$is_owner && $bit === 16) {
                            // Subscriber blocked email is only relevant to the owner of the access list
                            continue;
                        }
                        if($bit === 1 || $bit === 8){
                            // Welcome email and Blocked from Access List are mandatory and cannot be disabled
                            continue;
                        }
                        $checked = ($mask & $bit) ? ' checked' : '';

                        $al_rows_html .=
                              '<div class="qaep-al-row">'
                            .   '<label>'
                            .     '<input type="checkbox"'
                            .     ' class="accesslist-email-pref"'
                            .     ' data-listid="' . $lid . '"'
                            .     ' data-bit="' . $bit . '"'
                            .     $checked
                            .     '> '
                            .     qa_html($type_label)
                            .   '</label>'
                            . '</div>';
                    }

                    $al_rows_html .= '</div>'; // .qaep-al-card
                }

                $al_rows_html .= '</div>'; // .qaep-section#qaep-al-section
            }
        }

        // "Unsubscribe from all" convenience link
        $unsub_all_url = qa_path_absolute('email-preferences', array(
            'code'   => $code,
            'action' => 'unsubscribe_all',
        ));

        // Escape the JSON string for safe embedding inside a double-quoted
        // HTML attribute — json_encode() itself wraps the text in double
        // quotes, which would otherwise collide with the onclick="..."
        // delimiters and truncate the attribute early.
        $unsub_all_confirm_js = htmlspecialchars(
            json_encode(qa_lang('emailopt/unsub_all_confirm')),
            ENT_QUOTES,
            'UTF-8'
        );

        $unsubscribe_link = '<p class="qaep-unsub-all">'
            . '<a href="' . qa_html($unsub_all_url) . '" '
            . 'onclick="return confirm(' . $unsub_all_confirm_js . ');">'
            . qa_lang_html('emailopt/unsub_all_link')
            . '</a></p>';

        /* --------------------------------------------------
           Styles + behaviour (search, bulk select, counts,
           confirm-before-empty-submit). Static — safe as a
           nowdoc, no PHP interpolation needed inside.
        -------------------------------------------------- */
        $style_block = <<<'EOT'
<style>
.qaep-wrap { max-width: 720px; }
.qaep-wrap p.qaep-intro { margin-bottom: 14px; }
.qaep-search { margin-bottom: 16px; }
.qaep-search input[type="text"] {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
}
.qaep-section {
    margin-bottom: 22px;
    border: 1px solid #e2e2e2;
    border-radius: 8px;
    overflow: hidden;
}
.qaep-section-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    background: #f6f7f8;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e2e2;
}
.qaep-section-title { font-weight: bold; margin-right: auto; }
.qaep-bulk-actions a { text-decoration: none; }
.qaep-bulk-actions a:hover { text-decoration: underline; }
.qaep-count { font-size: 12px; color: #666; white-space: nowrap; }
.qaep-al-intro { color: #666; font-size: 13px; padding: 10px 14px 0; margin: 0; }
#qaep-main-rows { padding: 6px 14px 12px; }
.qaep-row { margin: 6px 0; }
.qaep-al-card {
    margin: 12px 14px;
    padding: 0 0 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    overflow: hidden;
}
.qaep-al-card-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    background: #fafafa;
    padding: 8px 10px;
    border-bottom: 1px solid #eee;
}
.qaep-al-card-title { font-weight: bold; margin-right: auto; }
.qaep-al-row { margin: 6px 10px; }
.qaep-unsub-all { margin-top: 16px; font-size: 13px; }
.qaep-unsub-all a { color: #b02a2a; }
</style>
EOT;

        $shared_script = <<<'EOT'
<script>
(function () {

    function qaepUpdateAccessListHiddenField() {
        var hidden = document.getElementById('accesslist_emailprefs');
        if (!hidden) {
            return;
        }

        var prefs = {};

        document.querySelectorAll('.accesslist-email-pref').forEach(function (checkbox) {
            var lid = parseInt(checkbox.getAttribute('data-listid'), 10);
            var bit = parseInt(checkbox.getAttribute('data-bit'), 10);

            if (!lid || !bit) {
                return;
            }

            if (!prefs[lid]) {
                prefs[lid] = 0;
            }

            if (checkbox.checked) {
                prefs[lid] |= bit;
            }
        });

        var values = [];
        Object.keys(prefs).forEach(function (lid) {
            values.push(lid + ':' + prefs[lid]);
        });

        hidden.value = values.join(',');
    }

    function qaepUpdateCounts() {
        var mainBoxes = document.querySelectorAll('#qaep-main-rows input[type=checkbox]');
        var mainCountEl = document.getElementById('qaep-main-count');
        if (mainCountEl) {
            var mainChecked = 0;
            mainBoxes.forEach(function (cb) { if (cb.checked) mainChecked++; });
            mainCountEl.textContent = mainChecked + ' of ' + mainBoxes.length + ' selected';
        }

        var alBoxes = document.querySelectorAll('.accesslist-email-pref');
        if (alBoxes.length) {
            var alCountEl = document.getElementById('qaep-al-count');
            var alChecked = 0;
            var cardTotals = {};
            var cardChecked = {};

            alBoxes.forEach(function (cb) {
                var lid = cb.getAttribute('data-listid');
                cardTotals[lid] = (cardTotals[lid] || 0) + 1;
                if (cb.checked) {
                    alChecked++;
                    cardChecked[lid] = (cardChecked[lid] || 0) + 1;
                }
            });

            if (alCountEl) {
                alCountEl.textContent = alChecked + ' of ' + alBoxes.length + ' selected';
            }

            Object.keys(cardTotals).forEach(function (lid) {
                var el = document.getElementById('qaep-al-count-' + lid);
                if (el) {
                    el.textContent = (cardChecked[lid] || 0) + ' of ' + cardTotals[lid] + ' selected';
                }
            });
        }
    }

    function qaepRefresh() {
        qaepUpdateAccessListHiddenField();
        qaepUpdateCounts();
    }

    // Recalculate on any relevant checkbox change.
    document.addEventListener('change', function (e) {
        if (e.target.matches('#qaep-main-rows input[type=checkbox], .accesslist-email-pref')) {
            qaepRefresh();
        }
    });

    // Bulk select-all / deselect-all links.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-qaep-action]');
        if (!link) {
            return;
        }
        e.preventDefault();

        var scope   = link.getAttribute('data-qaep-scope');
        var checked = (link.getAttribute('data-qaep-action') === 'select-all');

        document.querySelectorAll(scope).forEach(function (cb) {
            if (cb.type === 'checkbox') {
                cb.checked = checked;
            }
        });

        qaepRefresh();
    });

    // Live search across notifications and access list cards.
    var searchInput = document.getElementById('qaep-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();

            document.querySelectorAll('#qaep-main-rows .qaep-row').forEach(function (row) {
                var text = row.getAttribute('data-search') || '';
                row.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
            });

            document.querySelectorAll('.qaep-al-card').forEach(function (card) {
                var text = card.getAttribute('data-search') || '';
                card.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    qaepRefresh();
})();

function qaepConfirmSubmit() {
    var mainBoxes = document.querySelectorAll('#qaep-main-rows input[type=checkbox]');
    var anyMainChecked = Array.prototype.some.call(mainBoxes, function (cb) { return cb.checked; });

    var alBoxes = document.querySelectorAll('.accesslist-email-pref');
    var anyAlChecked = Array.prototype.some.call(alBoxes, function (cb) { return cb.checked; });

    if (!anyMainChecked && !anyAlChecked) {
        return window.confirm('You are about to opt out of every notification. Continue?');
    }

    return true;
}
</script>
EOT;

        $body_html = $style_block
            . '<div class="qaep-wrap">'
            . $notice
            . '<div class="qaep-search">'
            . '<input type="text" id="qaep-search-input" placeholder="Search notifications or access lists…" aria-label="Search email preferences">'
            . '</div>'
            . '<p class="qaep-intro">' . qa_lang_html('emailopt/unsub_page_intro') . '</p>'
            . $main_section_html
            . $al_rows_html
            . $unsubscribe_link
            . '</div>'
            . $shared_script;

        // Form action preserves the code param across the POST round-trip
        $form_action = htmlspecialchars(
            qa_path('email-preferences', array('code' => $code)),
            ENT_QUOTES,
            'UTF-8'
        );

        $qa_content['form'] = array(
            'tags'    => 'method="post" action="' . $form_action . '" onsubmit="return qaepConfirmSubmit();"',
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