# OVG Data Bridge

OVG Data Bridge sends selected survey data from one REDCap project to another. The transfer runs when the participant reaches the survey acknowledgement page.

Field mappings are kept in an `@RDB-MAP(...)` action tag in the source project's data dictionary. This allows data managers to maintain the mapping without access to the External Module configuration.

## Project Settings

- `Data Transmission URL`: the target REDCap API endpoint.
- `API Key`: an API token for the target project with permission to import and export records.

## Action Tag

Add the tag to a field annotation on the source instrument. For example:

```text
@RDB-MAP(
  condition="[demog_complete] = '2'",
  target_record_id_field="record_id",
  target_source_id_field="psq_id",
  target_form="demographics",
  target_event="baseline_arm_1",
  status_field="data_bridge_status",
  map="dob=>date_of_birth",
  map="sex=>sex",
  map="ethnicity=>ethnicity"
)
```

## Tag Options

- `condition` is optional REDCap logic. If it is omitted, the mapping runs whenever the survey acknowledgement page is reached.
- `target_record_id_field` is required and must name the primary record ID field in the target project. REDCap generates this value when a new target record is created.
- `target_source_id_field` is required and must name a secondary field in the target project. The module stores the source record ID in this field and uses it to find the same target record later.
- These must be two different fields.
- `target_form` is optional. When supplied, the module marks that target form Complete by setting `<target_form>_complete` to `2`.
- `target_event` is required when the target is longitudinal. Use the target project's unique event name. It is not needed for a classic project.
- `status_field` is optional but recommended. It must be a text field on the same source instrument. A non-text field is logged as invalid and the transfer is skipped.
- `map` is required. Add one entry for each field using `map="source_field=>target_field"`.

## Checkbox Behaviour

To copy the whole checkbox field, use `map="source_symptoms=>target_symptoms"`. The module copies each checkbox value with the same choice-code suffix, so the source and target fields should use matching choice codes.

To map choices individually, include the checkbox suffix, for example `map="source_symptoms___1=>target_symptoms___7"`.

Do not map a checkbox base field to a single target choice, such as `map="source_symptoms=>symptoms___7"`. The module logs and skips that mapping.

## Status Field Behaviour

When `status_field` is configured, the module records the outcome in the source project:

- blank = not attempted yet
- `SUCCESS` = pushed successfully
- `FAILED` = push failed and manual follow-up may be needed

The module only attempts a transfer while this field is blank. To retry a transfer, clear the status field and submit the survey again.

The module does not send email notifications. If notifications are required, configure a REDCap Alert based on the status field's failed value.

## Record Matching

Before sending data, the module searches the target project for the source record ID in `target_source_id_field`.

- If there is no match, REDCap creates a new record using its next auto-numbered record ID.
- If there is one matching record, the module updates that record using its generated target record ID.
- If more than one target record contains the same source ID, the transfer fails and the duplicate is written to the module log.

The source status field remains the first check. The target lookup provides a second safeguard if the target import succeeded but the source `SUCCESS` status could not be saved.

Record auto-numbering must be enabled in the target project.

## Notes

- The module runs on `redcap_survey_acknowledgement_page`.
- Multiple `@RDB-MAP(...)` tags can exist on the same instrument.
- Each valid tag is processed separately and sends its own request to the target project.
- Existing action tags using `target_record_field` must be updated to use `target_record_id_field` and `target_source_id_field`.
