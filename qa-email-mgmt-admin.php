<?php
/*
    Email Management Plugin — Admin Page + DB Table Creation (EVENT-WISE)
    Phase 1: Admin controls types of emails that user can enable/disable.
    Storage: uses DB table ^email_events
*/

if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

class qa_email_mgmt_admin
{
    // Table creation
	public function init_queries($tableslc)
	{
		$tbl = qa_db_add_table_prefix('email_events');

		if (!in_array($tbl, $tableslc)) {

			// First query: create table
			$sql =
			"CREATE TABLE `$tbl` (
				`eventid` INT NOT NULL AUTO_INCREMENT,
				`user_title` VARCHAR(255) NOT NULL,
				`subject_key` VARCHAR(255) NOT NULL,
				`forced` TINYINT(1) DEFAULT 0,
				`active` TINYINT(1) DEFAULT 1,
				`min_level` SMALLINT NOT NULL DEFAULT 0,
				`subject_type` TINYINT(1) NOT NULL DEFAULT 1,
				`created` DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`eventid`),
				UNIQUE KEY (`subject_key`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

			// Return both create + insert queries (Q2A will run them sequentially)
			return array_merge(
				[$sql],
				$this->default_insert_queries()
			);
		}

		return null;
	}

	private function default_insert_queries()
	{
		// [ user_title (plain text), subject_key, forced ]
		$defaults = [
			[ "Someone commented on your answer",'emails/a_commented_subject',0,1,0,1 ],
			[ "A related question was posted to your answer",'emails/a_followed_subject',0,1,0,1 ],
			[ "Your answer was selected as best",'emails/a_selected_subject',0,1,0,1 ],
			[ "Someone replied to your comment",'emails/c_commented_subject',0,1,0,1 ],
			[ "Email confirmation required",'emails/confirm_subject',1,1,0,1 ],
			[ "Admin received user feedback",'emails/feedback_subject',1,1,0,1 ],
			[ "A post has been flagged",'emails/flagged_subject',0,1,0,1 ],
			[ "A post requires moderation",'emails/moderate_subject',0,1,0,1 ],
			[ "Your new password email",'emails/new_password_subject',0,1,0,1 ],
			[ "You received a private message",'emails/private_message_subject',0,1,0,1 ],
			[ "Your question was answered",'emails/q_answered_subject',0,1,0,1 ],
			[ "Your question has a new comment",'emails/q_commented_subject',0,1,0,1 ],
			[ "A new question was posted",'emails/q_posted_subject',0,1,0,1 ],
			[ "Edited post requires re-approval",'emails/remoderate_subject',0,1,0,1 ],
			[ "Password reset requested",'emails/reset_subject',1,1,0,1 ],
			[ "A new user registered",'emails/u_registered_subject',0,1,0,1 ],
			[ "Your user account was approved",'emails/u_approved_subject',1,1,0,1 ],
			[ "Someone posted on your wall",'emails/wall_post_subject',0,1,0,1 ],
			[ "Welcome message on registration",'emails/welcome_subject',1,1,0,1 ],
			["Your post has been approved",'pdeleted/p_approve_reason_subject',0,1,0,1],
			["Your post has been permanently rejected",'pdeleted/p_hide_reason_subject',0,1,0,1],
			["Your post has been sent for moderation",'pdeleted/p_queue_reason_subject',0,1,0,1]
		];

		$queries = [];
		
		// Get correct table name
		$table = qa_db_add_table_prefix('email_events');
		
		foreach ($defaults as $row) {
			list($title, $subject_key, $forced, $active,$min_level,$subject_type) = $row;

			 // Escape single quotes for SQL safety
			$title_esc = str_replace("'", "''", $title);

			$queries[] =
				"INSERT INTO `{$table}` (user_title, subject_key, forced,active,min_level,subject_type )
				 VALUES ('{$title_esc}', '{$subject_key}', {$forced},{$active},{$min_level},{$subject_type})";
		}

		return $queries;
	}

    /* -------------------------------------------------
       2. LOAD ALL EVENTS FROM DB
    ------------------------------------------------- */
    private function load_events()
    {
        $query = qa_db_query_sub('SELECT * FROM ^email_events ORDER BY eventid ASC');
        return qa_db_read_all_assoc($query);
    }

    /* -------------------------------------------------
       3. SAVE (ADD/EDIT/DELETE)
    ------------------------------------------------- */
	private function save_events()
	{
		$count  = (int)qa_post_text('event_count');

		// Load existing events once
		$events = $this->load_events();

		// Index by eventid for O(1) lookup
		$map = [];
		foreach ($events as $ev) {
			$map[(int)$ev['eventid']] = $ev;
		}

		for ($i = 1; $i <= $count; $i++) {

			$eventid   = (int)qa_post_text('eventid_'.$i);
			$title     = trim(qa_post_text('user_title_'.$i));
			$subject   = trim(qa_post_text('subject_key_'.$i));
			$subject_type   = (int)(qa_post_text('subject_type_'.$i));
			$forced    = (int)qa_post_text('forced_'.$i);
			$active    = (int)qa_post_text('active_'.$i);
			$min_level = (int)qa_post_text('min_level_'.$i);

			if ($title === '' || $subject === '') {
				continue;
			}

			/* ---------------------------------
			   EXISTING EVENT → UPDATE IF CHANGED
			--------------------------------- */
			if (isset($map[$eventid])) {

				$old = $map[$eventid];

				$changed =
					$old['user_title'] !== $title ||
					$old['subject_key'] !== $subject ||
					(int)$old['subject_type'] !== $subject_type ||
					(int)$old['forced'] !== $forced ||
					(int)$old['active'] !== $active ||
					(int)$old['min_level'] !== $min_level;

				if ($changed) {
					qa_db_query_sub(
						'UPDATE ^email_events
						 SET user_title=$, subject_key=$, forced=#, active=#, min_level=#, subject_type=#
						 WHERE eventid=#',
						$title,
						$subject,
						$forced,
						$active,
						$min_level,
						$subject_type,
						$eventid
					);
				}
			}

			/* -------------------------
			   NEW EVENT → INSERT
			------------------------- */
			else {
				qa_db_query_sub(
					'INSERT INTO ^email_events
					 (user_title, subject_key, forced, active, min_level,subject_type)
					 VALUES ($, $, #, #, #,#)',
					$title,
					$subject,
					$forced,
					$active,
					$min_level,
					$subject_type,
				);
			}
		}

		return true;
	}


    // ADMIN FORM
    public function admin_form()
    {
        $saved = false;

		if (qa_clicked('reset_default_events')) {
			$this->reset_default_events();
			qa_redirect(qa_request(), ['ok' => 'Default email events restored successfully']);
		}
		
        if (qa_clicked('save_email_events')) {
            $saved = $this->save_events();
        }

        $events = $this->load_events();

        /* ---------------------------
           Build Editable Rows
        --------------------------- */
		require_once QA_INCLUDE_DIR.'qa-app-users.php';
		$level_options = array(
			QA_USER_LEVEL_BASIC => qa_lang_html('users/registered_user'),
			QA_USER_LEVEL_APPROVED => qa_lang_html('users/approved_user'),
			QA_USER_LEVEL_EXPERT => qa_lang_html('users/level_expert'),
			QA_USER_LEVEL_EDITOR => qa_lang_html('users/level_editor'),
			QA_USER_LEVEL_MODERATOR => qa_lang_html('users/level_moderator'),
			QA_USER_LEVEL_ADMIN => qa_lang_html('users/level_admin'),
			QA_USER_LEVEL_SUPER => qa_lang_html('users/level_super'),
		);

		$level_select_html = '';
		foreach ($level_options as $level => $label) {
			$level_select_html .=
				'<option value="'.$level.'">'.$label.' & above</option>';
		}

        $html = '<style>
					.ev-box { background:#fafafa;border:1px solid #ccc;padding:10px;margin-bottom:10px;border-radius:6px; }
					.ev-box h4 { margin:0 0 8px 0; }
					.ev-row { margin-bottom:6px; }
					.ev-label { display:block;font-weight:bold;margin-bottom:3px; }
					.ev-input { width:100%;padding:4px; }
					.ev-add { background:#007bff;color:#fff;padding:6px 10px;border:none;border-radius:4px;cursor:pointer; }
				</style>

				<script>
				document.addEventListener("DOMContentLoaded", function(){

					const addBtn = document.getElementById("ev-add-btn");
					const cont   = document.getElementById("ev-container");
					const cnt    = document.getElementById("event_count");

					if (!addBtn || !cont || !cnt) return;

					addBtn.addEventListener("click", function(e){
						e.preventDefault();

						let i = parseInt(cnt.value) + 1;

						let d = document.createElement("div");
						d.className = "ev-box";

						d.innerHTML = `
							<h4>Event ${i}</h4>
							
							<input type="hidden" name="eventid_${i}" value="${i}">

							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/email_title').'</label>
								<input class="ev-input" type="text" name="user_title_${i}">
							</div>
							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/subject_type').'</label>
								<select name="subject_type_${i}" class="ev-input">
									<option value="1">'.qa_lang_html('emailopt/subject_type_language_key').'</option>
									<option value="0">'.qa_lang_html('emailopt/subject_type_direct_key').'</option>
								</select>
							</div>
							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/subject_key').'</label>
								<input class="ev-input" type="text" name="subject_key_${i}">
							</div>

							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/forcefully_sent').'</label>
								<select name="forced_${i}" class="ev-input">
									<option value="0">No</option>
									<option value="1">Yes</option>
								</select>
							</div>

							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/min_level').'</label>
								<select name="min_level_${i}" class="ev-input">'.$level_select_html.'</select>
							</div>

							<div class="ev-row">
								<label class="ev-label">'.qa_lang_html('emailopt/event_status').'</label>
								<select name="active_${i}" class="ev-input">
									<option value="1">'.qa_lang_html('emailopt/active').'</option>
									<option value="0">'.qa_lang_html('emailopt/inactive').'</option>
								</select>
							</div>
						`;

						cont.appendChild(d);
						cnt.value = i;
					});

				});
				</script>';



        /* Existing rows */
        $i = 1;
        $html .= '<div id="ev-container">';
		foreach ($events as $ev) {

			$min = (int)$ev['min_level'];
			$act = (int)$ev['active'];
			$subject_type = (int)$ev['subject_type'];

			$html .= '
			<div class="ev-box">
				<h4>Event '.$i.'</h4>
				
				<div class="ev-row">
					<input type="hidden" name="eventid_'.$i.'" value="'.$ev['eventid'].'">
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/email_title').'</label>
					<input class="ev-input" type="text"
						   name="user_title_'.$i.'"
						   value="'.qa_html($ev['user_title']).'">
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/subject_type').'</label>
					<select name="subject_type_'.$i.'" class="ev-input">
						<option value="1"'.($subject_type==1?' selected':'').'>'.qa_lang_html('emailopt/subject_type_language_key').'</option>
						<option value="0"'.($subject_type==0?' selected':'').'>'.qa_lang_html('emailopt/subject_type_direct_key').'</option>
					</select>
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/subject_key').'</label>
					<input class="ev-input" type="text"
						   name="subject_key_'.$i.'"
						   value="'.qa_html($ev['subject_key']).'"
						   readonly>
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/forcefully_sent').'</label>
					<select name="forced_'.$i.'" class="ev-input">
						<option value="0"'.($ev['forced']==0?' selected':'').'>No</option>
						<option value="1"'.($ev['forced']==1?' selected':'').'>Yes</option>
					</select>
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/min_level').'</label>
					<select name="min_level_'.$i.'" class="ev-input">
						<option value="'.QA_USER_LEVEL_BASIC.'"'.($min==QA_USER_LEVEL_BASIC?' selected':'').'>All registered users</option>
						<option value="'.QA_USER_LEVEL_EXPERT.'"'.($min==QA_USER_LEVEL_EXPERT?' selected':'').'>Experts and above</option>
						<option value="'.QA_USER_LEVEL_EDITOR.'"'.($min==QA_USER_LEVEL_EDITOR?' selected':'').'>Editors and above</option>
						<option value="'.QA_USER_LEVEL_MODERATOR.'"'.($min==QA_USER_LEVEL_MODERATOR?' selected':'').'>Moderators and above</option>
						<option value="'.QA_USER_LEVEL_ADMIN.'"'.($min==QA_USER_LEVEL_ADMIN?' selected':'').'>Administrators only</option>
					</select>
				</div>

				<div class="ev-row">
					<label class="ev-label">'.qa_lang_html('emailopt/event_status').'</label>
					<select name="active_'.$i.'" class="ev-input">
						<option value="1"'.($act==1?' selected':'').'>'.qa_lang_html('emailopt/active').'</option>
						<option value="0"'.($act==0?' selected':'').'>'.qa_lang_html('emailopt/inactive').'</option>
					</select>
				</div>
			</div>';
			
			$i++;
		}

		$html .= '</div>';


        /* Add new */
        $html .= '<button id="ev-add-btn" class="ev-add">+ ' . qa_lang_html('emailopt/add_event_button') . '</button>';


        /* Return form */
        return [
				'ok' => $saved ? qa_lang_html('emailopt/events_saved') : null,

				'fields' => [
					[ 'type' => 'static', 'value' => $html ],
					[
						'type'  => 'hidden',
						'tags'  => 'id="event_count" name="event_count"',
						'value' => $i - 1
					]
				],

				'buttons' => [
					[
						'label' => qa_lang_html('emailopt/save_events'),
						'tags'  => 'name="save_email_events" class="ev-add"'
					],
				],
			];

    }
}

