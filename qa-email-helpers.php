<?php
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

/**
 * Get (or auto-generate) the site-level AES-128 encryption key.
 * Stored as a 32-char hex string in qa_options; returned as raw 16 bytes.
 */
function em_get_encryption_key()
{
    $hex = qa_opt('em_encryption_key');
    if (empty($hex) || strlen($hex) < 32) {
        $hex = bin2hex(random_bytes(16));
        qa_opt('em_encryption_key', $hex);
    }
    return hex2bin($hex);
}

/**
 * Encrypt uid + token into an opaque, URL-safe string.
 * Uses AES-128-CBC with a random IV prepended to the ciphertext.
 *
 * @param  int    $uid   Numeric user ID.
 * @param  string $token Per-user email token (hex).
 * @return string        URL-safe base64 ciphertext.
 */
function em_encrypt_uid($uid, $token)
{
    $key  = em_get_encryption_key();
    $data = $uid . ':' . $token;
    $iv   = random_bytes(16);
    $enc  = openssl_encrypt($data, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
    // base64url: +/ → -_, strip trailing =
    return rtrim(strtr(base64_encode($iv . $enc), '+/', '-_'), '=');
}

/**
 * Decrypt an opaque code back to uid + token.
 *
 * @param  string     $code URL-safe base64 from em_encrypt_uid().
 * @return array|null       ['uid' => int, 'token' => string] or null.
 */
function em_decrypt_uid($code)
{
    $key = em_get_encryption_key();
    $raw = base64_decode(strtr($code, '-_', '+/'));
    if ($raw === false || strlen($raw) < 17) {
        return null;
    }
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $data = openssl_decrypt($enc, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($data === false) {
        return null;
    }
    $parts = explode(':', $data, 2);
    if (count($parts) !== 2) {
        return null;
    }
    return array('uid' => (int)$parts[0], 'token' => $parts[1]);
}
