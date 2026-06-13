# Email Management Plugin for Question2Answer
Advanced Email Notification Control for Q2A

## Overview
The **Email Management Plugin** allows Q2A site administrators and users to fully control which email notifications are delivered. All outgoing emails are wrapped in a responsive HTML template with support for dark mode.

It includes:
- Admin-controlled email events
- Forced (non-unsubscribable) emails
- User-level per-event notification preferences
- Branded HTML email template (logo, CTA button, blockquotes, unsubscribe link)


## Features

### Admin Features
- Add / Edit / Activate or Deactivate email event types
- Set user-readable label, email subject, forced flag, and minimum user level per event
- Optional custom body template per event (overrides the Q2A default body)
- Dynamic "Add Event" system

### User Features
- "Email Preferences" section on the Account page
- Enable/disable individual notification types
- Opt in/out of unmanaged (uncategorised) emails
- Select All / Deselect All
- Automatic opt-in defaults for new users


## Database Structure

**Table:** `qa_email_events` — auto-created with default rows on first load.

| Column        | Description                                                        |
|---------------|--------------------------------------------------------------------|
| eventid       | Auto-increment primary key                                         |
| user_title    | Label shown to users on the preferences page                       |
| subject_key   | Email subject string, or a Q2A language key                        |
| subject_type  | `1` = subject_key is a language key; `0` = literal subject string  |
| forced        | `1` = always sent, user cannot opt out                             |
| active        | `1` = event is managed; `0` = treated as unmanaged                 |
| min_level     | Minimum user level required to see this preference                 |
| custom_body   | Optional body template that replaces the Q2A default               |
| created       | Timestamp                                                          |

**User preferences:** stored in `qa_usermeta` under the key `emailprefs` as a comma-separated list of enabled event IDs. Event ID `0` represents unmanaged (uncategorised) emails.


## Email Sending Logic

`qa_send_notification()` is fully overridden (no call to the base function for logged-in users):

1. Guest / no userid → delegate to `qa_send_notification_base` unchanged
2. Load event config (cached per-request via `em_get_events_config()`)
3. Apply custom body template if one is set for this subject
4. If subject is **forced & active** → send immediately
5. Load user's `emailprefs` from `qa_usermeta`
   - `NULL` (new user) → send (default opt-in)
   - `""` (explicitly disabled all) → skip
6. **Unmanaged event** → send only if the user enabled event ID `0`
7. **Managed event** → send only if the user enabled that event's ID
8. Otherwise → skip

### `em_send_with_footer()`
Handles the actual dispatch. Before sending it:
- Checks the global `$qa_notifications_suspended` flag
- Resolves missing email / handle from the database (mirrors core behaviour)
- Applies all `^placeholder` substitutions
- Wraps `^open…^close` blocks as styled HTML blockquotes
- Converts `^url` into a CTA button and `^a_url` into a secondary link
- Appends a per-user unsubscribe / preferences link
- Calls `qa_send_email()` directly to avoid the plain-text `"Dear [handle],"` prefix that `qa_send_notification_base` would prepend before an HTML body


## HTML Email Template

All emails are sent as HTML using `em_build_html_email()`.

**Structure:**
```
+----------------------------------+
|  HEADER  (logo + subject line)   |
+----------------------------------+
|  BODY    (content + CTA button)  |
+----------------------------------+
|  FOOTER  (site link + unsub)     |
+----------------------------------+
```

**Responsive & dark-mode aware:**
- `@media (max-width:620px)` collapses padding so the card fills narrow viewports edge-to-edge
- `@media (prefers-color-scheme:dark)` switches body/footer backgrounds and text for Apple Mail, Gmail iOS, and other supporting clients
- Inline styles remain as a fallback for Outlook and legacy clients

**Template constants** (defined once at the top of `qa-emails-overrides.php`, or override via your own plugin):

| Constant               | Default                     | Description               |
|------------------------|-----------------------------|---------------------------|
| `QA_EMAIL_LOGO_URL`    | `qa_opt('logo_url')`        | Logo image URL            |
| `QA_EMAIL_LOGO_HEIGHT` | `48`                        | Logo height in px         |
| `QA_EMAIL_HEADER_BG`   | `#1a1a2e`                   | Header background colour  |
| `QA_EMAIL_HEADER_TEXT` | `#ffffff`                   | Header text colour        |
| `QA_EMAIL_ACCENT`      | `#4a90d9`                   | Accent / CTA colour       |
| `QA_EMAIL_FOOTER_TEXT` | `qa_opt('em_footer_text')`  | Footer note text          |


## Custom Events

Register a custom event by inserting a row into `qa_email_events`, or use the admin panel. The `custom_body` column accepts the same `^placeholder` syntax as Q2A core email templates.


## License
Free to use and modify.