<?php

namespace OxfordVaccineGroup\RedcapDataBridge;

use REDCap;

require_once __DIR__ . '/classes/RuleValidator.php';

class RedcapDataBridge extends \ExternalModules\AbstractExternalModule
{
    private const CONFIGURATION_KEY = 'bridge_configuration';
    private const STATUS_SUCCESS = 'SUCCESS';
    private const STATUS_FAILED = 'FAILED';
    private const ENCRYPTED_TOKEN_PREFIX = 'rdb-token:v1:';

    // Avoid re-reading and filtering saved rules for each hook call in one request.
    private array $configCache = [];

    /** Allow super users, Design users, and explicitly delegated project roles. */
    public function canAccessProjectPage($projectId = null): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        $projectId = $projectId ?? $this->getProjectId();
        if (filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }
        if ($this->isSuperUser()) {
            return true;
        }

        $rights = $this->getUser()->getRights((int) $projectId);
        if (!is_array($rights) || $rights === []) {
            return false;
        }
        $expiration = (string) ($rights['expiration'] ?? '');
        $today = defined('TODAY') ? TODAY : date('Y-m-d');
        if ($expiration !== '' && $expiration <= $today) {
            return false;
        }
        if ((string) ($rights['design'] ?? '') === '1') {
            return true;
        }

        $roles = $this->getProjectSetting('configuration_roles', (int) $projectId);
        $roles = is_array($roles) ? array_map('strval', array_filter($roles, 'is_scalar')) : [];
        return !empty($rights['role_id']) && in_array((string) $rights['role_id'], $roles, true);
    }

    public function redcap_module_link_check_display($project_id, $link)
    {
        return $this->canAccessProjectPage($project_id) ? $link : null;
    }

    /** Return configuration data needed by the project page without exposing the API token. */
    public function getPageState(): array
    {
        $this->requirePageAccess();
        $configuration = $this->getBridgeConfiguration();
        $target = null;
        $connectionError = '';
        $apiToken = $this->getApiToken();
        if ($this->hasConnection($apiToken)) {
            try {
                $target = $this->loadTargetMetadata(
                    trim((string) $this->getProjectSetting('api_url')),
                    $apiToken
                );
            } catch (\Throwable $exception) {
                $connectionError = $exception->getMessage();
            }
        }

        return [
            'connection' => [
                'api_url' => trim((string) $this->getProjectSetting('api_url')),
                'has_api_key' => $apiToken !== '',
                'error' => $connectionError,
            ],
            'source' => $this->loadSourceMetadata(),
            'target' => $target,
            'configuration' => $this->configurationForPage($configuration),
        ];
    }

    /** Every browser action is authenticated by the framework and rechecks project access. */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id,
        $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        if ((int) $project_id !== (int) $this->getProjectId() || !$this->canAccessProjectPage($project_id)) {
            return ['success' => false, 'code' => 'forbidden', 'message' => 'You do not have permission to configure Data Bridge.'];
        }
        try {
            if ($action === 'reload-page') {
                return ['success' => true, 'state' => $this->getPageState()];
            }
            if ($action === 'test-connection') {
                return $this->testConnection($payload, false);
            }
            if ($action === 'save-connection') {
                return $this->testConnection($payload, true);
            }
            if ($action === 'save-rule') {
                return $this->saveRule($payload);
            }
            if ($action === 'delete-rule') {
                return $this->deleteRule($payload);
            }
            return ['success' => false, 'code' => 'invalid_action', 'message' => 'This action is not supported.'];
        } catch (\Throwable $exception) {
            $this->log('RDB page action failed: {action}: {error}', [
                'action' => (string) $action,
                'error' => $exception->getMessage(),
            ]);
            return ['success' => false, 'code' => 'unavailable', 'message' => 'The operation could not be completed. Ask a REDCap administrator to check the module log.'];
        }
    }

    private function testConnection($payload, bool $save): array
    {
        if (!is_array($payload)) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter the target connection details.'];
        }
        $url = trim((string) ($payload['api_url'] ?? ''));
        $token = trim((string) ($payload['api_key'] ?? ''));
        if ($token === '') {
            $token = $this->getApiToken();
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url) || $token === '') {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter a valid API URL and token.'];
        }

        try {
            $target = $this->loadTargetMetadata($url, $token);
        } catch (\Throwable $exception) {
            return ['success' => false, 'code' => 'connection', 'message' => 'Connection failed: ' . $exception->getMessage()];
        }
        if ($save) {
            $encryptedToken = $this->encryptApiToken($token);
            $this->setProjectSetting('api_url', $url);
            $this->setProjectSetting('api_key', $encryptedToken);
        }

        return [
            'success' => true,
            'saved' => $save,
            'message' => $save ? 'Target connection saved.' : 'Connection successful.',
            'connection' => ['api_url' => $url, 'has_api_key' => true, 'error' => ''],
            'target' => $target,
        ];
    }

    private function saveRule($payload): array
    {
        if (!is_array($payload) || !is_int($payload['revision'] ?? null) || !is_array($payload['rule'] ?? null)) {
            return ['success' => false, 'code' => 'validation', 'errors' => ['Reload the page before saving.']];
        }
        if (!$this->hasConnection()) {
            return ['success' => false, 'code' => 'validation', 'errors' => ['Save a working target connection first.']];
        }

        return $this->withConfigurationLock(function () use ($payload) {
            $current = $this->getBridgeConfiguration();
            if ($payload['revision'] !== $current['revision']) {
                return ['success' => false, 'code' => 'conflict', 'message' => 'Someone changed the rules after you opened the page. Reload before saving.'];
            }
            $source = $this->loadSourceMetadata();
            $target = $this->loadTargetMetadata(
                trim((string) $this->getProjectSetting('api_url')),
                $this->getApiToken()
            );
            $result = RuleValidator::validate(
                [$payload['rule']],
                $source,
                $target,
                static function (string $logic): bool {
                    return \LogicTester::isValid($logic);
                }
            );
            if ($result['errors']) {
                return ['success' => false, 'code' => 'validation', 'errors' => $result['errors']];
            }

            $rule = $result['rules'][0];
            $rules = $current['rules'];
            if ($rule['id'] === '') {
                if (count($rules) >= 50) {
                    return ['success' => false, 'code' => 'validation', 'errors' => ['A project can contain no more than 50 transfer rules.']];
                }
                $rule['id'] = 'rdb_' . bin2hex(random_bytes(12));
                $rules[] = $rule;
            } else {
                $index = array_search($rule['id'], array_column($rules, 'id'), true);
                if ($index === false) {
                    return ['success' => false, 'code' => 'conflict', 'message' => 'This rule no longer exists. Reload the page.'];
                }
                $rules[$index] = $rule;
            }
            if ($rules === $current['rules']) {
                return ['success' => true, 'saved_rule_id' => $rule['id'], 'configuration' => $this->configurationForPage($current), 'message' => 'No changes to save.'];
            }
            $next = $this->persistRules($current, $rules);
            return ['success' => true, 'saved_rule_id' => $rule['id'], 'configuration' => $this->configurationForPage($next), 'message' => 'Transfer rule saved.'];
        });
    }

    private function deleteRule($payload): array
    {
        if (!is_array($payload) || !is_int($payload['revision'] ?? null) || !is_string($payload['id'] ?? null)) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Reload the page before removing this rule.'];
        }
        return $this->withConfigurationLock(function () use ($payload) {
            $current = $this->getBridgeConfiguration();
            if ($payload['revision'] !== $current['revision']) {
                return ['success' => false, 'code' => 'conflict', 'message' => 'Someone changed the rules after you opened the page. Reload before removing this rule.'];
            }
            $rules = array_values(array_filter($current['rules'], static function (array $rule) use ($payload): bool {
                return ($rule['id'] ?? '') !== $payload['id'];
            }));
            if (count($rules) === count($current['rules'])) {
                return ['success' => false, 'code' => 'conflict', 'message' => 'This rule no longer exists. Reload the page.'];
            }
            $next = $this->persistRules($current, $rules);
            return ['success' => true, 'configuration' => $this->configurationForPage($next), 'message' => 'Transfer rule removed.'];
        });
    }

    private function persistRules(array $current, array $rules): array
    {
        $next = [
            'schema_version' => 1,
            'revision' => $current['revision'] + 1,
            'rules' => $rules,
        ];
        $this->setProjectSetting(self::CONFIGURATION_KEY, $next);
        $this->configCache = [];
        return $next;
    }

    /** Source metadata is read locally; participant values are never loaded for this page. */
    private function loadSourceMetadata(): array
    {
        $project = new \Project((int) $this->getProjectId());
        $forms = [];
        foreach ($project->forms as $name => $details) {
            $forms[$name] = ['name' => $name, 'label' => $this->plainLabel($details['menu'] ?? $name), 'fields' => []];
        }
        $fields = [];
        foreach ($project->metadata as $name => $metadata) {
            $form = (string) ($metadata['form_name'] ?? '');
            if (!isset($forms[$form]) || $name === $form . '_complete') {
                continue;
            }
            $field = [
                'name' => $name,
                'label' => $this->plainLabel($metadata['element_label'] ?? $name),
                'form' => $form,
                'type' => $this->normaliseFieldType((string) ($metadata['element_type'] ?? '')),
                'validation' => (string) ($metadata['element_validation_type'] ?? ''),
                'choices' => $this->parseChoices((string) ($metadata['element_enum'] ?? '')),
                'record_id' => $name === $project->table_pk,
            ];
            $fields[$name] = $field;
            $forms[$form]['fields'][] = $name;
        }
        return ['project_title' => (string) $project->project['app_title'], 'longitudinal' => (bool) $project->longitudinal, 'forms' => $forms, 'fields' => $fields];
    }

    /** Fetch and normalize only structural metadata required by the mapping page. */
    private function loadTargetMetadata(string $url, string $token): array
    {
        $projectInfo = $this->callTargetApi($url, $token, [
            'content' => 'project', 'format' => 'json', 'returnFormat' => 'json',
        ]);
        $metadataRows = $this->callTargetApi($url, $token, [
            'content' => 'metadata', 'format' => 'json', 'returnFormat' => 'json',
        ]);
        $longitudinal = !empty($projectInfo['is_longitudinal']);
        $events = [];
        if ($longitudinal) {
            $eventRows = $this->callTargetApi($url, $token, [
                'content' => 'event', 'format' => 'json', 'returnFormat' => 'json',
            ]);
            foreach ($eventRows as $event) {
                if (!is_array($event) || empty($event['unique_event_name'])) {
                    continue;
                }
                $events[(string) $event['unique_event_name']] = [
                    'name' => (string) $event['unique_event_name'],
                    'label' => $this->plainLabel($event['event_name'] ?? $event['unique_event_name']),
                    'forms' => [],
                ];
            }

            $formEventRows = $this->callTargetApi($url, $token, [
                'content' => 'formEventMapping', 'format' => 'json', 'returnFormat' => 'json',
            ]);
            foreach ($formEventRows as $mapping) {
                $eventName = (string) ($mapping['unique_event_name'] ?? '');
                $formName = (string) ($mapping['form'] ?? '');
                if ($eventName !== '' && $formName !== '' && isset($events[$eventName])) {
                    $events[$eventName]['forms'][] = $formName;
                }
            }
        }

        $forms = [];
        $fields = [];
        foreach ($metadataRows as $rowIndex => $metadata) {
            if (!is_array($metadata) || empty($metadata['field_name']) || empty($metadata['form_name'])) {
                continue;
            }
            $name = (string) $metadata['field_name'];
            $form = (string) $metadata['form_name'];
            if (!isset($forms[$form])) {
                $forms[$form] = ['name' => $form, 'label' => $form, 'fields' => []];
            }
            if ($name === $form . '_complete') {
                continue;
            }
            $field = [
                'name' => $name,
                'label' => $this->plainLabel($metadata['field_label'] ?? $name),
                'form' => $form,
                'type' => $this->normaliseFieldType((string) ($metadata['field_type'] ?? '')),
                'validation' => (string) ($metadata['text_validation_type_or_show_slider_number'] ?? ''),
                'choices' => $this->parseChoices((string) ($metadata['select_choices_or_calculations'] ?? '')),
                'record_id' => $rowIndex === 0,
            ];
            $fields[$name] = $field;
            $forms[$form]['fields'][] = $name;
        }
        if (!$fields) {
            throw new \RuntimeException('The target project returned no metadata.');
        }

        return [
            'project_title' => $this->plainLabel($projectInfo['project_title'] ?? 'Target REDCap project'),
            'longitudinal' => $longitudinal,
            'events' => $events,
            'forms' => $forms,
            'fields' => $fields,
        ];
    }

    private function getBridgeConfiguration(): array
    {
        $value = $this->getProjectSetting(self::CONFIGURATION_KEY);
        if ($value === null) {
            return ['schema_version' => 1, 'revision' => 0, 'rules' => []];
        }
        if (!is_array($value) || ($value['schema_version'] ?? null) !== 1
            || !is_int($value['revision'] ?? null) || !is_array($value['rules'] ?? null)) {
            throw new \RuntimeException('Stored Data Bridge configuration has an unsupported format.');
        }
        // Older configurations may contain a history key. It is deliberately
        // ignored and will be removed the next time a rule is saved.
        return [
            'schema_version' => 1,
            'revision' => $value['revision'],
            'rules' => $value['rules'],
        ];
    }

    private function configurationForPage(array $configuration): array
    {
        return [
            'revision' => $configuration['revision'],
            'rules' => $configuration['rules'],
        ];
    }

    /** Encrypt a target API token with REDCap's installation-specific key. */
    private function encryptApiToken(string $token): string
    {
        if ($token === '' || !function_exists('encrypt')) {
            throw new \RuntimeException('REDCap token encryption is not available.');
        }
        $encrypted = \encrypt($token);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new \RuntimeException('REDCap could not encrypt the target API token.');
        }
        return self::ENCRYPTED_TOKEN_PREFIX . $encrypted;
    }

    /**
     * Return the decrypted token and migrate legacy plaintext settings in place.
     * A prefix distinguishes ciphertext from older tokens without guessing.
     */
    private function getApiToken(): string
    {
        $stored = trim((string) $this->getProjectSetting('api_key'));
        if ($stored === '') {
            return '';
        }
        if (strpos($stored, self::ENCRYPTED_TOKEN_PREFIX) === 0) {
            if (!function_exists('decrypt')) {
                throw new \RuntimeException('REDCap token decryption is not available.');
            }
            $token = \decrypt(substr($stored, strlen(self::ENCRYPTED_TOKEN_PREFIX)));
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('The stored target API token could not be decrypted.');
            }
            return $token;
        }

        // Tokens saved by earlier module versions were plaintext. Re-save once
        // as ciphertext, then continue using the original value for this request.
        $this->setProjectSetting('api_key', $this->encryptApiToken($stored));
        return $stored;
    }

    private function hasConnection(?string $apiToken = null): bool
    {
        return trim((string) $this->getProjectSetting('api_url')) !== ''
            && ($apiToken ?? $this->getApiToken()) !== '';
    }

    public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        static $isHandlingTransmission = false;

        // Prevent recursive calls and skip if required parameters are missing.
        if (
            $isHandlingTransmission ||
            $record === null ||
            $record === '' ||
            $instrument === '' ||
            empty($project_id) ||
            empty($event_id)
        ) {
            return;
        }

        $isHandlingTransmission = true;

        try {
            $configs = $this->getTransmissionConfigs((int) $project_id, (string) $instrument);
            if (empty($configs)) {
                return;
            }

            $apiUrl = trim((string) $this->getProjectSetting('api_url'));
            $apiToken = $this->getApiToken();
            if ($apiUrl === '' || $apiToken === '') {
                $this->log('Data Bridge transmission skipped because the target connection is not configured.', []);
                return;
            }

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
        } catch (\Throwable $exception) {
            // Per-rule transfer failures are handled below. This catches failures
            // while loading configuration, connection details, or project context.
            $this->log(
                'Data Bridge could not start the transfer for record "' . (string) $record .
                '" on instrument "' . (string) $instrument . '": ' . $exception->getMessage(),
                []
            );
        } finally {
            $isHandlingTransmission = false;
        }
    }

    /**
     * Executes one saved transfer rule from start to finish.
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
        $successStatus = (string) ($config['status_success_value'] ?? self::STATUS_SUCCESS);
        $failedStatus = (string) ($config['status_failed_value'] ?? self::STATUS_FAILED);

        try {
            if (!$this->conditionPasses($projectId, $record, $eventId, $sourceInstance, $sourceRepeatInstrument, $instrument, $config)) {
                return;
            }

            if (!$this->statusFieldIsValid($project, $statusField, $successStatus, $failedStatus)) {
                return;
            }

            $sourceFields = $this->getSourceFieldsForConfig($config);
            $sourceData = empty($sourceFields)
                ? []
                : $this->getCurrentSourceRow($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $sourceFields);

            if (!empty($sourceFields) && empty($sourceData)) {
                $this->log(
                    'RDB rule "' . $config['name'] . '" found no source data for record "' . $record . '".'
                    , []
                );
                return;
            }

            // The status field may be on a different form, so read its own row
            // instead of assuming it is present on the triggering form's row.
            if ($statusField !== '') {
                $statusInstrument = (string) ($project->metadata[$statusField]['form_name'] ?? '');
                $statusData = $this->getCurrentSourceRow(
                    $project,
                    $projectId,
                    $record,
                    $statusInstrument,
                    $eventId,
                    $sourceInstance,
                    [$statusField]
                );
                $sourceData[$statusField] = $statusData[$statusField] ?? '';
            }

            if ($this->sourceStatusBlocksTransmission($sourceData, $statusField)) {
                return;
            }

            $payload = $this->buildPayload($record, $sourceData, $config);
            $targetRecordIds = $this->findTargetRecordIds($apiUrl, $apiToken, $record, $config);

            if (count($targetRecordIds) > 1) {
                throw new \RuntimeException(
                    'Multiple target records contain source ID "' . $record . '" in field "' .
                    $config['target_source_id_field'] . '".'
                );
            }

            $createNewRecord = empty($targetRecordIds);
            // REDCap requires a provisional ID even when forceAutoNumber replaces it.
            $payload[$config['target_record_id_field']] = $createNewRecord ? $record : $targetRecordIds[0];

            $this->importPayload($apiUrl, $apiToken, $payload, $createNewRecord);
            $this->saveSourceStatusSafely($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $statusField, $successStatus);
        } catch (\Throwable $exception) {
            $message = 'RDB rule "' . $config['name'] . '" failed for record "' . $record .
                '" on instrument "' . $instrument . '": ' . $exception->getMessage();

            $this->saveSourceStatusSafely($project, $projectId, $record, $instrument, $eventId, $sourceInstance, $statusField, $failedStatus);
            $this->log($message, []);
        }
    }

    /**
     * Evaluates the required REDCap logic expression for a transfer rule.
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
            // Stored rules should already be validated, but fail closed if an old
            // or malformed configuration reaches the runtime path.
            return false;
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

    /** Return enabled saved rules for the instrument that triggered the survey hook. */
    private function getTransmissionConfigs(int $projectId, string $instrument): array
    {
        if (isset($this->configCache[$projectId][$instrument])) {
            return $this->configCache[$projectId][$instrument];
        }
        $configs = [];
        foreach ($this->getBridgeConfiguration()['rules'] as $rule) {
            if (!empty($rule['enabled']) && ($rule['source_instrument'] ?? '') === $instrument) {
                $configs[] = $rule;
            }
        }
        $this->configCache[$projectId][$instrument] = $configs;
        return $configs;
    }

    /**
     * Computes the source fields needed for one transfer rule.
     *
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function getSourceFieldsForConfig(array $config): array
    {
        $fields = [];

        foreach ($config['mappings'] as $mapping) {
            $fields[] = (string) $mapping['source'];
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

        // Never substitute data from another repeat instance when the exact row
        // that triggered the hook is missing.
        return [];
    }

    /**
     * Builds one target payload array from mapping rules and source data.
     *
     * Always stores the source record ID in the configured secondary target field.
     *
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function buildPayload(string $record, array $sourceData, array $config): array
    {
        $payload = [];

        foreach ($config['mappings'] as $mapping) {
            $this->applyMappingToPayload($payload, $sourceData, $mapping);
        }

        // This value is used to find the same target record on future attempts.
        $payload[$config['target_source_id_field']] = $record;

        if ($config['target_event'] !== '') {
            $payload['redcap_event_name'] = $config['target_event'];
        }

        return $payload;
    }

    /**
     * Apply one validated mapping, translating choice codes when configured.
     *
     * @param array<string, string> $payload
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $mapping
     */
    private function applyMappingToPayload(
        array &$payload,
        array $sourceData,
        array $mapping
    ): void {
        $sourceField = (string) $mapping['source'];
        $targetField = (string) $mapping['target'];
        $valueMap = is_array($mapping['value_map'] ?? null) ? $mapping['value_map'] : [];

        $checkboxValues = $this->extractCheckboxValues($sourceField, $sourceData);
        if (!empty($checkboxValues)) {
            foreach (($mapping['target_choice_codes'] ?? []) as $targetCode) {
                $payload[$targetField . '___' . $targetCode] = '0';
            }
            foreach ($checkboxValues as $checkboxField => $value) {
                $sourceCode = substr($checkboxField, strlen($sourceField) + 3);
                $targetCode = (string) ($valueMap[$sourceCode] ?? $sourceCode);
                $payload[$targetField . '___' . $targetCode] = $value;
            }
            return;
        }

        $value = $this->normaliseFieldValue($sourceData[$sourceField] ?? '');
        $payload[$targetField] = $value !== '' && isset($valueMap[$value]) ? (string) $valueMap[$value] : $value;
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
     * Validates the configured status field can store the configured outcome.
     */
    private function statusFieldIsValid(
        \Project $project,
        string $statusField,
        string $successStatus,
        string $failedStatus
    ): bool
    {
        if ($statusField === '') {
            return true;
        }

        if (!isset($project->metadata[$statusField])) {
            $this->log('Data Bridge skipped because status field "' . $statusField . '" does not exist.', []);
            return false;
        }

        $statusFieldType = (string) ($project->metadata[$statusField]['element_type'] ?? '');
        if (!in_array($statusFieldType, ['radio', 'select'], true)) {
            $this->log(
                'Data Bridge skipped because status field "' . $statusField . '" has an unsupported field type.',
                []
            );
            return false;
        }
        $choices = $this->parseChoices((string) ($project->metadata[$statusField]['element_enum'] ?? ''));
        $labelsByCode = [];
        foreach ($choices as $choice) {
            $labelsByCode[(string) $choice['code']] = strtolower(trim((string) $choice['label']));
        }
        if (($labelsByCode[$successStatus] ?? '') !== 'success' || ($labelsByCode[$failedStatus] ?? '') !== 'failed') {
            $this->log('Data Bridge skipped because the status field no longer contains the configured Success and Failed choices.', []);
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

        $statusInstrument = (string) ($project->metadata[$statusField]['form_name'] ?? $instrument);
        if ($project->isRepeatingForm($eventId, $statusInstrument)) {
            $payload['redcap_repeat_instrument'] = $statusInstrument;
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
                'Data Bridge could not save status "' . $statusValue . '" to source field "' . $statusField .
                '" for record "' . $record . '": ' . $exception->getMessage()
                , []
            );
        }
    }

    /**
     * Finds target records already linked to this source record.
     *
     * @return array<int, string>
     */
    private function findTargetRecordIds(
        string $apiUrl,
        string $apiToken,
        string $sourceRecordId,
        array $config
    ): array {
        $targetRecordIdField = (string) $config['target_record_id_field'];
        $targetSourceIdField = (string) $config['target_source_id_field'];
        $escapedSourceRecordId = str_replace(['\\', "'"], ['\\\\', "\\'"], $sourceRecordId);

        $params = [
            'content' => 'record',
            'action' => 'export',
            'format' => 'json',
            'type' => 'flat',
            'fields' => [$targetRecordIdField, $targetSourceIdField],
            'filterLogic' => '[' . $targetSourceIdField . "] = '" . $escapedSourceRecordId . "'",
            'returnFormat' => 'json',
        ];

        if ($config['target_event'] !== '') {
            $params['events'] = [$config['target_event']];
        }

        $rows = $this->callTargetApi($apiUrl, $apiToken, $params);
        $recordIds = [];

        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row[$targetSourceIdField] ?? '') !== $sourceRecordId) {
                continue;
            }

            $targetRecordId = trim((string) ($row[$targetRecordIdField] ?? ''));
            if ($targetRecordId !== '') {
                $recordIds[$targetRecordId] = true;
            }
        }

        return array_keys($recordIds);
    }

    /**
     * Imports one payload row into target REDCap via API.
     *
     * @param array<string, string> $payload
     */
    private function importPayload(string $apiUrl, string $apiToken, array $payload, bool $createNewRecord): array
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

        if ($createNewRecord) {
            $params['forceAutoNumber'] = 'true';
        }

        return $this->callTargetApi($apiUrl, $apiToken, $params);
    }

    /**
     * Performs a POST request to target REDCap API and validates response.
     *
     * Throws RuntimeException on transport failures, HTTP errors, and API-level
     * error responses.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    private function callTargetApi(string $url, string $token, array $params): array
    {
        $requestData = array_merge(['token' => $token], $params);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // Do not follow redirects because an API redirect could expose the token
        // to a different host. Administrators should save the final API URL.
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
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

    private function withConfigurationLock(callable $callback): array
    {
        $name = 'redcap-data-bridge-config-' . (int) $this->getProjectId();
        $result = $this->query('SELECT GET_LOCK(?, 5) AS acquired', [$name]);
        if ((int) ($result->fetch_assoc()['acquired'] ?? 0) !== 1) {
            return ['success' => false, 'code' => 'busy', 'message' => 'Another configuration change is being saved. Try again.'];
        }
        try {
            return $callback();
        } finally {
            $this->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function normaliseFieldType(string $type): string
    {
        $aliases = ['select' => 'dropdown', 'textarea' => 'notes'];
        return $aliases[$type] ?? $type;
    }

    /** Parse REDCap choices without changing their raw codes or order. */
    private function parseChoices(string $choices): array
    {
        if (trim($choices) === '') {
            return [];
        }
        $result = [];
        // Local Project metadata may store line breaks as the two characters
        // "\\n", while API metadata normally returns real line breaks.
        foreach (preg_split('/\s*(?:\||\\\\n|\r\n|\r|\n)\s*/', trim($choices)) as $choice) {
            if ($choice === '' || strpos($choice, ',') === false) {
                continue;
            }
            [$code, $label] = array_map('trim', explode(',', $choice, 2));
            if ($code !== '') {
                $result[] = ['code' => $code, 'label' => $this->plainLabel($label)];
            }
        }
        return $result;
    }

    private function plainLabel($value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
    }

    private function requirePageAccess(): void
    {
        if (!$this->canAccessProjectPage()) {
            throw new \RuntimeException('Data Bridge configuration access is required.');
        }
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
