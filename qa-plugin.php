<?php
if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

define('QA_EMAIL_MGMT_DIR', str_replace('\\', '/', __DIR__) . '/');

qa_register_plugin_phrases('qa-emails-lang.php', 'emailopt');
qa_register_plugin_layer('qa-emails-opt-layer.php', 'Adding Email opt field to the user profile');
qa_register_plugin_overrides('qa-emails-overrides.php', 'To override the email triggering function');
qa_register_plugin_module('module', 'qa-email-mgmt-admin.php', 'qa_email_mgmt_admin', 'User Email Preferences');
qa_register_plugin_module('page', 'qa-email-unsubscribe.php', 'qa_email_unsubscribe_page', 'Email Preferences (Unsubscribe)');