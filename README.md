# Email Management Plugin for Question2Answer
Advanced Email Notification Control for Q2A - Developed using chatgpt

## Overview
The **Email Management Plugin** allows Q2A site administrators and users to fully control which email notifications are delivered.

It includes:
- Admin-controlled email events  
- Forced (non-unsubscribe-able) emails from Admins  
- User-level email event preferences 


## Features

### Admin Features
- Add / Edit / Active or Deactivate email event types  
- Set user-readable label, email subject, and forced flag, Minimum Userlevel to display that event. 
- Dynamic “Add Event” system  


### User Features
- New “Email Preferences” section on the Account page  
- Users can enable/disable notifications individually  
- User can select whether to receive the unmanaged email events
- Select All / Deselect All  
- Automatic defaults for new users  


3. Table `qa_email_events` will be auto-created with default rows.

## 🗂 Database Structure

**Table:** `qa_email_events`

| Column       		| Description                         
|--------------		|-------------------------------------
| eventid      		| Auto increment primary key          
| user_title   		| Label shown to users                
| subject_key  		| Email subject either direct subject or a language key 
| forced       		| 1 = cannot unsubscribe              
| active       		| 1 = mail event active. 0= mail event inactive, then mail sent based on user preference
| Minimum level     | Registered users & above, etc.              
| subject_type      | 1 = defined subject is language key. 0 = defined subject is direct subject
| created      		| Timestamp                       

**User preferences:** stored in `qa_usermeta.emailprefs` (CSV).

## Email Sending Logic

The plugin overrides `qa_send_notification()`:

1. Check email subject  
2. If active and forced → send
3. Else load user preferences  
4. If inactive or unmanaged -> check user selected other mails or not
5. If active and managed check whether user selected that event → send 
6. Otherwise → skip email  


## ✏ Custom Events

Add custom plugin notifications by defining:
```
["User readable label", "your_email_subject", 0,1,0,1]
```

## © License
Free to use and modify.