<?php

class qa_html_theme_layer extends qa_html_theme_base
{
    public function doctype()
    {
        if ($this->template === 'account') {
            $form = $this->email_prefs_generate();
            if ($form) {
                $this->content['form_emailprefs'] = $form;
            }
        }

        parent::doctype();
    }

private function email_prefs_generate()
{
    $userid = qa_get_logged_in_userid();
    $logged_level = qa_get_logged_in_level();
    if (!$userid) return null;

    require_once QA_INCLUDE_DIR . 'db/metas.php';

    // SAVE HANDLER
    if (qa_clicked('save_emailprefs')) {

        $vals = qa_post_array('emailprefs');
        $csv  = is_array($vals) ? implode(',', $vals) : '';

        qa_db_usermeta_set($userid, 'emailprefs', $csv);

        // Save access list email preferences (only if section was rendered)
        if (qa_post_text('accesslist_emailprefs_present')) {
            $al_prefs_raw = qa_post_text('accesslist_emailprefs');
            $al_prefs = array();

            // Expected format: 12:31,15:27,20:17
            if ($al_prefs_raw !== '') {

                // Get the user's current access-list IDs.
                $user_accesslists_csv = qa_db_usermeta_get($userid, 'accesslists');

                $user_list_ids = ($user_accesslists_csv && strlen(trim($user_accesslists_csv)) > 0)
                    ? array_filter(array_map('intval', explode(',', $user_accesslists_csv)))
                    : array();

                // Remove inactive access lists.
                if (function_exists('qa_exam_get_inactive_accesslist_ids')) {
                    $user_list_ids = array_values(array_diff(
                        $user_list_ids,
                        qa_exam_get_inactive_accesslist_ids()
                    ));
                }

                // Convert to a lookup array for fast validation.
                $valid_user_lists = array_fill_keys($user_list_ids, true);

                // Get owners of all lists belonging to this user in one query.
                $owner_ids = array();

                if (!empty($user_list_ids)) {

                    $ids_str = implode(',', $user_list_ids);

                    $owner_rows = qa_db_read_all_assoc(
                        qa_db_query_sub(
                            "SELECT listid, userid AS ownerid
                            FROM ^accesslists
                            WHERE listid IN ($ids_str)"
                        )
                    );

                    foreach ($owner_rows as $row) {
                        $owner_ids[(int)$row['listid']] = (int)$row['ownerid'];
                    }
                }

                // Validate submitted preferences.
                foreach (explode(',', $al_prefs_raw) as $item) {

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

                    // List does not belong to this user's current access lists.
                    if (!isset($valid_user_lists[$lid])) {
                        continue;
                    }

                    // Access list record wasn't found.
                    if (!isset($owner_ids[$lid])) {
                        continue;
                    }

                    // Bit 16 (Subscriber blocked) is only available to the owner of the access list.
                    if ($userid != $owner_ids[$lid]) {
                        $mask &= 15;
                    }

                    $al_prefs[$lid] = $mask;
                }
            }

            // Rebuild a clean string rather than trusting the submitted string directly.
            $al_clean_parts = array();

            foreach ($al_prefs as $lid => $mask) {
                $al_clean_parts[] = $lid . ':' . $mask;
            }

            $al_clean_csv = implode(',', $al_clean_parts);

            qa_db_usermeta_set(
                $userid,
                'accesslist_emailprefs',
                $al_clean_csv
            );
        }
			

        qa_redirect($this->request, ['email_ok' => '1']);
    }

    /* ------------------------------
       LOAD EVENTS (DB)
    ------------------------------ */
    $events = qa_db_read_all_assoc(
        qa_db_query_sub(
            'SELECT eventid, user_title, forced, active, min_level
             FROM ^email_events
             ORDER BY eventid ASC'
        )
    );

    /* ------------------------------
       FILTER MANAGEABLE EVENTS ONLY
       (forced events are COMPLETELY hidden)
    ------------------------------ */
    $manageable = [];

    foreach ($events as $ev) {
        if (
            (int)$ev['active'] === 1 &&
            (int)$ev['forced'] === 0 &&
            $logged_level >= (int)$ev['min_level']
        ) {
            $manageable[] = $ev;
        }
    }

    /* ------------------------------
       LOAD USER PREFS
    ------------------------------ */
    $csv = qa_db_usermeta_get($userid, 'emailprefs');
    $saved = is_string($csv) ? explode(',', $csv) : [];

    $is_new_user = ($csv === null);

    /* ------------------------------
       UI (CSS + JS)
    ------------------------------ */
    $html = '
			<style>
			
			
	
			/* Toast Notification */
					#em-toast {
						visibility:hidden;
						min-width:250px;
						background:#0f5132;
						color:white;
						text-align:center;
						padding:12px;
						border-radius:8px;
						position:fixed;
						z-index:99999;
						left:50%;
						transform:translateX(-50%);
						bottom:30px;
						font-size:14px;
						opacity:0;
						transition:opacity .4s ease, bottom .4s ease;
					}
					#em-toast.show {
						visibility:visible;
						opacity:1;
						bottom:60px;
					}
        .em-block {
            padding:10px;
            border:1px solid #ccc;
            border-radius:6px;
            margin-bottom:15px;
        }
        .em-title {
            cursor:pointer;
            font-weight:bold;
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:6px;
        }
        .em-title:hover { background:#f7f7f7; }
        .em-arrow { font-weight:bold; }
        .em-content {
            overflow:hidden;
            max-height:0;
            transition:max-height .35s ease;
            margin-left:10px;
        }
        .em-content.open { max-height:600px; }
        .em-search {
            width:98%;
            padding:6px;
            margin-bottom:8px;
        }
        .em-mini {
            padding:5px 10px;
            font-size:12px;
            cursor:pointer;
            margin-right:4px;
        }
        .em-row { margin:4px 0; }
    </style>

    <script>
        function toggleEM(id, header){
            var el = document.getElementById(id);
            var arrow = header.querySelector(".em-arrow");

            if (el.classList.contains("open")) {
                el.classList.remove("open");
                arrow.textContent = "▶";
            } else {
                el.classList.add("open");
                arrow.textContent = "▼";
            }
        }
        function em_select(cls, val){
            document.querySelectorAll("." + cls)
                .forEach(e => e.checked = val);
        }
        function em_filter(input, cls){
            var txt = input.value.toLowerCase();
            document.querySelectorAll("." + cls).forEach(row => {
                row.style.display =
                    row.innerText.toLowerCase().includes(txt)
                    ? "block" : "none";
            });
        }
		
		function em_toast(msg){
					var t=document.getElementById("em-toast");
					t.textContent=msg;
					t.classList.add("show");
					setTimeout(()=>{ t.classList.remove("show"); }, 2500);
				}
		function updateAccessListEmailPrefs() {
			var prefs = {};
			document.querySelectorAll(".accesslist-email-pref").forEach(function(checkbox) {

				var lid = parseInt(checkbox.getAttribute("data-listid"), 10);
				var bit = parseInt(checkbox.getAttribute("data-bit"), 10);

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

			Object.keys(prefs).forEach(function(lid) {
				values.push(lid + ":" + prefs[lid]);
			});

			var field = document.getElementById("accesslist_emailprefs");

			if (field) {
				field.value = values.join(",");
			}
		}
		
		document.addEventListener("DOMContentLoaded", function() {
			document.querySelectorAll(".accesslist-email-pref").forEach(function(checkbox) {
				checkbox.addEventListener("change", function() {
					updateAccessListEmailPrefs();
				});
			});
			updateAccessListEmailPrefs();
		});
		
    </script>
	
	<div id="em-toast"></div>
    ';

    /* ------------------------------
       MAIN COLLAPSIBLE BLOCK
       (collapsed by default)
    ------------------------------ */
    $html .= '
    <div class="em-block">
        <div class="em-title" onclick="toggleEM(\'emA\', this)">
            <span>'.qa_lang_html("emailopt/email_notifications_header").'</span>
            <span class="em-arrow">▶</span>
        </div>

        <div id="emA" class="em-content">

            <input type="text" class="em-search"
                   placeholder="Search…"
                   onkeyup="em_filter(this, \'em-row\')">

            <button type="button" class="em-mini"
                onclick="em_select(\'em-check\', true)">Select All</button>

            <button type="button" class="em-mini"
                onclick="em_select(\'em-check\', false)">Deselect All</button>
    ';

    /* ------------------------------
       MANAGEABLE EVENTS (DB)
    ------------------------------ */
    foreach ($manageable as $ev) {

        $eventid = (int)$ev['eventid'];
        $checked = $is_new_user
            ? true
            : in_array((string)$eventid, $saved, true);

        $html .= '
            <div class="em-row">
                <label>
                    <input type="checkbox" class="em-check"
                           name="emailprefs[]" value="'.$eventid.'"
                           '.($checked ? 'checked' : '').'>
                    '.qa_html($ev['user_title']).'
                </label>
            </div>';
    }

    /* ------------------------------
       VIRTUAL OPTION: eventid = 0
    ------------------------------ */
    $checked = $is_new_user
        ? true
        : in_array('0', $saved, true);

    $html .= '
            <div class="em-row">
                <label>
                    <input type="checkbox" class="em-check"
                           name="emailprefs[]" value="0"
                           '.($checked ? 'checked' : '').'>
                    '.qa_lang_html("emailopt/other_mails").'
                </label>
            </div>
        </div>
    </div>
    ';

    // ACCESS LIST EMAIL PREFERENCES
    $user_accesslists_csv = qa_db_usermeta_get($userid, 'accesslists');
    $user_list_ids = ($user_accesslists_csv && strlen(trim($user_accesslists_csv)) > 0)
        ? array_filter(array_map('intval', explode(',', $user_accesslists_csv)))
        : [];
    // Add access lists owned by me
    $owned_list_ids = qa_db_read_all_values(
        qa_db_query_sub(
            "SELECT listid
                FROM ^accesslists
                WHERE userid = #",
            $userid
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
        // Load access list details
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
			$al_prefs_csv = qa_db_usermeta_get($userid, 'accesslist_emailprefs');
            $al_is_new = ($al_prefs_csv === null);

			$al_saved = array();

			if (!$al_is_new && is_string($al_prefs_csv) && trim($al_prefs_csv) !== '') {

				foreach (explode(',', $al_prefs_csv) as $item) {

					$parts = explode(':', $item, 2);

					if (count($parts) !== 2) {
						continue;
					}

					$lid  = (int)$parts[0];
					$mask = (int)$parts[1];

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

			$html .= '
			<div class="em-block">
				<div class="em-title" onclick="toggleEM(\'emAL\', this)">
					<span>Access List Emails</span>
					<span class="em-arrow">▶</span>
				</div>

				<div id="emAL" class="em-content">

					<input type="hidden"
						   name="accesslist_emailprefs_present"
						   value="1">

					<input type="hidden"
						   name="accesslist_emailprefs"
						   id="accesslist_emailprefs"
						   value="">

					<p style="margin:6px 0 10px;color:#666;font-size:13px;">
						Choose which access list emails you want to receive:
					</p>';

			foreach ($al_db_rows as $al) {

                $lid = (int)$al['listid'];
                $ownerid = (int)$al['ownerid'];
                $is_owner = ($userid == $ownerid);

                if (array_key_exists($lid, $al_saved)) {
                    // User has an explicitly saved preference,
                    // including mask = 0.
                    $mask = (int)$al_saved[$lid];

                    // Non-owner cannot have Subscriber blocked.
                    if (!$is_owner) {
                        $mask &= 15;
                    }

                } else{

                    // No preference saved for this particular access list. This can happen either because:
                    // 1. The user is new, or
                    // 2. This access list was added after the user saved preferences..
                    $mask = $is_owner ? 31 : 15;

                }

				$html .= '
					<div class="em-row"
						 style="margin-top:12px;padding:8px;border:1px solid #ddd;">

						<div style="font-weight:bold;margin-bottom:6px;">
							' . qa_html($al['name']) . '
						</div>';

				foreach ($email_types as $bit => $type_label) {

                    if (!$is_owner && $bit === 16) {
                        // Subscriber blocked email is only relevant to the owner of the access list
                        continue;
                    }

                    if ($bit === 1 || $bit === 8) {
                        // Welcome email and Blocked from Access List are mandatory and cannot be disabled
                        continue;
                    }

					$checked = ($mask & $bit) ? ' checked' : '';

					$html .= '
						<div style="margin:4px 0;">
							<label>
								<input type="checkbox"
									   class="al-check accesslist-email-pref"
									   data-listid="' . $lid . '"
									   data-bit="' . $bit . '"
									   ' . $checked . '>
								' . qa_html($type_label) . '
							</label>
						</div>';
				}

				$html .= '
					</div>';
			}

			$html .= '
				</div>
			</div>';
		}
    }

    /* ------------------------------
       TOAST (after save)
    ------------------------------ */
        if (qa_get('email_ok')){
			$msg = qa_lang_html("emailopt/pref_saved");
            $html .= '<script> window.onload=function(){ em_toast('.json_encode($msg).'); } </script>';
		}

    /* ------------------------------
       RETURN Q2A FORM
    ------------------------------ */
    return [
        'tags'   => 'method="post" action="'.qa_self_html().'"',
        'style'  => 'wide',
        'fields' => [
            ['type' => 'static', 'value' => $html],
        ],
        'buttons' => [
            [
                'label' => qa_lang_html('emailopt/save_email_preferences'),
                'tags'  => 'name="save_emailprefs"',
            ],
        ],
    ];
}


}
