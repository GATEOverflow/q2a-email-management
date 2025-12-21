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

    /* ------------------------------
       SAVE HANDLER
    ------------------------------ */
    if (qa_clicked('save_emailprefs')) {

        $vals = qa_post_array('emailprefs');
        $csv  = is_array($vals) ? implode(',', $vals) : '';

        qa_db_usermeta_set($userid, 'emailprefs', $csv);
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
