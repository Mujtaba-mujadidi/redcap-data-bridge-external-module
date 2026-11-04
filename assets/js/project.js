(function () {
    'use strict';

    window.RedcapDataBridgePage = {
        init: function (module) {
            const root = document.getElementById('rdb-page');
            if (!root) return;
            let state = JSON.parse(document.getElementById('rdb-bootstrap').textContent);
            let rules = JSON.parse(JSON.stringify(state.configuration.rules));
            let busy = false;
            let fieldListId = 0;
            let alertTimer = null;
            let expandedRuleIndex = -1;
            const byId = id => document.getElementById('rdb-' + id);

            function node(tag, text, className) {
                const item = document.createElement(tag);
                if (text !== undefined) item.textContent = text;
                if (className) item.className = className;
                return item;
            }

            function showMessage(text, errors) {
                const feedback = byId('feedback');
                const errorBox = byId('errors');
                window.clearTimeout(alertTimer);
                feedback.hidden = !text;
                feedback.replaceChildren();
                errorBox.replaceChildren();
                errorBox.hidden = !errors?.length;
                const addCloseButton = alert => {
                    const close = node('button', '×', 'rdb-alert-close');
                    close.type = 'button';
                    close.setAttribute('aria-label', 'Close alert');
                    close.addEventListener('click', () => { alert.hidden = true; });
                    alert.appendChild(close);
                };
                if (text) {
                    feedback.appendChild(node('span', text));
                    addCloseButton(feedback);
                }
                if (errors?.length) {
                    const list = node('ul');
                    errors.forEach(error => list.appendChild(node('li', error)));
                    errorBox.appendChild(list);
                    addCloseButton(errorBox);
                    errorBox.focus({preventScroll: true});
                }
                if (text && !errors?.length) {
                    alertTimer = window.setTimeout(() => { feedback.hidden = true; }, 5000);
                }
            }

            function setBusy(value, message) {
                busy = value;
                root.setAttribute('aria-busy', String(value));
                root.querySelectorAll('button, input, select, textarea').forEach(control => control.disabled = value);
                const loader = byId('loader');
                byId('loader-text').textContent = message || 'Processing…';
                loader.hidden = !value;
                loader.setAttribute('aria-hidden', String(!value));
                if (!value) renderAvailability();
            }

            function confirmRemoval(message) {
                return new Promise(resolve => {
                    const modal = byId('confirm');
                    const cancel = byId('confirm-cancel');
                    const remove = byId('confirm-remove');
                    byId('confirm-message').textContent = message;
                    modal.hidden = false;

                    const finish = confirmed => {
                        modal.hidden = true;
                        cancel.onclick = null;
                        remove.onclick = null;
                        modal.onkeydown = null;
                        resolve(confirmed);
                    };
                    cancel.onclick = () => finish(false);
                    remove.onclick = () => finish(true);
                    modal.onclick = event => {
                        if (event.target === modal) finish(false);
                    };
                    modal.onkeydown = event => {
                        if (event.key === 'Escape') finish(false);
                    };
                    cancel.focus();
                });
            }

            function labelled(labelText, control) {
                const label = node('label');
                label.append(document.createTextNode(labelText), control);
                return label;
            }

            function searchableControl(name, items, value, placeholder, onSelect) {
                const wrapper = node('div', undefined, 'rdb-searchable-field');
                const listId = 'rdb-field-list-' + (++fieldListId);
                const input = document.createElement('input');
                input.type = 'search';
                input.className = 'rdb-field-combobox';
                input.placeholder = placeholder || 'Search fields';
                input.setAttribute('aria-label', placeholder || 'Search fields');
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-controls', listId);
                input.setAttribute('aria-expanded', 'false');
                input.autocomplete = 'off';
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                const list = node('div', undefined, 'rdb-field-options');
                list.id = listId;
                list.setAttribute('role', 'listbox');
                list.hidden = true;

                const selected = items.find(item => item.value === value);
                input.value = selected?.label || '';
                hidden.value = selected?.value || '';

                function closeList() {
                    list.hidden = true;
                    input.setAttribute('aria-expanded', 'false');
                }

                function renderOptions(showAll) {
                    const query = showAll ? '' : input.value.trim().toLowerCase();
                    const matches = items.filter(item => !query || item.label.toLowerCase().includes(query));
                    list.replaceChildren();
                    matches.forEach(item => {
                        const option = node('button', item.label, 'rdb-field-option');
                        option.type = 'button';
                        option.setAttribute('role', 'option');
                        option.addEventListener('mousedown', event => {
                            // Keep the combobox focused until the clicked option is committed.
                            event.preventDefault();
                        });
                        option.addEventListener('click', () => {
                            input.value = item.label;
                            hidden.value = item.value;
                            closeList();
                            if (onSelect) onSelect(item.value);
                        });
                        list.appendChild(option);
                    });
                    if (!matches.length) list.appendChild(node('p', 'No matching fields', 'rdb-field-no-results'));
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }

                input.addEventListener('focus', () => {
                    input.select();
                    renderOptions(true);
                });
                input.addEventListener('click', () => renderOptions(true));
                input.addEventListener('input', () => {
                    const match = items.find(item => item.label === input.value || item.value === input.value);
                    hidden.value = match?.value || '';
                    renderOptions();
                });
                input.addEventListener('change', () => {
                    const match = items.find(item => item.label === input.value || item.value === input.value);
                    hidden.value = match?.value || '';
                    if (match) {
                        input.value = match.label;
                        if (onSelect) onSelect(match.value);
                    }
                });
                input.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        closeList();
                        input.blur();
                    } else if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (list.hidden) renderOptions();
                        list.querySelector('.rdb-field-option')?.focus();
                    }
                });
                wrapper.addEventListener('focusout', () => {
                    window.setTimeout(() => {
                        if (!wrapper.contains(document.activeElement)) closeList();
                    }, 0);
                });
                wrapper.append(input, hidden, list);
                return wrapper;
            }

            const formItems = metadata => Object.values(metadata?.forms || {}).map(form => ({
                value: form.name, label: '[' + form.name + ']: ' + form.label
            }));
            const fieldOptionLabel = field => '[' + field.name + ']: ' + field.label;
            const fieldItems = (metadata, form, filter) => ((metadata?.forms?.[form]?.fields || [])
                .map(name => metadata.fields[name]).filter(field => !filter || filter(field))
                .map(field => ({value: field.name, label: fieldOptionLabel(field)})));
            const allFieldItems = (metadata, filter) => Object.values(metadata?.fields || {})
                .filter(field => field.type !== 'descriptive' && (!filter || filter(field)))
                .map(field => ({
                    value: field.name,
                    label: fieldOptionLabel(field)
                }));

            function syncRulesFromPage() {
                const cards = Array.from(root.querySelectorAll('.rdb-rule'));
                if (!cards.length) return;
                rules = cards.map(card => {
                    const index = Number(card.dataset.index);
                    const previous = rules[index] || {};
                    const value = name => card.querySelector('[name="' + name + '"]')?.value.trim() || '';
                    const mappings = Array.from(card.querySelectorAll('.rdb-mapping')).map(row => {
                        const valueMap = {};
                        row.querySelectorAll('[data-source-code]').forEach(select => {
                            valueMap[select.dataset.sourceCode] = select.value;
                        });
                        return {
                            source: row.querySelector('[name="mapping_source"]').value,
                            target: row.querySelector('[name="mapping_target"]').value,
                            value_map: valueMap
                        };
                    });
                    return {
                        id: previous.id || '',
                        name: value('rule_name'),
                        enabled: card.querySelector('[name="enabled"]').checked,
                        source_instrument: value('source_instrument'),
                        condition: value('condition'),
                        target_event: value('target_event'),
                        target_record_id_field: value('target_record_id_field'),
                        target_source_id_field: value('target_source_id_field'),
                        status_field: value('status_field'),
                        status_success_value: value('status_success_value'),
                        status_failed_value: value('status_failed_value'),
                        mappings
                    };
                });
            }

            function compatibility(source, target) {
                if (!source || !target) return {text: 'Choose both fields', kind: ''};
                if (source.type !== target.type) return {text: source.type + ' → ' + target.type + ' (incompatible)', kind: 'bad'};
                if (source.type === 'text' && source.validation !== target.validation) {
                    return {text: 'Validation differs: ' + (source.validation || 'none') + ' → ' + (target.validation || 'none'), kind: 'bad'};
                }
                const sourceCodes = (source.choices || []).map(choice => choice.code);
                const targetCodes = (target.choices || []).map(choice => choice.code);
                if (!sourceCodes.length && !targetCodes.length) return {text: 'Compatible', kind: 'good'};
                const formatChoices = choices => choices.map(choice => '[' + choice.code + ']: ' + choice.label).join('; ');
                const sourceOnly = source.choices.filter(choice => !targetCodes.includes(choice.code));
                const targetOnly = target.choices.filter(choice => !sourceCodes.includes(choice.code));
                if (sourceOnly.length || targetOnly.length) {
                    const differences = [];
                    if (sourceOnly.length) differences.push('Target is missing source options: ' + formatChoices(sourceOnly));
                    if (targetOnly.length) differences.push('Target has additional options: ' + formatChoices(targetOnly));
                    return {text: differences.join(' '), kind: 'warn'};
                }
                const targetByCode = Object.fromEntries(target.choices.map(choice => [choice.code, choice]));
                const labelDifferences = source.choices.filter(choice => targetByCode[choice.code]?.label !== choice.label);
                const orderMatches = JSON.stringify(sourceCodes) === JSON.stringify(targetCodes);
                if (labelDifferences.length || !orderMatches) {
                    const differences = [];
                    if (!orderMatches) differences.push('Choice order differs.');
                    if (labelDifferences.length) {
                        differences.push('Labels differ: ' + labelDifferences.map(choice =>
                            '[' + choice.code + '] source "' + choice.label + '" / target "' + targetByCode[choice.code].label + '"'
                        ).join('; '));
                    }
                    return {text: differences.join(' '), kind: 'warn'};
                }
                return {text: 'Choice codes, labels and order match', kind: 'good'};
            }

            function renderMapping(rule, mapping, mappingIndex, ruleIndex) {
                const row = node('div', undefined, 'rdb-mapping');
                const sourceFields = allFieldItems(state.source);
                const targetFields = allFieldItems(state.target, field => !field.record_id);
                const selectSourceField = selectedValue => {
                    syncRulesFromPage();
                    rules[ruleIndex].mappings[mappingIndex].source = selectedValue;
                    renderRules();
                };
                const selectTargetField = selectedValue => {
                    syncRulesFromPage();
                    rules[ruleIndex].mappings[mappingIndex].target = selectedValue;
                    renderRules();
                };
                const sourceControl = searchableControl('mapping_source', sourceFields, mapping.source, 'Source field', selectSourceField);
                const targetControl = searchableControl('mapping_target', targetFields, mapping.target, 'Target field', selectTargetField);
                sourceControl.classList.add('rdb-source-control');
                targetControl.classList.add('rdb-target-control');
                const sourceInput = sourceControl.querySelector('.rdb-field-combobox');
                const targetInput = targetControl.querySelector('.rdb-field-combobox');
                sourceInput.id = 'rdb-source-' + ruleIndex + '-' + mappingIndex;
                targetInput.id = 'rdb-target-' + ruleIndex + '-' + mappingIndex;
                const sourceLabel = node('label', 'Source field', 'rdb-source-label');
                sourceLabel.htmlFor = sourceInput.id;
                const targetLabel = node('label', 'Target field', 'rdb-target-label');
                targetLabel.htmlFor = targetInput.id;
                const remove = node('button', 'Remove', 'rdb-button rdb-danger');
                remove.type = 'button';
                remove.addEventListener('click', async () => {
                    syncRulesFromPage();
                    if (!await confirmRemoval('Remove this field mapping? This change is not permanent until the rule is saved.')) return;
                    rules[ruleIndex].mappings.splice(mappingIndex, 1);
                    renderRules();
                });
                const controls = node('div', undefined, 'rdb-mapping-grid');
                controls.append(sourceLabel, targetLabel, sourceControl, node('span', '→', 'rdb-arrow'), targetControl, remove);
                row.appendChild(controls);

                const source = state.source.fields[mapping.source];
                const target = state.target?.fields?.[mapping.target];
                const result = compatibility(source, target);
                row.appendChild(node('p', result.text, 'rdb-compat rdb-' + result.kind));

                if (source?.choices?.length && target?.choices?.length) {
                    const choices = node('div', undefined, 'rdb-choice-map');
                    choices.appendChild(node('p', 'Option mapping', 'rdb-choice-title'));
                    const header = node('div', undefined, 'rdb-choice-row rdb-choice-header');
                    header.append(node('span', 'Source option'), node('span', ''), node('span', 'Target option'));
                    choices.appendChild(header);
                    source.choices.forEach(choice => {
                        const line = node('div', undefined, 'rdb-choice-row');
                        line.appendChild(node('span', '[' + choice.code + ']: ' + choice.label));
                        line.appendChild(node('span', '→'));
                        const targetChoice = searchableControl('choice_target', target.choices.map(item => ({
                            value: item.code, label: '[' + item.code + ']: ' + item.label
                        })), mapping.value_map?.[choice.code] || (target.choices.some(item => item.code === choice.code) ? choice.code : ''), 'Choose target option');
                        targetChoice.querySelector('[name="choice_target"]').dataset.sourceCode = choice.code;
                        line.appendChild(targetChoice);
                        choices.appendChild(line);
                    });
                    row.appendChild(choices);
                }
                return row;
            }

            function renderRule(rule, index) {
                const card = node('article', undefined, 'rdb-rule');
                card.dataset.index = String(index);
                const heading = node('div', undefined, 'rdb-rule-heading');
                const title = node('div', undefined, 'rdb-rule-title');
                const titleText = rule.name || 'New transfer rule';
                const titleHeading = node('h3', titleText);
                titleHeading.title = titleText;
                title.append(titleHeading, node('span', rule.enabled === false ? 'Inactive' : 'Active', 'rdb-rule-state'));
                heading.appendChild(title);
                const headingActions = node('div', undefined, 'rdb-actions');
                const isExpanded = expandedRuleIndex === index;
                const toggle = node('button', undefined, 'rdb-button rdb-rule-toggle' + (isExpanded ? ' rdb-expanded' : ''));
                toggle.type = 'button';
                toggle.setAttribute('aria-expanded', String(isExpanded));
                toggle.setAttribute('aria-label', isExpanded ? 'Collapse rule' : 'Expand rule');
                toggle.title = isExpanded ? 'Collapse rule' : 'Expand rule';
                toggle.addEventListener('click', () => {
                    syncRulesFromPage();
                    expandedRuleIndex = expandedRuleIndex === index ? -1 : index;
                    renderRules();
                });
                const remove = node('button', 'Remove rule', 'rdb-button rdb-danger');
                remove.type = 'button';
                remove.addEventListener('click', () => removeRule(index));
                headingActions.append(remove, toggle);
                heading.appendChild(headingActions);
                card.appendChild(heading);
                const body = node('div', undefined, 'rdb-rule-body');
                body.hidden = expandedRuleIndex !== index;

                const enabled = document.createElement('input');
                enabled.type = 'checkbox'; enabled.name = 'enabled'; enabled.checked = rule.enabled !== false;
                const name = document.createElement('input');
                name.name = 'rule_name'; name.required = true; name.maxLength = 160; name.value = rule.name || '';
                const selectRuleContext = () => {
                    syncRulesFromPage();
                    renderRules();
                };
                const sourceForm = searchableControl('source_instrument', formItems(state.source), rule.source_instrument, 'Choose source instrument', selectRuleContext);
                const nameField = labelled('Rule name', name);
                nameField.className = 'rdb-rule-name-field';
                const activeField = labelled('Active', enabled);
                activeField.className = 'rdb-rule-active-field';
                const sourceField = labelled('Source instrument', sourceForm);
                sourceField.className = 'rdb-rule-source-field';
                const top = node('div', undefined, 'rdb-rule-basics');
                top.append(nameField, activeField, sourceField);
                body.appendChild(top);

                const identity = node('div', undefined, 'rdb-grid-two');
                const recordFields = Object.values(state.target?.fields || {}).filter(field => field.record_id)
                    .map(field => ({value: field.name, label: fieldOptionLabel(field)}));
                const targetFields = allFieldItems(state.target, field => !field.record_id);
                const sourceIdField = labelled('Target source-ID field', searchableControl('target_source_id_field', targetFields.filter(field => {
                    const metadata = state.target.fields[field.value];
                    return metadata.type === 'text';
                }), rule.target_source_id_field, 'Choose text field'));
                identity.append(
                    labelled('Target record ID field', searchableControl('target_record_id_field', recordFields, rule.target_record_id_field, 'Choose record ID')),
                    sourceIdField
                );
                if (state.target?.longitudinal) {
                    const events = Object.values(state.target.events);
                    identity.appendChild(labelled('Target event', searchableControl('target_event', events.map(event => ({value: event.name, label: '[' + event.name + ']: ' + event.label})), rule.target_event, 'Choose target event')));
                } else {
                    const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'target_event'; hidden.value = '';
                    identity.appendChild(hidden);
                }
                const choicesNamed = (field, label) => (field?.choices || []).filter(choice =>
                    choice.label.trim().toLowerCase() === label
                );
                const validStatusField = field => ['radio', 'dropdown'].includes(field.type)
                    && choicesNamed(field, 'success').length === 1
                    && choicesNamed(field, 'failed').length === 1;
                const allStatusFields = Object.values(state.source.fields).filter(field => ['radio', 'dropdown'].includes(field.type));
                const statusFields = allFieldItems(state.source, validStatusField);
                const statusBlock = node('div', undefined, 'rdb-status-config');
                statusBlock.appendChild(node('h4', 'Status tracking'));
                statusBlock.appendChild(labelled('Source status field *', searchableControl('status_field', statusFields, rule.status_field, 'Choose required radio or dropdown', selectRuleContext)));
                const configuredStatusField = state.source.fields[rule.status_field];
                const statusField = validStatusField(configuredStatusField || {}) ? configuredStatusField : null;
                const successChoices = choicesNamed(statusField, 'success');
                const failedChoices = choicesNamed(statusField, 'failed');
                const success = document.createElement('input');
                success.type = 'hidden'; success.name = 'status_success_value'; success.value = successChoices.length === 1 ? successChoices[0].code : '';
                const failed = document.createElement('input');
                failed.type = 'hidden'; failed.name = 'status_failed_value'; failed.value = failedChoices.length === 1 ? failedChoices[0].code : '';
                statusBlock.append(success, failed);
                if (statusField) {
                    const detection = node('div', undefined, 'rdb-status-detection');
                    const detectedText = (label, choices) => choices.length === 1
                        ? label + ' detected as [' + choices[0].code + ']: ' + choices[0].label
                        : label + ' option is missing or duplicated';
                    detection.append(
                        node('p', detectedText('Success', successChoices), successChoices.length === 1 ? 'rdb-good' : 'rdb-bad'),
                        node('p', detectedText('Failed', failedChoices), failedChoices.length === 1 ? 'rdb-good' : 'rdb-bad')
                    );
                    statusBlock.appendChild(detection);
                } else if (rule.status_field) {
                    statusBlock.appendChild(node('p', 'The previously selected status field is not eligible. Choose a radio or dropdown containing one Success and one Failed choice.', 'rdb-bad rdb-status-warning'));
                } else if (!statusFields.length) {
                    statusBlock.appendChild(node('p', 'No eligible status field was found. Create a radio or dropdown with one Success choice and one Failed choice, then reload this page.', 'rdb-bad rdb-status-warning'));
                    const diagnostics = node('div', undefined, 'rdb-status-diagnostics');
                    diagnostics.appendChild(node('strong', 'Single-choice metadata received from REDCap:'));
                    const list = node('ul');
                    if (!allStatusFields.length) {
                        list.appendChild(node('li', 'No fields were reported with the radio or dropdown field type.'));
                    } else {
                        allStatusFields.forEach(field => {
                            const labels = field.choices.length
                                ? field.choices.map(choice => '[' + choice.code + ']: ' + choice.label).join('; ')
                                : 'No choices returned';
                            list.appendChild(node('li', '[' + field.name + ']: ' + field.label + ' - ' + labels));
                        });
                    }
                    diagnostics.appendChild(list);
                    statusBlock.appendChild(diagnostics);
                }
                statusBlock.appendChild(node('p', 'Required choices: Success and Failed. Labels are matched without regard to uppercase or lowercase.', 'rdb-help'));
                identity.appendChild(statusBlock);
                body.appendChild(identity);

                const condition = document.createElement('textarea');
                condition.name = 'condition'; condition.rows = 2; condition.required = true; condition.value = rule.condition || '';
                condition.placeholder = "For example: [consent] = '1'";
                body.appendChild(labelled('REDCap condition', condition));

                const mappingHeading = node('div', undefined, 'rdb-subheading');
                mappingHeading.appendChild(node('h4', 'Field mappings'));
                const add = node('button', 'Add field', 'rdb-button rdb-primary'); add.type = 'button';
                add.addEventListener('click', () => {
                    syncRulesFromPage();
                    rules[index].mappings.push({source: '', target: '', value_map: {}});
                    renderRules();
                    window.requestAnimationFrame(() => {
                        const rows = root.querySelectorAll('.rdb-rule[data-index="' + index + '"] .rdb-mapping');
                        rows[rows.length - 1]?.scrollIntoView({behavior: 'smooth', block: 'center'});
                    });
                });
                body.appendChild(mappingHeading);
                const mappings = node('div', undefined, 'rdb-mappings');
                (rule.mappings || []).forEach((mapping, mappingIndex) => mappings.appendChild(renderMapping(rule, mapping, mappingIndex, index)));
                if (!(rule.mappings || []).length) mappings.appendChild(node('p', 'No fields selected.', 'rdb-empty-inline'));
                body.appendChild(mappings);
                const ruleFooter = node('div', undefined, 'rdb-actions rdb-rule-footer');
                const save = node('button', 'Save rule', 'rdb-button rdb-primary');
                save.type = 'button';
                save.addEventListener('click', () => saveRule(index));
                ruleFooter.append(add, save);
                body.appendChild(ruleFooter);
                card.appendChild(body);
                return card;
            }

            function renderRules() {
                byId('rule-count').textContent = String(rules.length);
                const container = byId('rules');
                container.replaceChildren();
                rules.forEach((rule, index) => container.appendChild(renderRule(rule, index)));
                if (!rules.length && state.target) container.appendChild(node('div', 'No transfer rules configured.', 'rdb-empty'));
                renderAvailability();
            }

            function renderConnection() {
                const fields = byId('connection-form').elements;
                fields.api_url.value = state.connection.api_url || '';
                fields.api_key.value = '';
                fields.api_key.placeholder = state.connection.has_api_key ? 'Saved token is hidden; leave blank to keep it' : 'Enter API token';
                const connected = !!state.target;
                byId('connection-badge').textContent = connected ? 'Connected' : 'Not connected';
                byId('connection-badge').classList.toggle('rdb-connected', connected);
                const summary = byId('target-summary');
                summary.hidden = !connected;
                summary.textContent = connected ? state.target.project_title + ' · ' + Object.keys(state.target.forms).length + ' forms · ' + Object.keys(state.target.fields).length + ' fields' : '';
                if (state.connection.error) showMessage(null, [state.connection.error]);
            }

            function renderAvailability() {
                const unavailable = !state.target;
                byId('no-target').hidden = !unavailable;
                byId('add-rule').disabled = busy || unavailable;
                byId('reload').disabled = busy;
            }

            async function connectionAction(action) {
                if (busy || !byId('connection-form').reportValidity()) return;
                const fields = byId('connection-form').elements;
                setBusy(true, action === 'save-connection' ? 'Saving target connection…' : 'Testing target connection…'); showMessage();
                try {
                    const response = await module.ajax(action, {api_url: fields.api_url.value.trim(), api_key: fields.api_key.value.trim()});
                    if (!response.success) return showMessage(null, [response.message]);
                    if (response.saved) {
                        state.connection = response.connection;
                        state.target = response.target;
                        renderConnection(); renderRules();
                    } else {
                        const summary = byId('target-summary');
                        summary.hidden = false;
                        summary.textContent = response.target.project_title + ' · connection test passed';
                    }
                    showMessage(response.message);
                } catch (error) {
                    showMessage(null, ['The target connection could not be checked.']);
                } finally { setBusy(false); }
            }

            async function saveRule(index) {
                if (busy) return;
                syncRulesFromPage();
                const rule = rules[index];
                if (!rule?.condition) {
                    showMessage(null, ['This transfer rule needs a REDCap condition.']);
                    root.querySelector('.rdb-rule[data-index="' + index + '"] [name="condition"]')?.focus();
                    return;
                }
                const statusField = state.source.fields[rule.status_field];
                const statusInput = root.querySelector('.rdb-rule[data-index="' + index + '"] [name="status_field"]');
                const focusStatus = () => statusInput?.parentElement.querySelector('.rdb-field-combobox')?.focus();
                if (!statusField || !['radio', 'dropdown'].includes(statusField.type)) {
                    showMessage(null, ['Select a source radio or dropdown status field.']);
                    focusStatus();
                    return;
                }
                const labels = statusField.choices.map(choice => choice.label.trim().toLowerCase());
                if (labels.filter(label => label === 'success').length !== 1
                    || labels.filter(label => label === 'failed').length !== 1) {
                    showMessage(null, ['The status field must contain one choice labelled "Success" and one choice labelled "Failed". Labels are case-insensitive.']);
                    focusStatus();
                    return;
                }
                setBusy(true, 'Saving transfer rule…'); showMessage();
                try {
                    const response = await module.ajax('save-rule', {revision: state.configuration.revision, rule});
                    if (!response.success) return showMessage(null, response.errors || [response.message]);
                    state.configuration = response.configuration;
                    const savedRule = response.configuration.rules.find(item => item.id === response.saved_rule_id);
                    if (savedRule) rules[index] = JSON.parse(JSON.stringify(savedRule));
                    expandedRuleIndex = index;
                    renderRules(); showMessage(response.message);
                } catch (error) { showMessage(null, ['The transfer rule could not be saved.']); }
                finally { setBusy(false); }
            }

            async function removeRule(index) {
                if (busy) return;
                syncRulesFromPage();
                const rule = rules[index];
                if (!await confirmRemoval(rule.id
                    ? 'Remove this transfer rule? The saved rule will be deleted immediately.'
                    : 'Remove this unsaved transfer rule?')) return;
                if (!rule.id) {
                    rules.splice(index, 1);
                    expandedRuleIndex = rules.length ? Math.min(index, rules.length - 1) : -1;
                    renderRules();
                    return;
                }
                setBusy(true, 'Removing transfer rule…'); showMessage();
                try {
                    const response = await module.ajax('delete-rule', {revision: state.configuration.revision, id: rule.id});
                    if (!response.success) return showMessage(null, [response.message]);
                    state.configuration = response.configuration;
                    rules.splice(index, 1);
                    expandedRuleIndex = rules.length ? Math.min(index, rules.length - 1) : -1;
                    renderRules(); showMessage(response.message);
                } catch (error) { showMessage(null, ['The transfer rule could not be removed.']); }
                finally { setBusy(false); }
            }

            async function reload() {
                if (busy) return;
                setBusy(true, 'Reloading Data Bridge…'); showMessage();
                try {
                    const response = await module.ajax('reload-page', {});
                    if (!response.success) return showMessage(null, [response.message]);
                    state = response.state; rules = JSON.parse(JSON.stringify(state.configuration.rules));
                    expandedRuleIndex = -1;
                    renderAll(); showMessage('Configuration reloaded.');
                } catch (error) { showMessage(null, ['Data Bridge could not be reloaded.']); }
                finally { setBusy(false); }
            }

            function renderAll() { renderConnection(); renderRules(); }

            root.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click', () => {
                root.querySelectorAll('[data-tab]').forEach(item => item.setAttribute('aria-selected', String(item === button)));
                root.querySelectorAll('.rdb-tab-panel').forEach(panel => panel.hidden = panel.id !== 'rdb-tab-' + button.dataset.tab);
            }));
            byId('connection-form').addEventListener('submit', event => { event.preventDefault(); connectionAction('save-connection'); });
            byId('test-connection').addEventListener('click', () => connectionAction('test-connection'));
            byId('add-rule').addEventListener('click', () => {
                syncRulesFromPage(); rules.push({id: '', name: '', enabled: true, source_instrument: '', condition: '', target_event: '', target_record_id_field: '', target_source_id_field: '', status_field: '', status_success_value: '', status_failed_value: '', mappings: []}); expandedRuleIndex = rules.length - 1; renderRules();
            });
            byId('reload').addEventListener('click', reload);
            renderAll();
        }
    };
}());
