<?php
/**
 * The sign-off on every message a person receives from the Foundation.
 *
 * These messages are automated, but they are not from a machine. Somebody
 * decided to admit this member, and somebody will read the reply. Signing them
 * says so, and it gives the reader a person to answer rather than an alias.
 *
 * Defined here rather than pasted into each template, because a signature
 * copied into seven files is a signature that will disagree with itself the
 * first time a title or an address changes.
 *
 * Included by the scripts that build the message templates:
 *   require_once __DIR__ . '/openar-signature.php';
 *   $text .= openar_signature_text();
 *   $html .= openar_signature_html();
 *
 * Reviewer notifications deliberately do not use this. They go to whoever is
 * doing the review, and a colleague signing a work queue item is odd.
 */

const OPENAR_SIGNER_NAME = 'Rob Grafrath';
const OPENAR_SIGNER_TITLE = 'Chair';
const OPENAR_ORG_NAME = 'The Open Accounts Receivable Collective Foundation';
const OPENAR_SIGNER_EMAIL = 'membership@openarcollective.org';
const OPENAR_ORG_URL = 'openarcollective.org';

/**
 * The plain-text sign-off, including the blank line that separates it from the
 * body. Two newlines, because a heredoc body ends without one of its own.
 */
function openar_signature_text(string $closing = 'Welcome aboard'): string {
  return "\n\n{$closing},\n\n"
    . OPENAR_SIGNER_NAME . "\n"
    . OPENAR_SIGNER_TITLE . ', ' . OPENAR_ORG_NAME . "\n"
    . OPENAR_SIGNER_EMAIL . "\n"
    . OPENAR_ORG_URL . "\n";
}

/**
 * The HTML sign-off.
 *
 * Links are left unstyled on purpose. Every other link in these emails inherits
 * the client's own colour, and a signature is the last place to start inventing
 * one that might not have the contrast to be read.
 */
function openar_signature_html(string $closing = 'Welcome aboard'): string {
  $name = htmlspecialchars(OPENAR_SIGNER_NAME, ENT_QUOTES);
  $role = htmlspecialchars(OPENAR_SIGNER_TITLE . ', ' . OPENAR_ORG_NAME, ENT_QUOTES);
  $mail = htmlspecialchars(OPENAR_SIGNER_EMAIL, ENT_QUOTES);
  $site = htmlspecialchars(OPENAR_ORG_URL, ENT_QUOTES);
  $closing = htmlspecialchars($closing, ENT_QUOTES);

  return <<<HTML

<p style="margin:26px 0 0;">{$closing},</p>

<p style="margin:14px 0 0;padding-top:14px;border-top:1px solid #e3ded3;line-height:1.5;">
<strong>{$name}</strong><br />
<span style="color:#5c564c;">{$role}</span><br />
<a href="mailto:{$mail}">{$mail}</a><br />
<a href="https://{$site}">{$site}</a>
</p>
HTML;
}
