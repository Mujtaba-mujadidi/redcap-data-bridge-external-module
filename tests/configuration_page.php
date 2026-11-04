<?php

require_once __DIR__ . '/../classes/RuleValidator.php';

use OxfordVaccineGroup\RedcapDataBridge\RuleValidator;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = [
    'longitudinal' => false,
    'forms' => [
        'demographics' => ['fields' => ['title', 'dob', 'bridge_status', 'coded_status']],
        'contact' => ['fields' => ['email', 'instructions']],
    ],
    'fields' => [
        'title' => ['form' => 'demographics', 'type' => 'radio', 'validation' => '', 'choices' => [
            ['code' => 'DR', 'label' => 'Dr'], ['code' => 'MR', 'label' => 'Mr'],
        ]],
        'dob' => ['form' => 'demographics', 'type' => 'text', 'validation' => 'date_ymd', 'choices' => []],
        'bridge_status' => ['form' => 'demographics', 'type' => 'text', 'validation' => '', 'choices' => []],
        'coded_status' => ['form' => 'demographics', 'type' => 'radio', 'validation' => '', 'choices' => [
            ['code' => '1', 'label' => 'sUcCeSs'], ['code' => '2', 'label' => 'FAILED'],
        ]],
        'email' => ['form' => 'contact', 'type' => 'text', 'validation' => 'email', 'choices' => []],
        'instructions' => ['form' => 'contact', 'type' => 'descriptive', 'validation' => '', 'choices' => []],
    ],
];
$target = [
    'longitudinal' => true,
    'events' => ['baseline_arm_1' => ['name' => 'baseline_arm_1', 'forms' => ['target_demographics', 'target_contact']]],
    'forms' => [
        'target_demographics' => ['fields' => ['record_id', 'source_id', 'title', 'dob']],
        'target_contact' => ['fields' => ['email']],
    ],
    'fields' => [
        'record_id' => ['form' => 'target_demographics', 'type' => 'text', 'validation' => '', 'choices' => [], 'record_id' => true],
        'source_id' => ['form' => 'target_demographics', 'type' => 'text', 'validation' => '', 'choices' => [], 'record_id' => false],
        'title' => ['form' => 'target_demographics', 'type' => 'radio', 'validation' => '', 'record_id' => false, 'choices' => [
            ['code' => '1', 'label' => 'Dr'], ['code' => '2', 'label' => 'Mr'],
        ]],
        'dob' => ['form' => 'target_demographics', 'type' => 'text', 'validation' => 'date_ymd', 'choices' => [], 'record_id' => false],
        'email' => ['form' => 'target_contact', 'type' => 'text', 'validation' => 'email', 'choices' => [], 'record_id' => false],
    ],
];
$rule = [
    'id' => '', 'name' => 'Demographics', 'enabled' => true,
    'source_instrument' => 'demographics', 'condition' => "[dob] <> ''",
    'target_event' => 'baseline_arm_1',
    'target_record_id_field' => 'record_id', 'target_source_id_field' => 'source_id',
    'status_field' => 'coded_status',
    'mappings' => [
        ['source' => 'title', 'target' => 'title', 'value_map' => ['DR' => '1', 'MR' => '2']],
        ['source' => 'dob', 'target' => 'dob', 'value_map' => []],
    ],
];

$valid = RuleValidator::validate([$rule], $source, $target, static function (): bool { return true; });
check($valid['errors'] === [], 'A compatible rule should validate.');
check($valid['rules'][0]['mappings'][0]['value_map']['DR'] === '1', 'Choice mapping should be preserved.');
check($valid['rules'][0]['mappings'][0]['target_choice_codes'] === ['1', '2'], 'Target choices should be stored for checkbox clearing.');

$crossForm = $rule;
$crossForm['mappings'][] = ['source' => 'email', 'target' => 'email', 'value_map' => []];
$valid = RuleValidator::validate([$crossForm], $source, $target, static function (): bool { return true; });
check($valid['errors'] === [], 'Mappings may use project fields outside the trigger instrument.');

$missingChoice = $rule;
$missingChoice['mappings'][0]['value_map'] = ['DR' => '1'];
$invalid = RuleValidator::validate([$missingChoice], $source, $target, static function (): bool { return true; });
check(count($invalid['errors']) === 1, 'Every source choice must map to a target choice.');

$wrongValidation = $target;
$wrongValidation['fields']['dob']['validation'] = 'date_dmy';
$invalid = RuleValidator::validate([$rule], $source, $wrongValidation, static function (): bool { return true; });
check(in_array('Rule 1, mapping 2 text validation types do not match.', $invalid['errors'], true), 'Text validation mismatch should fail.');

$valid = RuleValidator::validate([$rule], $source, $target, static function (): bool { return true; });
check($valid['rules'][0]['status_success_value'] === '1', 'Success status code should be detected case-insensitively.');
check($valid['rules'][0]['status_failed_value'] === '2', 'Failed status code should be detected case-insensitively.');

$dropdownStatus = $source;
$dropdownStatus['fields']['coded_status']['type'] = 'dropdown';
$valid = RuleValidator::validate([$rule], $dropdownStatus, $target, static function (): bool { return true; });
check($valid['errors'] === [], 'A dropdown status field should also validate.');

$invalidStatus = $rule;
$invalidStatus['status_field'] = 'bridge_status';
$invalid = RuleValidator::validate([$invalidStatus], $source, $target, static function (): bool { return true; });
check(in_array('Rule 1 status field must be a radio or dropdown field in the source project.', $invalid['errors'], true), 'Non-single-choice status field should fail.');

$missingStatus = $rule;
$missingStatus['status_field'] = '';
$invalid = RuleValidator::validate([$missingStatus], $source, $target, static function (): bool { return true; });
check(in_array('Rule 1 needs a source status field.', $invalid['errors'], true), 'Status field should be required.');

$missingStatusChoice = $source;
$missingStatusChoice['fields']['coded_status']['choices'][0]['label'] = 'Completed';
$invalid = RuleValidator::validate([$rule], $missingStatusChoice, $target, static function (): bool { return true; });
check(in_array('Rule 1 status field must contain one choice labelled "Success".', $invalid['errors'], true), 'Missing Success label should fail.');

$wrongEvent = $target;
$wrongEvent['events']['baseline_arm_1']['forms'] = ['another_form'];
$invalid = RuleValidator::validate([$rule], $source, $wrongEvent, static function (): bool { return true; });
check(in_array('Rule 1 target source-ID field is not assigned to the selected event.', $invalid['errors'], true), 'The source-ID field must be assigned to its selected event.');

$codedSourceId = $target;
$codedSourceId['fields']['source_id']['type'] = 'radio';
$invalid = RuleValidator::validate([$rule], $source, $codedSourceId, static function (): bool { return true; });
check(in_array('Rule 1 target source-ID field must be a text field.', $invalid['errors'], true), 'Target source-ID field must be text.');

$validatedSourceId = $target;
$validatedSourceId['fields']['source_id']['validation'] = 'integer';
$valid = RuleValidator::validate([$rule], $source, $validatedSourceId, static function (): bool { return true; });
check($valid['errors'] === [], 'An integer-validated text field may store numeric source record IDs.');

$missingCondition = $rule;
$missingCondition['condition'] = '';
$invalid = RuleValidator::validate([$missingCondition], $source, $target, static function (): bool { return true; });
check(in_array('Rule 1 needs a REDCap condition.', $invalid['errors'], true), 'A condition must be required.');

$descriptiveMapping = $rule;
$descriptiveMapping['mappings'][0]['source'] = 'instructions';
$invalid = RuleValidator::validate([$descriptiveMapping], $source, $target, static function (): bool { return true; });
check(in_array('Rule 1, mapping 1 cannot use a descriptive field.', $invalid['errors'], true), 'Descriptive fields cannot be mapped.');

echo "Configuration validation tests passed.\n";
