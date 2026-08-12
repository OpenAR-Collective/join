<?php
/**
 * The Mission Supporter Statement of Support form, in full.
 *
 * Like the membership form script, this holds the WHOLE layout. Afform::update
 * replaces everything, so a script carrying only the fields would delete the
 * Statement text and the Community Participation Terms the signer is agreeing
 * to. That is precisely what happened to the membership form once.
 *
 * If you change this form in the CiviCRM Form Builder, mirror the change here.
 *
 * The three agreements are forced to CheckBox. Left as their stored Boolean
 * type they render as Yes/No radio pairs, and a required radio pair is
 * satisfied by choosing No: an organization could sign the Statement while
 * answering No to agreeing to the terms and No to having authority to bind it.
 * That was live and accepted submissions. A required checkbox must be ticked.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file civi-afform-supporter.php
 */

civicrm_initialize();

use Civi\Api4\Afform;

const FORM = 'afformSupporterStatement';

$layout = <<<'SUPPORTER_LAYOUT'
<af-form ctrl="afform">
  <af-entity data="{ source: 'Mission Supporter statement'}" type="Organization" name="Organization1" label="Organization" actions="{create: true, update: false}" security="FBAC" />
  <fieldset af-fieldset="Organization1" class="af-container oar-form">
    <af-title>Sign the Statement of Support</af-title>
    
    <h3 class="oar-h">Our mission</h3>
<blockquote class="oar-mission"><p>The Open Accounts Receivable Collective Foundation works to make accounts receivable and debt collection more transparent, more compliant, and more accountable to the consumers it touches. It builds software under an open-source license so anyone can run, inspect, modify, and redistribute it at no cost. The Foundation also publishes open educational and compliance resources, develops shared standards, delivers training, and maintains a neutral, community-governed commons where practitioners learn from one another.</p></blockquote>
    <af-field name="MissionSupporter.mission_affirmation_org" defn="{required: true, input_type: 'CheckBox', label: 'Our organization has read the mission statement above, and supports the Foundation&rsquo;s charitable mission.'}" />
    <h3 class="oar-h">Your organization</h3>
    <div class="af-container af-layout-cols2">
      <af-field name="organization_name" defn="{required: true, label: 'Organization legal name'}" />
      <af-field name="MissionSupporter.trade_name" defn="{required: false, label: 'Trade name, if different', help_post: 'This is the name shown on the Foundation&rsquo;s public roster at <a href=&quot;https://openarcollective.org/supporters&quot; target=&quot;_blank&quot; rel=&quot;noopener&quot;>openarcollective.org/supporters</a>. Leave it blank to be listed under your legal name.'}" />
    </div>
    <af-field name="MissionSupporter.website_url" defn="{required: false, label: 'Website'}" />
    <h3 class="oar-h">About you</h3>
    <div class="af-container af-layout-cols2">
      <af-field name="MissionSupporter.signer_name" defn="{required: true, label: 'Your name'}" />
      <af-field name="MissionSupporter.signer_title" defn="{required: true, label: 'Your title'}" />
    </div>
    <af-field name="MissionSupporter.signer_email" defn="{required: true, label: 'Your business email address'}" />
    <h3 class="oar-h">The Statement and terms</h3>
    <p class="oar-terms-label">Scroll to read the full Statement and terms before agreeing.</p><div class="oar-terms" tabindex="0" role="region" aria-label="Scroll to read the full Statement and terms before agreeing."><h2 id="what-this-statement-is">What This Statement Is</h2>
<p>By signing below, your organization states publicly that it supports that mission. That is all this Statement does.</p>
<p>Signing costs nothing. There is no dues payment, no sponsorship, no donation, and no financial commitment of any kind, now or later. The Foundation will never condition your participation on a contribution.</p>
<p>Signing does not make your organization a member, partner, affiliate, or sponsor of the Foundation, and it gives your organization no governance role, vote, or ownership interest in the Foundation.</p>
<p>The Foundation is not endorsing your organization. It does not review, evaluate, certify, or approve your products, services, or business practices, and it makes no representation about them. Your listing reflects what you have said about yourself.</p>
<h2 id="what-your-organization-receives">What Your Organization Receives</h2>
<p><strong>Listing on the public roster.</strong> The Foundation will list your organization by name on the Mission Supporter roster on its website, together with your logo and a link to your website if you provide them. All organizations appear on identical terms, in alphabetical order, with no tiers and no indication of who has contributed financially.</p>
<p><strong>Use of the designation.</strong> The Foundation grants your organization a limited, non-exclusive, non-transferable, and revocable license to describe itself as an OpenAR Collective Mission Supporter, on the terms below.</p>
<h2 id="using-the-designation">Using the Designation</h2>
<p>Your organization may say, in its website, marketing materials, presentations, and communications:</p>
<ul>
<li><p>“[Organization] is an OpenAR Collective Mission Supporter.”</p></li>
<li><p>“[Organization] supports the mission of the OpenAR Collective.”</p></li>
<li><p>“[Organization] is a Mission Supporter of The Open Accounts Receivable Collective Foundation.”</p></li>
</ul>
<p>Your organization will not:</p>
<ul>
<li><p>Describe itself as a member, partner, affiliate, ally, sponsor, or contributor of or to the Foundation on the basis of this Statement;</p></li>
<li><p>Describe itself, or any of its products or services, as certified, approved, accredited, endorsed, recommended, validated, or powered by the Foundation;</p></li>
<li><p>Place the designation adjacent to a claim about its own products or services in a way that suggests the Foundation supports that claim;</p></li>
<li><p>Use the Foundation’s name, logo, or marks in any other manner, including in a product name, company name, domain name, or social media handle, without separate written permission under the Foundation’s Trademark Policy; or</p></li>
<li><p>Suggest that the Foundation has reviewed, evaluated, or approved the organization in any respect.</p></li>
</ul>
<p>At conferences and other events, the Foundation may hand out ribbons or tags for your representatives to wear with their conference credentials. The Foundation may also make badge images available later that your organization can put on a website or in materials. No badge images exist yet. Until the Foundation issues one, your organization will not make its own badge or other graphic using the Foundation’s logo or marks.</p>
<p>The license lasts as long as your organization participates in the program. If your organization withdraws, or if participation is revoked, your organization will stop using the designation.</p>
<h2 id="public-listing-and-records">Public Listing and Records</h2>
<p>Your organization’s name will appear publicly on the roster. The Foundation will keep a record of your organization’s name, website, the name, title, and business email address of the person signing, the date of signature, and the version of this Statement signed.</p>
<p>The Foundation will use that information to administer the program, publish the roster, and communicate with your organization about the Foundation’s work, which may include invitations to support the Foundation financially. The Foundation will not sell that information and will not disclose it to third parties except as required by law or as necessary to operate the program. Your organization may opt out of Foundation communications at any time without leaving the roster.</p>
<h2 id="leaving-the-program">Leaving the Program</h2>
<p>Your organization may withdraw at any time, for any reason, by writing to the Foundation. The Foundation will remove your organization from the roster promptly.</p>
<p>The Foundation may revoke participation only for: material misrepresentation in this Statement, including as to the signer’s authority; use of the designation inconsistent with this Statement that is not corrected after notice; unlawful conduct directed at the Foundation, its community, or its participants; or a determination that continued participation would be unlawful. The Foundation will not revoke participation on the basis of your organization’s products, services, pricing, business model, customers, or business practices, or on the basis of any viewpoint regarding industry practices. If participation is revoked, your organization may ask the Board of Directors to reconsider within thirty days.</p>
<h2 id="community-participation-terms">Community Participation Terms</h2>
<p>These Terms apply to your organization’s participation. In these Terms, “you” means the participating organization, and “the Foundation” means The Open Accounts Receivable Collective Foundation, operating as The OpenAR Collective. References to the policy are to the Foundation’s Community Programs and Standards Policy, which is published on the Foundation’s website.</p>
<p><strong>1. What participation is not.</strong> Participation gives you no vote, no governance authority, no right to direct or approve the Foundation’s activities, positions, software, or standards, no right to notice of or attendance at meetings of the Board of Directors or its committees, and no ownership, financial, or property interest in the Foundation or its assets. Participation is not membership within the meaning of the Delaware General Corporation Law.</p>
<p><strong>2. No payment, ever.</strong> Participation requires no dues, fees, sponsorship, donation, or financial contribution of any kind. No contribution to the Foundation will confer participation, standing, priority, or any preference of any kind.</p>
<p><strong>3. No endorsement in either direction.</strong> The Foundation does not review, evaluate, certify, approve, or endorse you, your employer, or any organization’s products, services, or business practices, and makes no representation about them. You will not state or imply otherwise.</p>
<p><strong>4. Antitrust.</strong> Foundation community spaces and events may not be used to discuss or reach any agreement or understanding among competitors concerning prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal with any business. If a discussion approaches these subjects, you will end your participation in it and notify a moderator or the Foundation. This obligation applies regardless of whether the discussion occurs in a Foundation space, and you will not use your participation as an occasion for such a discussion elsewhere.</p>
<p><strong>5. Consumer privacy.</strong> You will not share any consumer’s personal or account information in any Foundation community space or with the Foundation, whether or not you believe the information has been anonymized. If you contribute data, code, documentation, or examples, you are responsible for ensuring they contain no consumer information.</p>
<p><strong>6. Confidential and competitively sensitive information.</strong> You will not disclose to the Foundation or its community any information you are not free to disclose, including your employer’s confidential information, information subject to a client contract or nondisclosure agreement, and information subject to legal privilege.</p>
<p><strong>7. Reporting concerns, and no retaliation.</strong> If you believe in good faith that the Foundation, or any person acting on its behalf, has violated the law or a Foundation policy, you may report it under the Foundation’s Whistleblower Policy, including anonymously. The Foundation prohibits retaliation against any person who makes such a report in good faith or who participates in an investigation, and will not suspend, revoke, or otherwise act against your participation because you made such a report. Reports made in bad faith, or with knowledge that the reported information is false, are not protected.</p>
<p><strong>8. Nothing here is legal or compliance advice.</strong> The Foundation publishes educational and compliance resources and develops open-source software. Nothing the Foundation publishes, and nothing said in a Foundation community space, is legal advice or a guarantee of compliance with any law, regulation, or contractual obligation. You are responsible for your own compliance and for obtaining your own professional advice.</p>
<p><strong>9. Intellectual property.</strong> You will respect the Foundation’s trademarks and the license terms of the Foundation’s software and published materials. Any use of the Foundation’s name, logo, or marks beyond what is expressly permitted requires separate written permission under the Foundation’s Trademark Policy. Contributions of code or content are governed by the Foundation’s Open Source Policy and its contributor terms.</p>
<p><strong>10. Accuracy.</strong> The information you provide will be truthful and current, and you will notify the Foundation promptly when it materially changes. Material misrepresentation is grounds for denial or revocation.</p>
<p><strong>11. Conduct.</strong> You will comply with the community standards in Article V of this policy and with the published rules of each Foundation platform, and with the reasonable directions of moderators. Good-faith criticism of the Foundation, its board, its software, its published positions, or its governance is never a violation of those standards and will never affect your participation.</p>
<p><strong>12. Your information.</strong> The Foundation will use the information you provide to administer the program, operate its community platforms, and communicate with you about the Foundation’s work, which may include invitations to support the Foundation financially. The Foundation will not sell your information and will not disclose it to third parties except as required by law or as necessary to operate the program. You may opt out of Foundation communications at any time without affecting your participation.</p>
<p><strong>13. Suspension, revocation, and withdrawal.</strong> You may withdraw at any time. The Foundation may suspend or revoke participation on the grounds and through the process stated in Article VII of this policy, which includes written notice and one appeal to the Board of Directors. Revocation creates no claim against the Foundation.</p>
<p><strong>14. Changes.</strong> The Foundation may amend this policy at any time. You are bound by the version of these Terms in force on the date you were admitted, and a later version applies to you only if you accept it.</p>
<p><strong>15. No contract for services.</strong> Participation is not a contract for goods or services and creates no financial obligation on either party.</p>
<h2 id="other-terms">Other Terms</h2>
<p>This Statement is not a contract for goods or services and creates no financial obligation on either party. It binds your organization to the version signed; if the Foundation issues a later version, your organization stays on this one unless it chooses to sign the newer version. The program is administered under a policy adopted by the Foundation’s Board of Directors, which the Board may amend or discontinue.</p></div>
    <af-field name="MissionSupporter.terms_agreement_org" defn="{required: true, input_type: 'CheckBox', label: 'Our organization has read and agrees to the Community Participation Terms above.'}" />
    <af-field name="MissionSupporter.authority_representation" defn="{required: true, input_type: 'CheckBox', label: 'I have authority to bind the organization named above, and the information provided is truthful and current.'}" />
  </fieldset>
  <button class="af-button btn btn-primary" ng-click="afform.submit()">Sign the Statement</button>
</af-form>
SUPPORTER_LAYOUT;

$existing = Afform::get(FALSE)
  ->addSelect('name')
  ->addWhere('name', '=', FORM)
  ->execute()->first();

if (!$existing) {
  echo "ERROR: " . FORM . " does not exist. This script updates it; it does not create it.
";
  return;
}

// The Statement and the Terms are the parts with legal weight. Their silent
// absence is what makes a bad write dangerous rather than merely untidy.
foreach (['What This Statement Is' => 'the Statement text',
          'Community Participation Terms' => 'the participation terms',
          'authority_representation' => 'the authority checkbox'] as $marker => $what) {
  if (!str_contains($layout, $marker)) {
    echo "ERROR: the layout in this file is missing {$what}. Refusing to write it.
";
    return;
  }
}

Afform::update(FALSE)
  ->addWhere('name', '=', FORM)
  ->addValue('layout', $layout)
  ->addValue('title', 'Mission Supporter Statement of Support')
  ->addValue('server_route', 'civicrm/supporter-statement')
  ->addValue('is_public', TRUE)
  ->addValue('permission', ['*always allow*'])
  ->addValue('manual_processing', TRUE)
  ->addValue('allow_verification_by_email', FALSE)
  ->addValue('create_submission', TRUE)
  ->execute();

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
foreach (['What This Statement Is', 'Community Participation Terms', 'oar-terms', 'authority_representation'] as $marker) {
  echo "  contains {$marker}: " . (str_contains($after['layout'], $marker) ? 'yes' : 'NO') . "
";
}
echo "manual_processing=" . json_encode($after['manual_processing'])
  . " allow_verification_by_email=" . json_encode($after['allow_verification_by_email']) . "
";
