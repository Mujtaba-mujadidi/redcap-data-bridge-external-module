<?php

/** @var \OxfordVaccineGroup\RedcapDataBridge\RedcapDataBridge $module */
if (!isset($module) || !($module instanceof \OxfordVaccineGroup\RedcapDataBridge\RedcapDataBridge)
    || !$module->canAccessProjectPage()) {
    http_response_code(403);
    echo '<div class="alert alert-danger" role="alert">You do not have access to Data Bridge in this project.</div>';
    return;
}

try {
    $bootstrap = json_encode($module->getPageState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    echo '<div class="alert alert-danger" role="alert">Data Bridge could not load. Ask a REDCap administrator to check the module log.</div>';
    return;
}
$css = $module->getUrl('assets/css/project.css');
$js = $module->getUrl('assets/js/project.js');
?>
<link rel="stylesheet" href="<?= $module->escape($css) ?>">

<main id="rdb-page" aria-labelledby="rdb-title">
    <header class="rdb-hero">
        <div>
            <p class="rdb-kicker">OVG Data Operations</p>
            <h1 id="rdb-title">Data Bridge</h1>
            <p>Configure and validate transfers to another REDCap project.</p>
        </div>
        <span id="rdb-connection-badge" class="rdb-badge">Not connected</span>
    </header>

    <nav class="rdb-tabs" aria-label="Data Bridge sections">
        <button type="button" data-tab="connection" aria-selected="true">Connection</button>
        <button type="button" data-tab="mappings" aria-selected="false">Field mappings</button>
    </nav>

    <div id="rdb-feedback" class="rdb-feedback" role="status" aria-live="polite" hidden></div>
    <div id="rdb-errors" class="rdb-feedback rdb-error" role="alert" tabindex="-1" hidden></div>
    <div id="rdb-loader" class="rdb-loader" role="status" aria-live="polite" aria-hidden="true" hidden>
        <div class="rdb-loader-card">
            <span class="rdb-spinner" aria-hidden="true"></span>
            <span id="rdb-loader-text">Processing…</span>
        </div>
    </div>
    <div id="rdb-confirm" class="rdb-confirm" role="dialog" aria-modal="true" aria-labelledby="rdb-confirm-title" aria-describedby="rdb-confirm-message" hidden>
        <div class="rdb-confirm-card">
            <h2 id="rdb-confirm-title">Confirm removal</h2>
            <p id="rdb-confirm-message"></p>
            <div class="rdb-actions rdb-confirm-actions">
                <button type="button" id="rdb-confirm-cancel" class="rdb-button">Cancel</button>
                <button type="button" id="rdb-confirm-remove" class="rdb-button rdb-danger">Remove</button>
            </div>
        </div>
    </div>

    <section id="rdb-tab-connection" class="rdb-panel rdb-tab-panel" aria-labelledby="rdb-connection-title">
        <div class="rdb-section-heading">
            <div>
                <h2 id="rdb-connection-title">Target project connection</h2>
                <p>The token remains on the REDCap server and is never returned to this page.</p>
            </div>
        </div>
        <form id="rdb-connection-form">
            <div class="rdb-grid-two">
                <label>Target REDCap API URL
                    <input name="api_url" type="url" required placeholder="https://redcap.example.org/api/">
                </label>
                <label>Target API token
                    <input name="api_key" type="password" autocomplete="new-password" placeholder="Leave blank to keep the saved token">
                </label>
            </div>
            <p class="rdb-help">The token needs metadata export, record export and record import permissions. Target record auto-numbering must be enabled.</p>
            <div class="rdb-actions">
                <button type="button" id="rdb-test-connection" class="rdb-button">Test connection</button>
                <button type="submit" class="rdb-button rdb-primary">Save connection</button>
            </div>
        </form>
        <div id="rdb-target-summary" class="rdb-summary" hidden></div>
    </section>

    <section id="rdb-tab-mappings" class="rdb-panel rdb-tab-panel" aria-labelledby="rdb-mappings-title" hidden>
        <div class="rdb-section-heading">
            <div>
                <h2 id="rdb-mappings-title">Transfer rules <span id="rdb-rule-count" class="rdb-count">0</span></h2>
                <p>Each rule runs immediately after its source survey is completed.</p>
            </div>
            <div class="rdb-actions">
                <button type="button" id="rdb-reload" class="rdb-button">Reload</button>
                <button type="button" id="rdb-add-rule" class="rdb-button rdb-primary">Add rule</button>
            </div>
        </div>
        <div id="rdb-no-target" class="rdb-empty" hidden>Save a working target connection before configuring mappings.</div>
        <div id="rdb-rules"></div>
    </section>

</main>

<script type="application/json" id="rdb-bootstrap"><?= $bootstrap ?></script>
<?php $module->initializeJavascriptModuleObject(); ?>
<script src="<?= $module->escape($js) ?>"></script>
<script>window.RedcapDataBridgePage.init(<?= $module->getJavascriptModuleObjectName() ?>);</script>
