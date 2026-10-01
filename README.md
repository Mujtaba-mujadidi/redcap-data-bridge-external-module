# OVG Data Bridge

OVG Data Bridge sends selected survey data from one REDCap project to another. The transfer runs when the participant reaches the survey acknowledgement page.

Field mappings are kept in an `@RDB-MAP(...)` action tag in the source project's data dictionary. This allows data managers to maintain the mapping without access to the External Module configuration.

## Project Settings

- `Data Transmission URL`: the target REDCap API endpoint.
- `API Key`: an API token for the target project with permission to import records.

## Action Tag

Add the tag to a field annotation on the source instrument. For example:

```text
@RDB-MAP(
  condition="[demog_complete] = '2'",
  target_record_field="participant_id",
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
- `target_record_field` is required and must name the record ID field in the target project. Its value is always set to the source record ID. If the record already exists in the target project, REDCap updates it.
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

## Notes

- The module runs on `redcap_survey_acknowledgement_page`.
- Multiple `@RDB-MAP(...)` tags can exist on the same instrument.
- Each valid tag is processed separately and sends its own request to the target project.
