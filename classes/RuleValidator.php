<?php

namespace OxfordVaccineGroup\RedcapDataBridge;

final class RuleValidator
{
    private const MAX_RULES = 50;
    private const MAX_MAPPINGS = 200;

    /** Validate browser input against live source and target metadata. */
    public static function validate($rules, array $source, array $target, callable $logicValidator): array
    {
        if (!is_array($rules) || !self::isList($rules)) {
            return ['rules' => [], 'errors' => ['The transfer rules are not in the expected format.']];
        }
        if (count($rules) > self::MAX_RULES) {
            return ['rules' => [], 'errors' => ['A project can contain no more than ' . self::MAX_RULES . ' transfer rules.']];
        }

        $normalised = [];
        $errors = [];
        $ids = [];
        foreach ($rules as $index => $rule) {
            $label = 'Rule ' . ($index + 1);
            if (!is_array($rule)) {
                $errors[] = $label . ' is invalid.';
                continue;
            }
            $result = self::validateRule($rule, $label, $source, $target, $logicValidator);
            $errors = array_merge($errors, $result['errors']);
            if ($result['rule'] !== null) {
                $id = $result['rule']['id'];
                if ($id !== '' && isset($ids[$id])) {
                    $errors[] = $label . ' has a duplicate internal ID.';
                }
                $ids[$id] = true;
                $normalised[] = $result['rule'];
            }
        }

        return ['rules' => $normalised, 'errors' => $errors];
    }

    private static function validateRule(
        array $input,
        string $label,
        array $source,
        array $target,
        callable $logicValidator
    ): array {
        $errors = [];
        $rule = [
            'id' => self::shortText($input['id'] ?? '', 80),
            'name' => self::shortText($input['name'] ?? '', 160),
            'enabled' => !empty($input['enabled']),
            'source_instrument' => self::fieldName($input['source_instrument'] ?? ''),
            'condition' => trim((string) ($input['condition'] ?? '')),
            'target_event' => self::shortText($input['target_event'] ?? '', 160),
            'target_record_id_field' => self::fieldName($input['target_record_id_field'] ?? ''),
            'target_source_id_field' => self::fieldName($input['target_source_id_field'] ?? ''),
            'status_field' => self::fieldName($input['status_field'] ?? ''),
            'status_success_value' => self::shortText($input['status_success_value'] ?? '', 100),
            'status_failed_value' => self::shortText($input['status_failed_value'] ?? '', 100),
            'mappings' => [],
        ];

        if ($rule['name'] === '') {
            $errors[] = $label . ' needs a name.';
        }
        $sourceForm = $source['forms'][$rule['source_instrument']] ?? null;
        if (!is_array($sourceForm)) {
            $errors[] = $label . ' has an invalid source instrument.';
        }
        if ($rule['condition'] === '') {
            $errors[] = $label . ' needs a REDCap condition.';
        } elseif (!$logicValidator($rule['condition'])) {
            $errors[] = $label . ' contains invalid REDCap logic.';
        }

        if (!empty($target['longitudinal'])) {
            if ($rule['target_event'] === '' || !isset($target['events'][$rule['target_event']])) {
                $errors[] = $label . ' needs a valid target event.';
            }
        } else {
            $rule['target_event'] = '';
        }

        $recordField = $target['fields'][$rule['target_record_id_field']] ?? null;
        if (!is_array($recordField) || empty($recordField['record_id'])) {
            $errors[] = $label . ' has an invalid target record ID field.';
        }
        $sourceIdField = $target['fields'][$rule['target_source_id_field']] ?? null;
        if (!is_array($sourceIdField) || ($sourceIdField['type'] ?? '') !== 'text') {
            $errors[] = $label . ' target source-ID field must be a text field.';
        } elseif (!empty($target['longitudinal']) && isset($target['events'][$rule['target_event']])
            && !in_array($sourceIdField['form'] ?? '', $target['events'][$rule['target_event']]['forms'] ?? [], true)) {
            $errors[] = $label . ' target source-ID field is not assigned to the selected event.';
        }
        if ($rule['target_record_id_field'] !== '' && $rule['target_record_id_field'] === $rule['target_source_id_field']) {
            $errors[] = $label . ' must use different record-ID and source-ID fields.';
        }

        if ($rule['status_field'] === '') {
            $errors[] = $label . ' needs a source status field.';
            $rule['status_success_value'] = '';
            $rule['status_failed_value'] = '';
        } else {
            $status = $source['fields'][$rule['status_field']] ?? null;
            if (!is_array($status) || !in_array($status['type'] ?? '', ['radio', 'dropdown'], true)) {
                $errors[] = $label . ' status field must be a radio or dropdown field in the source project.';
            } else {
                $successCodes = self::choiceCodesByLabel($status['choices'] ?? [], 'success');
                $failedCodes = self::choiceCodesByLabel($status['choices'] ?? [], 'failed');
                if (count($successCodes) !== 1) {
                    $errors[] = $label . ' status field must contain one choice labelled "Success".';
                }
                if (count($failedCodes) !== 1) {
                    $errors[] = $label . ' status field must contain one choice labelled "Failed".';
                }
                $rule['status_success_value'] = $successCodes[0] ?? '';
                $rule['status_failed_value'] = $failedCodes[0] ?? '';
            }
        }

        $mappings = $input['mappings'] ?? null;
        if (!is_array($mappings) || !self::isList($mappings) || $mappings === [] || count($mappings) > self::MAX_MAPPINGS) {
            $errors[] = $label . ' must contain between 1 and ' . self::MAX_MAPPINGS . ' field mappings.';
            return ['rule' => $rule, 'errors' => $errors];
        }

        $targets = [];
        foreach ($mappings as $mappingIndex => $mapping) {
            $mappingLabel = $label . ', mapping ' . ($mappingIndex + 1);
            $mappingResult = self::validateMapping($mapping, $mappingLabel, $rule, $source, $target);
            $errors = array_merge($errors, $mappingResult['errors']);
            if ($mappingResult['mapping'] === null) {
                continue;
            }
            $targetName = $mappingResult['mapping']['target'];
            if (isset($targets[$targetName])) {
                $errors[] = $mappingLabel . ' repeats target field "' . $targetName . '".';
            }
            $targets[$targetName] = true;
            $rule['mappings'][] = $mappingResult['mapping'];
        }

        return ['rule' => $rule, 'errors' => $errors];
    }

    private static function validateMapping($input, string $label, array $rule, array $source, array $target): array
    {
        if (!is_array($input)) {
            return ['mapping' => null, 'errors' => [$label . ' is invalid.']];
        }
        $sourceName = self::fieldName($input['source'] ?? '');
        $targetName = self::fieldName($input['target'] ?? '');
        $sourceField = $source['fields'][$sourceName] ?? null;
        $targetField = $target['fields'][$targetName] ?? null;
        $errors = [];

        if (!is_array($sourceField)) {
            $errors[] = $label . ' has an invalid source field.';
        }
        if (!is_array($targetField)) {
            $errors[] = $label . ' has an invalid target field.';
        }
        if ($sourceName === $rule['status_field']) {
            $errors[] = $label . ' cannot transfer the source status field.';
        }
        if (in_array($targetName, [$rule['target_record_id_field'], $rule['target_source_id_field']], true)) {
            $errors[] = $label . ' cannot overwrite a target identity field.';
        }
        if (!is_array($sourceField) || !is_array($targetField)) {
            return ['mapping' => null, 'errors' => $errors];
        }
        if (($sourceField['type'] ?? '') === 'descriptive' || ($targetField['type'] ?? '') === 'descriptive') {
            $errors[] = $label . ' cannot use a descriptive field.';
        }
        if (!empty($target['longitudinal'])
            && !in_array($targetField['form'] ?? '', $target['events'][$rule['target_event']]['forms'] ?? [], true)) {
            $errors[] = $label . ' target field is not assigned to the selected event.';
        }
        if (($sourceField['type'] ?? '') !== ($targetField['type'] ?? '')) {
            $errors[] = $label . ' field types do not match.';
        }
        if (($sourceField['type'] ?? '') === 'text'
            && ($sourceField['validation'] ?? '') !== ($targetField['validation'] ?? '')) {
            $errors[] = $label . ' text validation types do not match.';
        }

        $sourceChoices = array_column($sourceField['choices'] ?? [], null, 'code');
        $targetChoices = array_column($targetField['choices'] ?? [], null, 'code');
        $valueMap = [];
        if ($sourceChoices || $targetChoices) {
            if (!$sourceChoices || !$targetChoices) {
                $errors[] = $label . ' choice definitions do not match.';
            }
            $providedMap = is_array($input['value_map'] ?? null) ? $input['value_map'] : [];
            foreach ($sourceChoices as $sourceCode => $choice) {
                $targetCode = trim((string) ($providedMap[$sourceCode] ?? $sourceCode));
                if ($targetCode === '' || !isset($targetChoices[$targetCode])) {
                    $errors[] = $label . ' must map source choice "' . $sourceCode . '" to a valid target choice.';
                    continue;
                }
                $valueMap[$sourceCode] = $targetCode;
            }
        }

        return [
            'mapping' => [
                'source' => $sourceName,
                'target' => $targetName,
                'value_map' => $valueMap,
                'target_choice_codes' => array_map('strval', array_keys($targetChoices)),
            ],
            'errors' => $errors,
        ];
    }

    private static function fieldName($value): string
    {
        $value = trim((string) $value);
        return preg_match('/^[a-z][a-z0-9_]*$/D', $value) ? $value : '';
    }

    private static function shortText($value, int $length): string
    {
        $value = trim((string) $value);
        return mb_substr($value, 0, $length);
    }

    private static function choiceCodesByLabel(array $choices, string $label): array
    {
        $codes = [];
        foreach ($choices as $choice) {
            if (is_array($choice) && strcasecmp(trim((string) ($choice['label'] ?? '')), $label) === 0) {
                $codes[] = (string) ($choice['code'] ?? '');
            }
        }
        return array_values(array_filter($codes, static function (string $code): bool { return $code !== ''; }));
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
