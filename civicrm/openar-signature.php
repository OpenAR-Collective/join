<?php
/**
 * The sign-off on every message a person receives from the Foundation.
 *
 * These messages are automated, but they are not from a machine. Somebody
 * decided to admit this member, and somebody will read the reply. Signing them
 * says so, and it gives the reader a person to answer rather than an alias.
 *
 * This is Rob's own email signature, reproduced, with one deliberate change:
 * the address is membership@ rather than rob@. Automated mail is sent from
 * membership@ so that replies reach the mailbox the Foundation actually
 * monitors, and a signature pointing somewhere else would undo that on every
 * message that carries it.
 *
 * Defined here rather than pasted into each template, because a signature
 * copied into seven files is a signature that will disagree with itself the
 * first time a title or an address changes.
 *
 * Included by the scripts that build the message templates:
 *   require_once __DIR__ . '/openar-signature.php';
 *   $text .= openar_signature_text('Welcome aboard');
 *   $html .= openar_signature_html('Welcome aboard');
 *
 * Reviewer notifications deliberately do not use this. They go to whoever is
 * doing the review, and a colleague signing a work queue item is odd.
 *
 * The images are served from openarcollective.org/assets/email/ and live in the
 * website repository under public/assets/email/. They are on main, so they load
 * for real recipients today rather than only after full-site merges. If that
 * path ever moves, every signature already sitting in somebody's inbox breaks,
 * so treat those four files as permanent.
 */

const OPENAR_SIGNER_NAME = 'Rob Grafrath';
const OPENAR_SIGNER_TITLE = 'Founder and Chair';
const OPENAR_ORG_NAME = 'The Open Accounts Receivable Collective Foundation';
const OPENAR_SIGNER_EMAIL = 'membership@openarcollective.org';
const OPENAR_ORG_URL = 'openarcollective.org';

/**
 * Set either of these to '' to drop that line from every automated message.
 *
 * Worth a thought rather than a default: these go to everyone who applies,
 * including people whose application is declined, and an inbox is forever.
 */
const OPENAR_SIGNER_PHONE = '(903) 436-3547';
const OPENAR_SIGNER_MEETING_URL = 'https://meetings.hubspot.com/rob-grafrath';

const OPENAR_SIGNATURE_ASSETS = 'https://openarcollective.org/assets/email';
const OPENAR_LINKEDIN_URL = 'https://www.linkedin.com/in/grafrath/';
const OPENAR_DISCORD_INVITE = 'https://discord.gg/5Z7TEQAek3';

/**
 * The plain-text sign-off, including the blank line that separates it from the
 * body. Two newlines, because a heredoc body ends without one of its own.
 *
 * No wordmark to lean on here, so the organization is named in full on the
 * title line, and the name is in ordinary case rather than the capitals the
 * HTML uses. Capitals are a typographic choice on screen and shouting in text.
 */
function openar_signature_text(string $closing = 'Welcome aboard'): string {
  $lines = [
    OPENAR_SIGNER_NAME,
    OPENAR_SIGNER_TITLE . ', ' . OPENAR_ORG_NAME,
    OPENAR_SIGNER_EMAIL,
  ];
  if (OPENAR_SIGNER_PHONE !== '') {
    $lines[] = OPENAR_SIGNER_PHONE;
  }
  $lines[] = 'https://' . OPENAR_ORG_URL;
  if (OPENAR_SIGNER_MEETING_URL !== '') {
    $lines[] = 'Schedule a meeting: ' . OPENAR_SIGNER_MEETING_URL;
  }

  return "\n\n{$closing},\n\n" . implode("\n", $lines) . "\n";
}

/**
 * The HTML sign-off, as a table.
 *
 * A table rather than flexbox because this has to survive Outlook, which is
 * still rendering mail with Word's engine. Every style is inline for the same
 * reason: a <style> block is stripped by several clients including Gmail.
 *
 * It degrades honestly when images are blocked, which is the common case on
 * first contact from an unknown sender: the wordmark beside the icon is real
 * text, so the Foundation is still named and the contact details still read.
 */
function openar_signature_html(string $closing = 'Welcome aboard'): string {
  $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

  $assets = OPENAR_SIGNATURE_ASSETS;
  $mail = $e(OPENAR_SIGNER_EMAIL);
  $site = $e(OPENAR_ORG_URL);

  $phone = OPENAR_SIGNER_PHONE === '' ? '' :
    '<div style="margin-top:3px;font-size:13px;color:#1A1714;">' . $e(OPENAR_SIGNER_PHONE) . '</div>';

  $meeting = OPENAR_SIGNER_MEETING_URL === '' ? '' :
    '<div style="margin-top:3px;">'
    . '<a href="' . $e(OPENAR_SIGNER_MEETING_URL) . '" style="text-decoration:none;color:#B87818;font-size:13px;">'
    . 'Schedule a Meeting</a></div>';

  $icon = function (string $file, string $alt, string $href) use ($assets, $e): string {
    return '<a href="' . $e($href) . '" style="text-decoration:none;border:none;display:inline-block;'
      . 'padding-right:4px;vertical-align:middle;">'
      . '<img src="' . $assets . '/' . $file . '" alt="' . $e($alt) . '" width="21" border="0" '
      . 'style="display:inline-block;vertical-align:middle;border:none;"></a>';
  };

  $social = $icon('linkedin.png', 'LinkedIn', OPENAR_LINKEDIN_URL)
    . $icon('website.png', 'Website', 'https://' . OPENAR_ORG_URL)
    . $icon('discord.jpg', 'Discord', OPENAR_DISCORD_INVITE);

  $closing = $e($closing);
  $name = $e(strtoupper(OPENAR_SIGNER_NAME));
  $title = $e(OPENAR_SIGNER_TITLE);

  return <<<HTML

<p style="margin:26px 0 14px;">{$closing},</p>

<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;line-height:1.2;color:#1A1714;">
  <tr>
    <td style="text-align:center;padding-right:10px;vertical-align:top;padding-top:4px;">
      <img src="{$assets}/openar-icon.png" alt="OpenAR Collective" width="70" height="70" border="0"
           style="display:block;margin:0 auto 4px auto;">
      <div style="font-size:14px;font-weight:bold;line-height:1.1;white-space:nowrap;">
        <span style="color:#2E2B28;">Open</span><span style="color:#B87818;">AR</span>
      </div>
      <div style="font-size:14px;font-weight:bold;color:#2E2B28;white-space:nowrap;">Collective</div>
    </td>

    <td style="border-left:2px solid #B87818;padding:0;vertical-align:middle;"></td>

    <td style="padding-left:10px;vertical-align:middle;">
      <div style="font-size:15px;font-weight:bold;color:#2E2B28;">{$name}</div>
      <div style="font-size:13px;color:#B87818;font-weight:bold;">{$title}</div>

      <div style="margin-top:3px;">
        <a href="mailto:{$mail}" style="text-decoration:none;color:#1A1714;font-size:13px;">{$mail}</a>
      </div>
      {$phone}
      <div style="margin-top:3px;">{$social}</div>
      {$meeting}
    </td>
  </tr>
</table>
HTML;
}
