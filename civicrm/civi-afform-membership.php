<?php
/**
 * The membership application form, in full.
 *
 * This holds the WHOLE layout, on purpose. The previous version of this script
 * carried only the fields, and Afform::update replaces the entire layout, so
 * running it silently destroyed the mission statement, the section headings and
 * the scrollable terms that had been added to the live form by other work. The
 * form ran for a while as a bare list of inputs with no terms to agree to.
 *
 * So: if you change the form in the CiviCRM Form Builder, mirror the change
 * here. Anything in the live form and missing from this file is lost the next
 * time this runs.
 *
 * The LinkedIn placeholder shows a realistic slug rather than a tidy name.
 * Public profile URLs are usually a name with random characters appended, so
 * an example reading linkedin.com/in/yourname invites people to type a name
 * that is not their URL. Showing the messy shape tells them to go and copy it.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file civi-afform-membership.php
 */

civicrm_initialize();

use Civi\Api4\Afform;

const FORM = 'afformMembershipApplication';

$layout = <<<'HTML'
<af-form ctrl="afform">
  <af-entity data="{contact_type: 'Individual', source: 'Membership application'}" type="Individual" name="Individual1" label="Applicant" actions="{create: true, update: false}" security="FBAC" />
  <fieldset af-fieldset="Individual1" class="af-container oar-form">
    <af-title>Apply for membership</af-title>

    <h3 class="oar-h">Our mission</h3>
    <blockquote class="oar-mission"><p>The Open Accounts Receivable Collective Foundation works to make accounts receivable and debt collection more transparent, more compliant, and more accountable to the consumers it touches. It builds software under an open-source license so anyone can run, inspect, modify, and redistribute it at no cost. The Foundation also publishes open educational and compliance resources, develops shared standards, delivers training, and maintains a neutral, community-governed commons where practitioners learn from one another.</p></blockquote>
    <af-field name="Membership.mission_affirmation" defn="{required: true, input_type: 'CheckBox', label: 'I have read the mission statement above, and I support the Foundation’s charitable mission.'}" />

    <h3 class="oar-h">Your information</h3>
    <div class="af-container af-layout-cols2">
      <af-field name="first_name" defn="{required: true, label: 'First name'}" />
      <af-field name="last_name" defn="{required: true, label: 'Last name'}" />
    </div>
    <div class="af-container af-layout-cols2">
      <div af-join="Email" data="{is_primary: true}">
        <af-field name="email" defn="{required: true, label: 'Email address'}" />
      </div>
      <af-field name="Membership.employer_affiliation" defn="{required: true, label: 'Employer or affiliation'}" />
    </div>
    <div class="af-container af-layout-cols2">
      <af-field name="job_title" defn="{required: true, label: 'Professional role or title'}" />
      <af-field name="Membership.linkedin_url" defn="{required: false, label: 'LinkedIn profile', help_post: 'Optional. It helps us confirm your professional engagement more quickly.', input_attrs: {placeholder: 'linkedin.com/in/jane-doe-8a4b21'}}" />
    </div>

    <h3 class="oar-h">The terms</h3>
    <p class="oar-terms-label">Scroll to read the full terms before agreeing.</p><div class="oar-terms" tabindex="0" role="region" aria-label="Scroll to read the full terms before agreeing."><h2 id="calling-yourself-a-member">Calling Yourself a Member</h2>
<p>Membership entitles you to say publicly that you are a member of the OpenAR Collective. The Foundation grants you a limited, non-exclusive, non-transferable, and revocable license to do so on the terms below.</p>
<p>You may say, in your professional profiles, biography, presentations, and communications:</p>
<ul>
<li>&ldquo;[Your name] is a member of the OpenAR Collective.&rdquo;</li>
<li>&ldquo;Member, The OpenAR Collective.&rdquo;</li>
<li>&ldquo;OpenAR Collective Member.&rdquo;</li>
</ul>
<p>You may cite your member number.</p>
<p>You will not:</p>
<ul>
<li>State or imply that your employer, or any other organization, is a member, partner, affiliate, sponsor, or contributor of or to the Foundation on the basis of your membership;</li>
<li>Describe yourself, your employer, or any product or service as certified, approved, accredited, endorsed, or recommended by the Foundation;</li>
<li>Place the designation next to a claim about a product or service in a way that suggests the Foundation supports that claim;</li>
<li>Represent that you speak for the Foundation or hold any role in its governance; or</li>
<li>Use the Foundation&rsquo;s name, logo, or marks in any other manner, including in a company name, product name, domain name, or social media handle, without separate written permission under the Foundation&rsquo;s Trademark Policy.</li>
</ul>
<p>The Foundation does not publish a list of members. If someone asks the Foundation whether you are a member, the Foundation will confirm or deny it and will say nothing else about you.</p>
<p>At conferences and other events, the Foundation may hand out ribbons or tags that members can wear with their conference credentials. These are given only to people whose membership the Foundation has verified. The Foundation may also make badge images available later that members can put on a website, a profile, or a business card, possibly showing your member number. No badge images exist yet. Until the Foundation issues one, do not make your own badge or other graphic using the Foundation&rsquo;s logo or marks.</p>
<p>The license lasts as long as your membership does. If you withdraw, or if your membership is suspended or revoked, you will stop using the designation.</p>
<h2 id="community-participation-terms">Community Participation Terms</h2>
<p>These Terms apply to your membership. In these Terms, &ldquo;you&rdquo; means you as an individual member, and &ldquo;the Foundation&rdquo; means The Open Accounts Receivable Collective Foundation, operating as The OpenAR Collective. References to the policy are to the Foundation&rsquo;s Community Programs and Standards Policy, which is published on the Foundation&rsquo;s website.</p>
<p><strong>1. What participation is not.</strong> Participation gives you no vote, no governance authority, no right to direct or approve the Foundation&rsquo;s activities, positions, software, or standards, no right to notice of or attendance at meetings of the Board of Directors or its committees, and no ownership, financial, or property interest in the Foundation or its assets. Participation is not membership within the meaning of the Delaware General Corporation Law.</p>
<p><strong>2. No payment, ever.</strong> Participation requires no dues, fees, sponsorship, donation, or financial contribution of any kind. No contribution to the Foundation will confer participation, standing, priority, or any preference of any kind.</p>
<p><strong>3. No endorsement in either direction.</strong> The Foundation does not review, evaluate, certify, approve, or endorse you, your employer, or any organization&rsquo;s products, services, or business practices, and makes no representation about them. You will not state or imply otherwise.</p>
<p><strong>4. Antitrust.</strong> Foundation community spaces and events may not be used to discuss or reach any agreement or understanding among competitors concerning prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal with any business. If a discussion approaches these subjects, you will end your participation in it and notify a moderator or the Foundation. This obligation applies regardless of whether the discussion occurs in a Foundation space, and you will not use your participation as an occasion for such a discussion elsewhere.</p>
<p><strong>5. Consumer privacy.</strong> You will not share any consumer&rsquo;s personal or account information in any Foundation community space or with the Foundation, whether or not you believe the information has been anonymized. If you contribute data, code, documentation, or examples, you are responsible for ensuring they contain no consumer information.</p>
<p><strong>6. Confidential and competitively sensitive information.</strong> You will not disclose to the Foundation or its community any information you are not free to disclose, including your employer&rsquo;s confidential information, information subject to a client contract or nondisclosure agreement, and information subject to legal privilege.</p>
<p><strong>7. Reporting concerns, and no retaliation.</strong> If you believe in good faith that the Foundation, or any person acting on its behalf, has violated the law or a Foundation policy, you may report it under the Foundation&rsquo;s Whistleblower Policy, including anonymously. The Foundation prohibits retaliation against any person who makes such a report in good faith or who participates in an investigation, and will not suspend, revoke, or otherwise act against your participation because you made such a report. Reports made in bad faith, or with knowledge that the reported information is false, are not protected.</p>
<p><strong>8. Nothing here is legal or compliance advice.</strong> The Foundation publishes educational and compliance resources and develops open-source software. Nothing the Foundation publishes, and nothing said in a Foundation community space, is legal advice or a guarantee of compliance with any law, regulation, or contractual obligation. You are responsible for your own compliance and for obtaining your own professional advice.</p>
<p><strong>9. Intellectual property.</strong> You will respect the Foundation&rsquo;s trademarks and the license terms of the Foundation&rsquo;s software and published materials. Any use of the Foundation&rsquo;s name, logo, or marks beyond what is expressly permitted requires separate written permission under the Foundation&rsquo;s Trademark Policy. Contributions of code or content are governed by the Foundation&rsquo;s Open Source Policy and its contributor terms.</p>
<p><strong>10. Accuracy.</strong> The information you provide will be truthful and current, and you will notify the Foundation promptly when it materially changes. Material misrepresentation is grounds for denial or revocation.</p>
<p><strong>11. Conduct.</strong> You will comply with the community standards in Article V of this policy and with the published rules of each Foundation platform, and with the reasonable directions of moderators. Good-faith criticism of the Foundation, its board, its software, its published positions, or its governance is never a violation of those standards and will never affect your participation.</p>
<p><strong>12. Your information.</strong> The Foundation will use the information you provide to administer the program, operate its community platforms, and communicate with you about the Foundation&rsquo;s work, which may include invitations to support the Foundation financially. The Foundation will not sell your information and will not disclose it to third parties except as required by law or as necessary to operate the program. You may opt out of Foundation communications at any time without affecting your participation.</p>
<p><strong>13. Suspension, revocation, and withdrawal.</strong> You may withdraw at any time. The Foundation may suspend or revoke participation on the grounds and through the process stated in Article VII of this policy, which includes written notice and one appeal to the Board of Directors. Revocation creates no claim against the Foundation.</p>
<p><strong>14. Changes.</strong> The Foundation may amend this policy at any time. You are bound by the version of these Terms in force on the date you were admitted, and a later version applies to you only if you accept it.</p>
<p><strong>15. No contract for services.</strong> Participation is not a contract for goods or services and creates no financial obligation on either party.</p></div>
    <div class="oar-agreements">
      <af-field name="Membership.terms_agreement" defn="{required: true, input_type: 'CheckBox', label: 'I have read and agree to the Community Participation Terms above.'}" />
      <af-field name="Membership.info_truthful" defn="{required: true, input_type: 'CheckBox', label: 'The information I have provided is truthful and current.'}" />
    </div>
  </fieldset>
  <button class="af-button btn btn-primary" ng-click="afform.submit()">Submit application</button>
</af-form>

HTML;

$existing = Afform::get(FALSE)
  ->addSelect('name')
  ->addWhere('name', '=', FORM)
  ->execute()->first();

if (!$existing) {
  echo "ERROR: " . FORM . " does not exist. This script updates it; it does not create it.
";
  return;
}

// A guard against the mistake this script was rewritten to prevent. The terms
// are the part with legal weight, so their absence is the thing worth refusing
// to ship rather than merely warning about.
foreach (['oar-terms' => 'the scrollable terms', 'oar-mission' => 'the mission statement',
          'oar-agreements' => 'the agreement checkboxes'] as $marker => $what) {
  if (!str_contains($layout, $marker)) {
    echo "ERROR: the layout in this file is missing {$what} ({$marker}). Refusing to write it.
";
    return;
  }
}

Afform::update(FALSE)
  ->addWhere('name', '=', FORM)
  ->addValue('layout', $layout)
  ->addValue('title', 'Membership application')
  ->addValue('server_route', 'civicrm/membership-application')
  ->addValue('is_public', TRUE)
  ->addValue('permission', ['*always allow*'])
  // manual_processing keeps an unconfirmed application out of the contact
  // records; our own seven-day confirmation email replaces Afform's ten-minute one.
  ->addValue('manual_processing', TRUE)
  ->addValue('allow_verification_by_email', FALSE)
  ->addValue('create_submission', TRUE)
  ->execute();

// 'layout' has to be selected explicitly, or the read-back below silently
// reports that every marker is missing and looks like a failed write.
$after = Afform::get(FALSE)
  ->addSelect('name', 'layout', 'manual_processing', 'allow_verification_by_email')
  ->addWhere('name', '=', FORM)
  ->setLayoutFormat('html')
  ->execute()->first();

if (empty($after['layout'])) {
  echo "ERROR: read-back returned no layout; check the write.
";
  return;
}

echo "layout written, " . strlen($after['layout']) . " chars
";
foreach (['oar-mission', 'oar-terms', 'oar-agreements', 'af-layout-cols2', 'placeholder'] as $marker) {
  echo "  contains {$marker}: " . (str_contains($after['layout'], $marker) ? 'yes' : 'NO') . "
";
}
echo "manual_processing=" . json_encode($after['manual_processing'])
  . " allow_verification_by_email=" . json_encode($after['allow_verification_by_email']) . "
";
