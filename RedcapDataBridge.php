<?php

namespace OxfordVaccineGroup\RedcapDataBridge;

use REDCap;

class RedcapDataBridge extends \ExternalModules\AbstractExternalModule
{
    // Data Bridge action tag used to identify fields that should be transmitted to a target REDCap project.
    private const ACTION_TAG = '@RDB-MAP';
    private const STATUS_SUCCESS = 'SUCCESS';
    private const STATUS_FAILED = 'FAILED';

    // Cache parsed tag configs per project/instrument for the duration of the request.
    private array $configCache = [];

    public function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        static $isHandlingTransmission = false;

        // Prevent recursive calls and skip if required parameters are missing.
        if (
            $isHandlingTransmission ||
            $record === '' ||
            $instrument === '' ||
            empty($project_id) ||
            empty($event_id)
        ) {
            return;
        }


        $configs = $this->getTransmissionConfigs((int) $project_id, (string) $instrument);
        if (empty($configs)) {
            return;
        }

        $apiUrl = trim((string) $this->getProjectSetting('api_url'));
        $apiToken = trim((string) $this->getProjectSetting('api_key'));

        if ($apiUrl === '' || $apiToken === '') {
            $this->log('RDB-MAP transmission skipped because api_url or api_key is not configured.', []);
            return;
        }

        $isHandlingTransmission = true;

        try {
            $project = new \Project((int) $project_id);
            $sourceInstance = $this->normaliseInstance($repeat_instance);
            $sourceRepeatInstrument = $project->isRepeatingForm($event_id, $instrument) ? (string) $instrument : '';

            foreach ($configs as $config) {
                $this->processTransmissionConfig(
                    $project,
                    (int) $project_id,
                    (string) $record,
                    (string) $instrument,
                    (int) $event_id,
                    $sourceInstance,
                    $sourceRepeatInstrument,
                    $apiUrl,
                    $apiToken,
                    $config
                );
            }
        } finally {
            $isHandlingTransmission = false;
        }
    }

    /**
     * Executes one parsed tag configuration from start to finish.
     *
     * This method validates preconditions, reads source values, builds payload,
     * imports to target REDCap, and records SUCCESS/FAILED state.
     */
    private function processTransmissionConfig(
        \Project $project,
        int $projectId,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        string $sourceRepeatInstrument,
        string $apiUrl,
        string $apiToken,
        array $config
    ): void {
        $statusField = (string) ($config['status_field'] ?? '');

        try {
            if (!$this->conditionPasses($projectId, $record, $eventId, $sourceInstance, $sourceRepeatInstrument, $instrument, $config)) {
                return;
            }

            if (!$this->statusFieldIsValid($project, $instrument, $statusField)) {
                return;
            }

            $sourceFields = $this->getSourceFieldsForConfig($config);
            $sourceData = empty($sourceFields)
                ? []
                : $this->getCurrentSourceRow($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $sourceFields);

            if (!empty($sourceFields) && empty($sourceData)) {
                $this->log(
                    'RDB-MAP skipped tag on field "' . $config['source_field'] .
                    '" because no source data was found for record "' . $record . '".'
                    , []
                );
                return;
            }

            if ($this->sourceStatusBlocksTransmission($sourceData, $statusField)) {
                return;
            }

            $payload = $this->buildPayload($project, $record, $instrument, $eventId, $sourceInstance, $sourceData, $config);
            $targetRecordValue = (string) ($payload[$config['target_record_field']] ?? '');

            if ($targetRecordValue === '') {
                $this->log(
                    'RDB-MAP skipped tag on field "' . $config['source_field'] .
                    '" because the target record value resolved to blank.'
                    , []
                );
                return;
            }

            $this->importPayload($apiUrl, $apiToken, $payload);
            $this->saveSourceStatusSafely($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $statusField, self::STATUS_SUCCESS);
        } catch (\Throwable $exception) {
            $message = 'RDB-MAP failed for record "' . $record . '" on instrument "' . $instrument .
                '" from tag #' . $config['tag_index'] . ' on field "' . $config['source_field'] .
                '": ' . $exception->getMessage();

            $this->saveSourceStatusSafely($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $statusField, self::STATUS_FAILED);
            $this->log($message, []);
        }
    }

    /**
     * Evaluates an optional REDCap logic expression for a mapping config.
     *
     * Empty condition means "always true".
     */
    private function conditionPasses(
        int $projectId,
        string $record,
        int $eventId,
        int $sourceInstance,
        string $sourceRepeatInstrument,
        string $instrument,
        array $config
    ): bool {
        $condition = trim((string) ($config['condition'] ?? ''));
        if ($condition === '') {
            return true;
        }

        return REDCap::evaluateLogic(
            $condition,
            $projectId,
            $record,
            $eventId,
            $sourceInstance,
            $sourceRepeatInstrument,
            $instrument
        ) === true;
    }

    /**
     * Builds and caches all RDB-MAP transmission configs for one instrument.
     *
     * Source of truth is REDCap metadata (data dictionary) for fields on the
     * current instrument. For each field annotation containing @RDB-MAP, this
     * method delegates parsing to extractTagConfigs() and flattens all parsed
     * tags into a single config list.
     *
     * @param int $projectId Current REDCap project id.
     * @param string $instrument Instrument name whose fields should be scanned.
     * @return array<int, array<string, mixed>> Parsed config objects.
     */
    private function getTransmissionConfigs(int $projectId, string $instrument): array
    {
        //If the configs for this project/instrument are already cached, return them.
        if (isset($this->configCache[$projectId][$instrument])) {
            return $this->configCache[$projectId][$instrument];
        }

        $instrumentFields = REDCap::getFieldNames($instrument);
        if (empty($instrumentFields)) {
            $this->configCache[$projectId][$instrument] = [];
            return [];
        }


        $fieldsAttributes = REDCap::getDataDictionary($projectId, 'array', false, $instrumentFields);
        $configs = [];

        foreach ($fieldsAttributes as $fieldAttribute) {
            $annotation = trim((string) ($fieldAttribute['field_annotation'] ?? ''));
            //Skip if field annotation is empty or does not contain the action tag.
            if ($annotation === '' || stripos($annotation, self::ACTION_TAG) === false) {
                continue;
            }

            $fieldName = (string) ($fieldAttribute['field_name'] ?? '');
            foreach ($this->extractTagConfigs($annotation, $fieldName) as $config) {
                $configs[] = $config;
            }
        }

        $this->configCache[$projectId][$instrument] = $configs;
        return $configs;
    }

    /**
     * Extracts and normalises one or more @RDB-MAP(...) tags from a field annotation.
     *
     * Parsing behaviour:
     * - Tag name matching is case-insensitive (@RDB-MAP, @rdb-map, etc.).
     * - Attribute names are case-insensitive and normalised to lower-case keys.
     * - Attribute values must be in double quotes (key="value").
     * - map="source=>target" can appear multiple times and is collected as a list.
     *
     * Validation behaviour:
     * - target_record_field is required per tag.
     * - At least one valid map entry is required per tag.
     * - Invalid tags are skipped with a log entry; parsing continues for other tags.
     *
     * @param string $annotation Raw REDCap field annotation text.
     * @param string $fieldName Field name used for log context.
     * @return array<int, array<string, mixed>> List of parsed tag configs.
     */
    private function extractTagConfigs(string $annotation, string $fieldName): array
    {
        // Match full @RDB-MAP(...) blocks.
        // The recursive (?<inner>...) group allows nested parentheses and quoted
        // substrings inside the tag body without prematurely terminating the match.
        $pattern = '/@RDB-MAP\s*\(\s*(?<inner>(?:[^()"\'()]+' .
            '|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\((?&inner)\))*)\s*\)/si';

        $configs = [];
        if (!preg_match_all($pattern, $annotation, $matches, PREG_SET_ORDER)) {
            return $configs;
        }

        foreach ($matches as $index => $match) {
            $rawAttributes = $match['inner'] ?? '';
            $attributes = [];

            // Parse attributes in key="value" form.
            // Example: map="mrn=>target_mrn" target_record_field="target_id"
            if (preg_match_all('/([a-z_]+)\s*=\s*"((?:\\\\.|[^"\\\\])*)"/i', $rawAttributes, $attributeMatches, PREG_SET_ORDER)) {
                foreach ($attributeMatches as $attributeMatch) {
                    $key = strtolower($attributeMatch[1]);
                    $value = stripcslashes($attributeMatch[2]);

                    // 'map' can be repeated, so we collect all entries.
                    if ($key === 'map') {
                        $attributes['map'][] = $value;
                        continue;
                    }

                    $attributes[$key] = trim($value);
                }
            }

            $targetRecordField = trim((string) ($attributes['target_record_field'] ?? ''));
            if ($targetRecordField === '') {
                // Required to identify/create the target record.
                $this->log('RDB-MAP skipped a tag on field "' . $fieldName . '" because target_record_field is missing.', []);
                continue;
            }

            // Convert raw map strings into [{source, target}, ...].
            $maps = $this->parseMaps($attributes['map'] ?? [], $fieldName, $index + 1);
            if (empty($maps)) {
                // A tag without usable mappings cannot produce a payload.
                $this->log('RDB-MAP skipped a tag on field "' . $fieldName . '" because no valid map entries were found.', []);
                continue;
            }

            // Normalised config consumed by downstream transmission logic.
            $configs[] = [
                'condition' => trim((string) ($attributes['condition'] ?? '')),
                'target_record_field' => $targetRecordField,
                'target_form' => trim((string) ($attributes['target_form'] ?? '')),
                'target_event' => trim((string) ($attributes['target_event'] ?? '')),
                'status_field' => trim((string) ($attributes['status_field'] ?? '')),
                'maps' => $maps,
                'source_field' => $fieldName,
                'tag_index' => $index + 1,
            ];
        }

        return $configs;
    }

    /**
     * Parses map="source=>target" entries into structured source/target pairs.
     *
     * Invalid entries are skipped and logged (missing =>, blank side, etc.).
     *
     * @param array<int, string> $rawMaps Raw map strings from tag attributes.
     * @param string $fieldName Field name used for log context.
     * @param int $tagIndex 1-based index of tag occurrence in annotation.
     * @return array<int, array{source:string,target:string}>
     */
    private function parseMaps(array $rawMaps, string $fieldName, int $tagIndex): array
    {
        $maps = [];

        foreach ($rawMaps as $rawMap) {
            if (strpos($rawMap, '=>') === false) {
                $this->log(
                    'RDB-MAP skipped one map entry on field "' . $fieldName .
                    '" in tag #' . $tagIndex . ' because it does not contain "=>".'
                    , []
                );
                continue;
            }

            [$sourceField, $targetField] = array_map('trim', explode('=>', $rawMap, 2));
            if ($sourceField === '' || $targetField === '') {
                $this->log(
                    'RDB-MAP skipped one map entry on field "' . $fieldName .
                    '" in tag #' . $tagIndex . ' because the source or target field is blank.'
                    , []
                );
                continue;
            }

            $maps[] = [
                'source' => $sourceField,
                'target' => $targetField,
            ];
        }

        return $maps;
    }

    /**
     * Computes source field names needed for a single config.
     *
     * Base checkbox names are included once, and special runtime tokens are ignored
     * because they are resolved without reading source data.
     *
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function getSourceFieldsForConfig(array $config): array
    {
        $fields = [];

        foreach ($config['maps'] as $map) {
            $source = (string) $map['source'];
            if ($this->isSpecialSource($source)) {
                continue;
            }

            $fields[] = $this->getBaseFieldName($source);
        }

        $statusField = trim((string) ($config['status_field'] ?? ''));
        if ($statusField !== '') {
            $fields[] = $statusField;
        }

        return array_values(array_unique($fields));
    }

    /**
     * Retrieves the source row that matches the current submission context.
     *
     * For repeating data, REDCap may return multiple rows, so we select the row
     * that matches the current instrument/event repeat instance.
     *
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function getCurrentSourceRow(
        \Project $project,
        int $projectId,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        array $fields
    ): array {
        $params = [
            'project_id' => $projectId,
            'records' => [$record],
            'fields' => $fields,
            'events' => [$eventId],
            'return_format' => 'json-array',
            // Keep checkbox choices split as field___code so the mapped payload
            // can be sent back to REDCap in a format import supports.
            'combine_checkbox_values' => false,
        ];

        $data = REDCap::getData($params);
        if (!is_array($data) || empty($data)) {
            return [];
        }

        // Repeating contexts return multiple rows here, so pick the row that
        // matches the form/event instance that triggered the hook.
        $isRepeatingEvent = $project->isRepeatingEvent($eventId);
        $isRepeatingForm = $project->isRepeatingForm($eventId, $instrument);

        foreach ($data as $row) {
            $rowRepeatInstrument = (string) ($row['redcap_repeat_instrument'] ?? '');
            $rowRepeatInstance = $this->normaliseInstance($row['redcap_repeat_instance'] ?? 1);

            if ($isRepeatingForm) {
                if ($rowRepeatInstrument === $instrument && $rowRepeatInstance === $sourceInstance) {
                    return $row;
                }
                continue;
            }

            if ($isRepeatingEvent) {
                if ($rowRepeatInstrument === '' && $rowRepeatInstance === $sourceInstance) {
                    return $row;
                }
                continue;
            }

            if ($rowRepeatInstrument === '') {
                return $row;
            }
        }

        return $data[0];
    }

    /**
     * Builds one target payload array from mapping rules and source data.
     *
     * Always sets the target record field to the source record id to keep record
     * linkage explicit.
     *
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function buildPayload(
        \Project $project,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        array $sourceData,
        array $config
    ): array {
        $payload = [];

        foreach ($config['maps'] as $map) {
            $this->applyMapToPayload($payload, $project, $record, $instrument, $eventId, $sourceInstance, $sourceData, $map, $config);
        }

        // Keep the target record ID aligned with the source record ID.
        $payload[$config['target_record_field']] = $record;

        if ($config['target_event'] !== '') {
            $payload['redcap_event_name'] = $config['target_event'];
        }

        if ($config['target_form'] !== '') {
            $payload[$config['target_form'] . '_complete'] = '2';
        }

        return $payload;
    }

    /**
     * Applies a single map entry to the outgoing payload.
     *
     * Supports special runtime tokens, explicit checkbox codes, and base-field
     * checkbox expansion.
     *
     * @param array<string, string> $payload
     * @param array<string, mixed> $sourceData
     * @param array<string, string> $map
     * @param array<string, mixed> $config
     */
    private function applyMapToPayload(
        array &$payload,
        \Project $project,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        array $sourceData,
        array $map,
        array $config
    ): void {
        $sourceField = (string) $map['source'];
        $targetField = (string) $map['target'];

        if ($this->isSpecialSource($sourceField)) {
            $payload[$targetField] = $this->resolveScalarSourceValue(
                $sourceField,
                $project,
                $record,
                $instrument,
                $eventId,
                $sourceInstance,
                $sourceData
            );
            return;
        }

        if (strpos($sourceField, '___') !== false) {
            $payload[$targetField] = $this->normaliseFieldValue($sourceData[$sourceField] ?? '');
            return;
        }

        $checkboxValues = $this->extractCheckboxValues($sourceField, $sourceData);
        if (!empty($checkboxValues)) {
            if (strpos($targetField, '___') !== false) {
                $this->log(
                    'RDB-MAP skipped checkbox base mapping "' . $sourceField . '=>' . $targetField .
                    '" on field "' . $config['source_field'] . '". Use explicit checkbox codes instead.'
                    , []
                );
                return;
            }

            foreach ($checkboxValues as $checkboxField => $value) {
                $suffix = substr($checkboxField, strlen($sourceField));
                $payload[$targetField . $suffix] = $value;
            }
            return;
        }

        $payload[$targetField] = $this->normaliseFieldValue($sourceData[$sourceField] ?? '');
    }

    /**
     * Extracts checkbox values for a base field from a REDCap data row.
     *
     * Example base field "symptoms" matches keys like symptoms___1, symptoms___2.
     *
     * @param array<string, mixed> $sourceData
     * @return array<string, string>
     */
    private function extractCheckboxValues(string $baseFieldName, array $sourceData): array
    {
        $checkboxValues = [];

        foreach ($sourceData as $fieldName => $value) {
            if (strpos((string) $fieldName, $baseFieldName . '___') !== 0) {
                continue;
            }

            $checkboxValues[(string) $fieldName] = $this->normaliseFieldValue($value);
        }

        return $checkboxValues;
    }

    /**
     * Resolves a map source into a scalar string value.
     *
     * Special tokens are resolved first; otherwise source data is read directly.
     *
     * @param array<string, mixed> $sourceData
     */
    private function resolveScalarSourceValue(
        string $sourceField,
        \Project $project,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        array $sourceData
    ): string {
        $specialValue = $this->resolveSpecialSourceValue($sourceField, $project, $record, $instrument, $eventId, $sourceInstance);
        if ($specialValue !== null) {
            return $specialValue;
        }

        return $this->normaliseFieldValue($sourceData[$sourceField] ?? '');
    }

    /**
     * Resolves supported special source tokens to runtime values.
     *
     * Returns null when the source is not a recognised token.
     */
    private function resolveSpecialSourceValue(
        string $sourceField,
        \Project $project,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance
    ): ?string {
        switch (strtoupper($sourceField)) {
            case '__RECORD_ID__':
                return $record;

            case '__EVENT_ID__':
                return (string) $eventId;

            case '__EVENT_NAME__':
                return $project->longitudinal ? (string) $project->getUniqueEventNames($eventId) : '';

            case '__REPEAT_INSTANCE__':
                return (string) $sourceInstance;

            case '__INSTRUMENT__':
                return $instrument;

            default:
                return null;
        }
    }

    /**
     * Checks whether a map source is one of the supported special tokens.
     */
    private function isSpecialSource(string $sourceField): bool
    {
        return in_array(strtoupper($sourceField), [
            '__RECORD_ID__',
            '__EVENT_ID__',
            '__EVENT_NAME__',
            '__REPEAT_INSTANCE__',
            '__INSTRUMENT__',
        ], true);
    }

    /**
     * Removes checkbox suffix from a field name, if present.
     */
    private function getBaseFieldName(string $fieldName): string
    {
        return strpos($fieldName, '___') !== false
            ? (string) strstr($fieldName, '___', true)
            : $fieldName;
    }

    /**
     * Validates the configured status field is a text field on this instrument.
     */
    private function statusFieldIsValid(\Project $project, string $instrument, string $statusField): bool
    {
        if ($statusField === '') {
            return true;
        }

        if (!isset($project->metadata[$statusField])) {
            $this->log('RDB-MAP skipped because status_field "' . $statusField . '" does not exist.', []);
            return false;
        }

        $statusFieldForm = (string) ($project->metadata[$statusField]['form_name'] ?? '');
        if ($statusFieldForm !== $instrument) {
            $this->log(
                'RDB-MAP skipped because status_field "' . $statusField .
                '" is not on the current instrument "' . $instrument . '".'
                , []
            );
            return false;
        }

        $statusFieldType = (string) ($project->metadata[$statusField]['element_type'] ?? '');
        if ($statusFieldType !== 'text') {
            $this->log(
                'RDB-MAP skipped because status_field "' . $statusField . '" must be a text field.',
                []
            );
            return false;
        }

        return true;
    }

    /**
     * Returns true when status field is populated, which blocks retransmission.
     */
    private function sourceStatusBlocksTransmission(array $sourceData, string $statusField): bool
    {
        if ($statusField === '') {
            return false;
        }

        return trim($this->normaliseFieldValue($sourceData[$statusField] ?? '')) !== '';
    }

    /**
     * Persists SUCCESS/FAILED marker back to source project status field.
     *
     * Handles longitudinal and repeating context fields required by REDCap import.
     */
    private function saveSourceStatus(
        \Project $project,
        int $projectId,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        string $statusField,
        string $statusValue
    ): void {
        if ($statusField === '') {
            return;
        }

        $recordIdField = REDCap::getRecordIdField($projectId);
        $payload = [
            $recordIdField => $record,
            $statusField => $statusValue,
        ];

        if ($project->longitudinal) {
            $payload['redcap_event_name'] = (string) $project->getUniqueEventNames($eventId);
        }

        if ($project->isRepeatingForm($eventId, $instrument)) {
            $payload['redcap_repeat_instrument'] = $instrument;
            $payload['redcap_repeat_instance'] = (string) $sourceInstance;
        } elseif ($project->isRepeatingEvent($eventId)) {
            $payload['redcap_repeat_instance'] = (string) $sourceInstance;
        }

        $result = REDCap::saveData($projectId, 'json', json_encode([$payload]));
        if (!empty($result['errors'])) {
            throw new \RuntimeException('Failed to save source status field: ' . json_encode($result['errors']));
        }
    }

    /**
     * Best-effort status write that never throws to caller.
     */
    private function saveSourceStatusSafely(
        \Project $project,
        int $projectId,
        string $record,
        string $instrument,
        int $eventId,
        int $sourceInstance,
        string $statusField,
        string $statusValue
    ): void {
        try {
            $this->saveSourceStatus($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $statusField, $statusValue);
        } catch (\Throwable $exception) {
            $this->log(
                'RDB-MAP could not save status "' . $statusValue . '" to source field "' . $statusField .
                '" for record "' . $record . '": ' . $exception->getMessage()
                , []
            );
        }
    }

    /**
     * Imports one payload row into target REDCap via API.
     *
     * @param array<string, string> $payload
     */
    private function importPayload(string $apiUrl, string $apiToken, array $payload): void
    {
        $params = [
            'content' => 'record',
            'action' => 'import',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'normal',
            'data' => json_encode([$payload]),
            'returnContent' => 'ids',
            'returnFormat' => 'json',
        ];

        $this->callTargetApi($apiUrl, $apiToken, $params);
    }

    /**
     * Performs a POST request to target REDCap API and validates response.
     *
     * Throws RuntimeException on transport failures, HTTP errors, and API-level
     * error responses.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function callTargetApi(string $url, string $token, array $params): array
    {
        $requestData = array_merge(['token' => $token], $params);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($requestData, '', '&'));

        $output = curl_exec($curl);
        if ($output === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('cURL request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($statusCode >= 400) {
            $responseBody = trim((string) $output);
            if ($responseBody !== '') {
                throw new \RuntimeException('Target REDCap API returned HTTP ' . $statusCode . ': ' . $responseBody);
            }

            throw new \RuntimeException('Target REDCap API returned HTTP ' . $statusCode . '.');
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Target REDCap API returned an unexpected response: ' . $output);
        }

        if (isset($decoded['error']) && $decoded['error'] !== '') {
            throw new \RuntimeException((string) $decoded['error']);
        }

        if (!empty($decoded['errors'])) {
            throw new \RuntimeException(json_encode($decoded['errors']));
        }

        return $decoded;
    }

    /**
     * Converts repeat instance to a positive integer; defaults to 1.
     */
    private function normaliseInstance($repeatInstance): int
    {
        $instance = (int) $repeatInstance;
        return $instance > 0 ? $instance : 1;
    }

    /**
     * Normalises arbitrary REDCap values to string for payload consistency.
     */
    private function normaliseFieldValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
