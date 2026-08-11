<?php
/**
 * Email must be a JOIN on the contact entity, not a separate af-entity.
 * Afform's verification routine looks for the address in the entity's joins
 * (Submit.php intersects submitted joins with the entity's declared joins), so
 * a separate Email entity yields no address and no confirmation email is sent.
 */
civicrm_initialize();

use Civi\Api4\Afform;

$layout = <<<HTML
<af-form ctrl="afform">
  <af-entity data="{contact_type: 'Individual', source: 'Membership application'}" type="Individual" name="Individual1" label="Applicant" actions="{create: true, update: false}" security="FBAC" />
  <fieldset af-fieldset="Individual1" class="af-container">
    <div class="af-container af-layout-inline">
      <af-field name="first_name" defn="{required: true}" />
      <af-field name="last_name" defn="{required: true}" />
    </div>
    <div af-join="Email" data="{is_primary: true}">
      <af-field name="email" defn="{required: true, label: 'Email address'}" />
    </div>
    <af-field name="Membership.employer_affiliation" defn="{required: true, label: 'Employer or affiliation'}" />
    <af-field name="job_title" defn="{required: true, label: 'Professional role or title'}" />
    <af-field name="Membership.linkedin_url" defn="{required: false, label: 'LinkedIn profile', help_pre: 'Optional. It helps us confirm your professional engagement more quickly.'}" />
    <af-field name="Membership.mission_affirmation" defn="{required: true, input_type: 'CheckBox', label: 'I have read the mission statement, and I support the Foundation\'s charitable mission'}" />
    <af-field name="Membership.terms_agreement" defn="{required: true, input_type: 'CheckBox', label: 'I have read and agree to the Community Participation Terms'}" />
    <af-field name="Membership.info_truthful" defn="{required: true, input_type: 'CheckBox', label: 'The information I have provided is truthful and current'}" />
  </fieldset>
  <button class="af-button btn btn-primary" ng-click="afform.submit()">Submit application</button>
</af-form>
HTML;

Afform::update(FALSE)
  ->addWhere('name', '=', 'afformMembershipApplication')
  ->setValues(['layout' => $layout])
  ->execute();

echo "layout rebuilt with Email as a join\n";

$a = Afform::get(FALSE)->addWhere('name', '=', 'afformMembershipApplication')
  ->addSelect('manual_processing', 'allow_verification_by_email', 'email_confirmation_template_id')->execute()->first();
echo "manual_processing=", var_export($a['manual_processing'], TRUE),
     " verify=", var_export($a['allow_verification_by_email'], TRUE),
     " tpl=", $a['email_confirmation_template_id'], "\n";
