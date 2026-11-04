# OVG Data Bridge

OVG Data Bridge sends selected survey data from one REDCap project to another after a configured source survey is completed. Users with the module configuration access can configure connections and transfer rules from the **Data Bridge** project page.

## Access

The project page is available to:

- REDCap super users
- Project users with Design rights
- Members of project roles selected in the module's `configuration_roles` setting

A delegated role does not need External Module management rights. The module checks access on every page and AJAX request.

## Before You Start

The target project must have:

- Record auto-numbering enabled
- A text field that will store the source record ID
- Fields that are compatible with the source fields being transferred
- An API token with metadata export, record export and record import rights

The source project must have a radio or dropdown status field with exactly one choice labelled `Success` and one choice labelled `Failed`. Matching is case-insensitive, and the raw choice codes may be anything.

## Setup

1. Enable the module in the source project.
2. If data managers need access without Design rights, select their project roles in the module's `configuration_roles` setting.
3. Open **Data Bridge** from the project menu.
4. On the **Connection** tab, enter the target REDCap API URL and token.
5. Select **Test connection** to confirm that the target metadata can be loaded.
6. Select **Save connection**. The token is encrypted with REDCap's installation-specific encryption key before it is stored.
7. Open **Field mappings**, add a rule, complete all required settings and save the rule.
8. Complete a new response for the configured source survey and confirm the source status and target record.

The saved token is never returned to browser JavaScript.

## Transfer Rules

Each rule describes one transfer and contains the following settings.

### Rule name

A descriptive name that helps data managers identify the transfer. It does not affect when or how the rule runs.

### Active

Only active rules are evaluated. A rule can be disabled temporarily without deleting its configuration.

### Source instrument

The survey whose completion triggers the rule. A survey chain may begin on another instrument; the rule runs when this specific survey is completed.

### REDCap condition

A required REDCap logic expression that decides whether the transfer should proceed for the current record, event and repeat instance. For example:

```text
[consent] = '1'
```

If the condition is false, no transfer is attempted and the status field is not changed.

### Target event

Required only for longitudinal target projects. It tells REDCap which event receives the imported values. The forms containing the Target source-ID field and every mapped target field must be assigned to this event.

### Target record ID field

The target project's primary record ID field. For a new transfer, the module sends a provisional value and asks REDCap to replace it with the next auto-numbered record ID. For an existing match, the module sends the matched target record ID so that record is updated.

### Target source-ID field

A text field in the target project that stores the source record ID. Integer validation may be used when all source record IDs are numeric. The module searches for the source record ID before every import:

- No match creates a new auto-numbered target record.
- One match updates that target record.
- More than one match fails safely without importing data.

This field is the link between the source and target records and prevents a repeated survey submission from creating a duplicate target record. Record-only matching assumes that a target project receives data from one source project. Do not send records from multiple source projects to the same target when their record IDs may overlap.

### Source status field

A required source radio or dropdown field containing one `Success` choice and one `Failed` choice. The module detects and stores their raw codes when the rule is saved.

After a successful target import, the module writes the `Success` code. If the transfer fails, it writes the `Failed` code and records the reason in the External Module log. Any nonblank status blocks another automatic attempt. Clear the status field before resubmitting when a retry is required.

### Field mappings

Each mapping links one source field to one target field. Selectors show fields as `[field_name]: Field label` and can be searched by field name or label. Descriptive fields are excluded because they do not store record data.

The rule editor validates mappings against current project metadata:

- Field types must match.
- Text validation types must match.
- Radio, dropdown and checkbox choices are compared.
- Different choice codes must be mapped explicitly.
- Differences in labels or choice order are shown to the user.

Transfers use raw REDCap values rather than displayed labels. For example, a source radio value `DR` can be mapped to target code `4`.

For checkboxes, each source choice is mapped to a target choice. The module sends checkbox values in REDCap's `field___code` format and sends `0` for unselected target choices.

## How a Transfer Runs

When the configured source survey is completed, the module:

1. Loads active rules for that source instrument.
2. Evaluates each rule's REDCap condition.
3. Reads data from the exact source event and repeat instance.
4. Stops if the source status field is already populated.
5. Builds the target payload using the saved field and choice mappings.
6. Searches the target source-ID field for the source record ID.
7. Creates an auto-numbered target record or updates the single matching record.
8. Writes `Success` or `Failed` to the source status field.

The target form completion field is not included in the API payload, so the module does not change the destination form's completion status. If an exact repeating source row cannot be found, data from another repeat instance is never substituted.

Multiple active rules may use the same source instrument. They are evaluated independently in their saved order.

## Notifications and Logs

The module does not send email. Use REDCap Alerts when a notification is required, for example when the source status field becomes `Failed`.

Failures are written to the External Module log without storing the API token or full participant payload. Successful transfers are represented by the source status field and are not added to a separate transfer-history table.

Configuration saves use a revision check and project lock so one user's changes cannot silently overwrite another user's work. The module does not retain a configuration-change history.
