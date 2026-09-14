<?php
require_once __DIR__ . '/config.php';
require_login();

$page_title = 'Documents';
$active = 'documents';

$pdo = db();

$client_id = $_SESSION['client_id'];

// Retrieve selected print settings for dynamic calculation
$stmtSettings = $pdo->prepare("
    SELECT single_side_cost, double_side_cost 
    FROM print_settings 
    WHERE client_id = :client_id AND is_selected = 1 
    LIMIT 1
");
$stmtSettings->execute([':client_id' => $client_id]);
$selectedSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

// Fallback rates if no selected configuration exists in the database
$singleSideCost = $selectedSettings ? (float)$selectedSettings['single_side_cost'] : 2.00;
$doubleSideCost = $selectedSettings ? (float)$selectedSettings['double_side_cost'] : 1.50;

$phone = trim($_GET['phone'] ?? '');
$createdDate = trim($_GET['created_at'] ?? '');

$sql = "SELECT
            id,
            phone,
            media_url,
            page_count,
            cost,
            document_type,
            created_at
        FROM documents
        WHERE client_id = :client_id";

$params = [
    ':client_id' => $client_id
];

if ($phone !== '') {
    $sql .= " AND phone LIKE :phone";
    $params[':phone'] = '%' . $phone . '%';
}

if ($createdDate !== '') {
    $sql .= " AND DATE(created_at) = :created_at";
    $params[':created_at'] = $createdDate;
}

$sql .= " ORDER BY phone ASC, created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$documents = $stmt->fetchAll();

/*
 * Normalize document types and group documents by type.
 */
$documentTypeGroups = [
    'all' => [],
    'pdf' => [],
    'image' => [],
    'unknown' => []
];

foreach ($documents as &$doc) {

    $documentType = strtolower(
        trim($doc['document_type'] ?? '')
    );

    if ($documentType === 'pdf') {

        $doc['_tab_type'] = 'pdf';

    } elseif (
        in_array(
            $documentType,
            ['image', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            true
        )
    ) {

        $doc['_tab_type'] = 'image';

    } else {

        $doc['_tab_type'] = 'unknown';
    }

    $documentTypeGroups['all'][] = $doc;
    $documentTypeGroups[$doc['_tab_type']][] = $doc;
}

unset($doc);

/*
 * Group each document type by phone.
 */
$groupedDocumentsByType = [];

foreach ($documentTypeGroups as $type => $typeDocuments) {

    $groupedDocumentsByType[$type] = [];

    foreach ($typeDocuments as $doc) {
        $groupedDocumentsByType[$type][$doc['phone']][] = $doc;
    }
}

require __DIR__ . '/header.php';
?>

<div class="page-heading">
    <div>
        <h1>Documents</h1>

        <p>
            <?= count($documents) ?>
            document<?= count($documents) === 1 ? '' : 's' ?> found
        </p>
    </div>
</div>


<section class="content-card filter-card">

    <form method="get" class="filter-form">

        <div class="field">
            <label for="phone">Phone</label>

            <input
                id="phone"
                name="phone"
                type="text"
                value="<?= e($phone) ?>"
                placeholder="Search phone number"
            >
        </div>


        <div class="field">
            <label for="created_at">Created Date</label>

            <input
                id="created_at"
                name="created_at"
                type="date"
                value="<?= e($createdDate) ?>"
            >
        </div>


        <div class="filter-actions">

            <button
                class="btn btn-primary"
                type="submit"
            >
                Refresh
            </button>

            <a
                class="btn btn-light"
                href="documents.php"
            >
                Clear
            </a>

        </div>

    </form>

</section>


<section class="content-card table-card">

    <?php
    $tabDefinitions = [
        'all' => 'All',
        'pdf' => 'PDF',
        'image' => 'IMAGE',
        'unknown' => 'UNKNOWN'
    ];
    ?>

    <!-- Document Type Tabs -->
    <div class="document-tabs" role="tablist">

        <?php foreach ($tabDefinitions as $tabKey => $tabLabel): ?>

            <button
                type="button"
                class="document-tab <?= $tabKey === 'all' ? 'active' : '' ?>"
                data-tab="<?= e($tabKey) ?>"
                onclick="showDocumentTab('<?= e($tabKey) ?>')"
            >
                <?= e($tabLabel) ?>

                <span class="document-tab-count">
                    <?= count($documentTypeGroups[$tabKey]) ?>
                </span>
            </button>

        <?php endforeach; ?>

    </div>


    <!-- Document Type Tab Panels -->

    <?php foreach ($tabDefinitions as $tabKey => $tabLabel): ?>

        <?php
        $groupedDocuments = $groupedDocumentsByType[$tabKey];
        ?>

        <div
            class="document-tab-panel <?= $tabKey === 'all' ? 'active' : '' ?>"
            data-tab-panel="<?= e($tabKey) ?>"
        >

            <div class="table-wrap">

                <table class="documents-table">

                    <thead>

                        <tr>
                            <th style="width:50px;"></th>
                            <th>Customer</th>
                            <th>Documents</th>
                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!$groupedDocuments): ?>

                        <tr>
                            <td
                                colspan="3"
                                class="empty-state"
                            >
                                No <?= e($tabLabel) ?> documents found.
                            </td>
                        </tr>

                    <?php else: ?>


                        <?php foreach ($groupedDocuments as $phoneNumber => $phoneDocuments): ?>

                            <?php
                            /*
                             * Include tab name in group ID.
                             * This prevents the same phone number
                             * in different tabs from conflicting.
                             */
                            $groupId = 'phone_' . $tabKey . '_' . md5($phoneNumber);

                            $documentCount = count($phoneDocuments);

                            $summaryOriginalPages = 0;
                            $summaryPages = 0;
                            $summaryCost = 0;
                            ?>


                            <!-- Phone Group Row -->

                            <tr class="phone-group-row">

                                <td>

                                    <button
                                        type="button"
                                        class="drilldown-btn"
                                        onclick="toggleDocuments(
                                            '<?= e($groupId) ?>',
                                            this
                                        )"
                                        aria-label="Expand documents"
                                    >
                                        +
                                    </button>

                                </td>


                                <td class="phone-cell">

                                    <strong>
                                        <?= e($phoneNumber) ?>
                                    </strong>

                                </td>


                                <td>

                                    <?= $documentCount ?>

                                    document<?= $documentCount === 1 ? '' : 's' ?>

                                </td>

                            </tr>


                            <!-- Documents under this phone -->

                            <?php foreach ($phoneDocuments as $index => $doc): ?>

                                <?php
                                $originalPages = (int)$doc['page_count'];

                                $defaultCopies = 1;
                                $defaultSide = 'single';

                                $defaultPrintPages =
                                    $originalPages * $defaultCopies;

                                $defaultRate =
                                    ($defaultSide === 'single')
                                    ? $singleSideCost
                                    : $doubleSideCost;

                                $defaultActualCost =
                                    $defaultPrintPages * $defaultRate;

                                $summaryPages += $defaultPrintPages;
                                $summaryCost += $defaultActualCost;

                                $rowId = 'document_' . (int)$doc['id'];
                                ?>


                                <tr
                                    class="document-detail-row <?= e($groupId) ?>"
                                    style="display:none;"
                                    data-document-id="<?= (int)$doc['id'] ?>"
                                    data-original-pages="<?= $originalPages ?>"
                                >

                                    <td></td>


                                    <td class="document-number-cell">

                                        <strong>
                                            <?= $index + 1 ?>.
                                        </strong>

                                    </td>


                                    <td>

                                        <div class="document-details">


                                            <?php
                                            $documentType =
                                                strtolower(
                                                    trim(
                                                        $doc['document_type'] ?? ''
                                                    )
                                                );

                                            $isWord = in_array(
                                                $documentType,
                                                ['word', 'doc', 'docx'],
                                                true
                                            );

                                            $isImage = in_array(
                                                $documentType,
                                                [
                                                    'image',
                                                    'jpg',
                                                    'jpeg',
                                                    'png',
                                                    'gif',
                                                    'webp'
                                                ],
                                                true
                                            );


                                            if ($isWord) {

                                                $typeLabel = 'WORD';
                                                $viewLabel = 'View WORD';

                                            } elseif ($isImage) {

                                                $typeLabel = 'IMAGE';
                                                $viewLabel = 'View IMAGE';

                                            } elseif ($documentType === 'pdf') {

                                                $typeLabel = 'PDF';
                                                $viewLabel = 'View PDF';

                                            } else {

                                                $typeLabel = 'UNKNOWN';
                                                $viewLabel = 'View File';

                                            }
                                            ?>


                                            <!-- Document Type -->

                                            <div class="document-info">

                                                <span class="detail-label">
                                                    <?= $typeLabel ?>
                                                </span>

                                                <a
                                                    class="pdf-link"
                                                    href="<?= e($doc['media_url']) ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    <?= $viewLabel ?>
                                                </a>

                                            </div>


                                            <!-- Original Pages -->

                                            <div class="document-info">

                                                <span class="detail-label">
                                                    Original Pages
                                                </span>

                                                <strong>
                                                    <?= $originalPages ?>
                                                </strong>

                                            </div>


                                            <!-- Number Of Copies -->

                                            <div class="document-info">

                                                <label
                                                    class="detail-label"
                                                    for="copies_<?= (int)$doc['id'] ?>"
                                                >
                                                    Number Of Copies
                                                </label>

                                                <select
                                                    id="copies_<?= (int)$doc['id'] ?>"
                                                    class="print-copies"
                                                    data-document-id="<?= (int)$doc['id'] ?>"
                                                >

                                                    <?php for ($i = 1; $i <= 100; $i++): ?>

                                                        <option
                                                            value="<?= $i ?>"
                                                            <?= $i === 1 ? 'selected' : '' ?>
                                                        >
                                                            <?= $i ?>
                                                        </option>

                                                    <?php endfor; ?>

                                                </select>

                                            </div>


                                            <!-- Printing Side -->

                                            <div class="document-info">

                                                <label
                                                    class="detail-label"
                                                    for="side_<?= (int)$doc['id'] ?>"
                                                >
                                                    Printing
                                                </label>

                                                <select
                                                    id="side_<?= (int)$doc['id'] ?>"
                                                    class="print-side"
                                                    data-document-id="<?= (int)$doc['id'] ?>"
                                                >

                                                    <option
                                                        value="single"
                                                        selected
                                                    >
                                                        Single Side
                                                    </option>

                                                    <option
                                                        value="double"
                                                    >
                                                        Two Side
                                                    </option>

                                                </select>

                                            </div>


                                            <!-- Print Pages -->

                                            <div class="document-info">

                                                <span class="detail-label">
                                                    Print Pages
                                                </span>

                                                <strong
                                                    id="print-pages-<?= (int)$doc['id'] ?>"
                                                    class="print-pages-value"
                                                >
                                                    <?= $defaultPrintPages ?>
                                                </strong>

                                            </div>


                                            <!-- Actual Cost -->

                                            <div class="document-info">

                                                <span class="detail-label">
                                                    Actual Cost
                                                </span>

                                                <strong
                                                    id="actual-cost-<?= (int)$doc['id'] ?>"
                                                    class="actual-cost-value"
                                                >
                                                    ₹<?= number_format(
                                                        $defaultActualCost,
                                                        2
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <!-- Created At -->

                                            <div class="document-info">

                                                <span class="detail-label">
                                                    Created At
                                                </span>

                                                <?= e($doc['created_at']) ?>

                                            </div>


                                            <!-- Print -->

                                            <div class="document-info">

                                                <button
                                                    type="button"
                                                    class="btn btn-print"
                                                    onclick="printDocument(
                                                        <?= (int)$doc['id'] ?>
                                                    )"
                                                >
                                                    🖨 Print
                                                </button>

                                            </div>


                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>


                            <!-- Phone Summary -->

                            <tr
                                class="phone-summary-row <?= e($groupId) ?>"
                                style="display:none;"
                            >

                                <td></td>


                                <td>

                                    <strong>
                                        TOTAL
                                    </strong>

                                </td>


                                <td>

                                    <div class="phone-summary">

                                        <div class="summary-item summary-control">
                                            <label
                                                class="detail-label"
                                                for="all-copies-<?= e($groupId) ?>"
                                            >
                                                Number of Copies
                                            </label>

                                            <select
                                                id="all-copies-<?= e($groupId) ?>"
                                                class="apply-all-copies"
                                                onchange="applyCopiesToAll(
                                                    '<?= e($groupId) ?>',
                                                    this.value
                                                )"
                                            >
                                                <?php for ($i = 1; $i <= 100; $i++): ?>
                                                    <option value="<?= $i ?>">
                                                        <?= $i ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>


                                        <div class="summary-item summary-control">
                                            <label
                                                class="detail-label"
                                                for="all-side-<?= e($groupId) ?>"
                                            >
                                                Printing
                                            </label>

                                            <select
                                                id="all-side-<?= e($groupId) ?>"
                                                class="apply-all-side"
                                                onchange="applySideToAll(
                                                    '<?= e($groupId) ?>',
                                                    this.value
                                                )"
                                            >
                                                <option value="single">
                                                    Single Side
                                                </option>
                                                <option value="double">
                                                    Two Side
                                                </option>
                                            </select>
                                        </div>


                                        <div class="summary-item">
                                            <span>
                                                Total Original Pages
                                            </span>

                                            <strong
                                                class="phone-total-original-pages"
                                            >
                                                <?= $summaryOriginalPages ?>
                                            </strong>
                                        </div>


                                        <div class="summary-item">
                                            <span>
                                                Total Print Pages
                                            </span>

                                            <strong
                                                class="phone-total-pages"
                                            >
                                                <?= $summaryPages ?>
                                            </strong>
                                        </div>


                                        <div class="summary-item">
                                            <span>
                                                Total Cost
                                            </span>

                                            <strong
                                                class="phone-total-cost"
                                            >
                                                ₹<?= number_format(
                                                    $summaryCost,
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>


                                        <div class="summary-item print-all-wrapper">

                                            <button
                                                type="button"
                                                class="btn btn-print-all"
                                                onclick="printAllDocuments(
                                                    '<?= e($groupId) ?>',
                                                    <?= $documentCount ?>
                                                )"
                                            >
                                                🖨 Print All
                                            </button>

                                            <button
                                                type="button"
                                                class="btn btn-download-all"
                                                onclick="downloadAllDocuments(
                                                    '<?= e($groupId) ?>'
                                                )"
                                            >
                                                ⬇ Download All
                                            </button>

                                        </div>

                                    </div>


                                        <!-- <div class="summary-item">

                                            <span>
                                                Total Cost
                                            </span>

                                            <strong
                                                class="phone-total-cost"
                                            >
                                                ₹<?= number_format(
                                                    $summaryCost,
                                                    2
                                                ) ?>
                                            </strong>

                                        </div> -->


                                        <!-- <div class="summary-item print-all-wrapper">

                                            <button
                                                type="button"
                                                class="btn btn-print-all"
                                                onclick="printAllDocuments(
                                                    '<?= e($groupId) ?>',
                                                    <?= $documentCount ?>
                                                )"
                                            >
                                                🖨 Print All
                                            </button>

                                        </div> -->


                                    </div>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>

    <?php endforeach; ?>

</section>


<script>
// Dynamic global rates rendered from active client settings
const SINGLE_SIDE_COST = <?= json_encode($singleSideCost) ?>;
const DOUBLE_SIDE_COST = <?= json_encode($doubleSideCost) ?>;

function showDocumentTab(tabType) {
    document.querySelectorAll('.document-tab').forEach(function(tab) {
        const active = tab.dataset.tab === tabType;
        tab.classList.toggle('active', active);
    });

    document.querySelectorAll('.document-tab-panel').forEach(function(panel) {
        const active = panel.dataset.tabPanel === tabType;
        panel.classList.toggle('active', active);
    });
}

/*
 * Expand / collapse phone
 */
function toggleDocuments(groupId, button) {

    const rows = document.querySelectorAll('.' + groupId);

    if (!rows.length) {
        return;
    }

    const currentlyHidden = rows[0].style.display === 'none';

    rows.forEach(function(row) {
        row.style.display = currentlyHidden ? 'table-row' : 'none';
    });

    button.textContent = currentlyHidden ? '−' : '+';

    if (currentlyHidden) {
        button.classList.add('expanded');
    } else {
        button.classList.remove('expanded');
    }
}


/*
 * Calculate one document
 */
function updateDocumentCalculation(documentId) {

    const row = document.querySelector('[data-document-id="' + documentId + '"]');

    if (!row) {
        return;
    }

    const originalPages = parseInt(row.dataset.originalPages, 10) || 0;
    const copiesElement = row.querySelector('.print-copies');
    const sideElement = row.querySelector('.print-side');

    const copies = parseInt(copiesElement.value, 10) || 1;
    const side = sideElement.value;

    let rate;
    let pagesPerCopy;

    if (side === 'single') {
        rate = SINGLE_SIDE_COST;
        pagesPerCopy = originalPages;
    } else {
        rate = DOUBLE_SIDE_COST;
        pagesPerCopy = Math.ceil(originalPages / 2);
    }

    const printPages = pagesPerCopy * copies;
    const actualCost = printPages * rate;

    document.getElementById('print-pages-' + documentId).textContent = printPages;
    document.getElementById('actual-cost-' + documentId).textContent = '₹' + actualCost.toFixed(2);

    updatePhoneSummary(row.closest('tbody'), row.className);
}


/*
 * Recalculate phone total
 */
function updatePhoneSummary(tbody, rowClass) {

    if (!tbody) {
        return;
    }

    const phoneGroupClass = Array.from(rowClass.split(' ')).find(function(cls) {
        return cls.indexOf('phone_') === 0;
    });

    if (!phoneGroupClass) {
        return;
    }

    const rows = tbody.querySelectorAll('.' + phoneGroupClass);

    let totalOriginalPages = 0;
    let totalPages = 0;
    let totalCost = 0;

    rows.forEach(function(row) {

        if (!row.classList.contains('document-detail-row')) {
            return;
        }

        const pages = parseInt(row.dataset.originalPages, 10) || 0;
        const copies = parseInt(row.querySelector('.print-copies').value, 10) || 1;
        const side = row.querySelector('.print-side').value;
        const actualCostElement = row.querySelector('.actual-cost-value');

        let actualCost = 0;
        let printPages = 0;

        if (side === 'double') {
            printPages = Math.ceil(pages / 2) * copies;
        } else {
            printPages = pages * copies;
        }

        if (actualCostElement) {
            actualCost = parseFloat(
                actualCostElement.textContent
                    .replace('₹', '')
                    .replace(/,/g, '')
                    .trim()
            ) || 0;
        }

        totalOriginalPages += pages;
        totalPages += printPages;
        totalCost += actualCost;
    });

    const summaryRow = tbody.querySelector('.phone-summary-row.' + phoneGroupClass);

    if (!summaryRow) {
        return;
    }

    summaryRow.querySelector('.phone-total-original-pages').textContent = totalOriginalPages;
    summaryRow.querySelector('.phone-total-pages').textContent = totalPages;
    summaryRow.querySelector('.phone-total-cost').textContent = '₹' + totalCost.toFixed(2);
}


/*
 * Copies change
 */
document.querySelectorAll('.print-copies').forEach(function(select) {
    select.addEventListener('change', function() {
        updateDocumentCalculation(this.dataset.documentId);
    });
});


/*
 * Single Side / Two Side change
 */
document.querySelectorAll('.print-side').forEach(function(select) {
    select.addEventListener('change', function() {
        updateDocumentCalculation(this.dataset.documentId);
    });
});


/*
 * Apply Number Of Copies to all documents in one phone group
 */
function applyCopiesToAll(groupId, copies) {

    const rows = document.querySelectorAll('.document-detail-row.' + groupId);

    if (!rows.length) {
        return;
    }

    rows.forEach(function(row) {
        const select = row.querySelector('.print-copies');

        if (!select) {
            return;
        }

        select.value = copies;
        updateDocumentCalculation(row.dataset.documentId);
    });
}


/*
 * Apply Single Side / Two Side to all documents in one phone group
 */
function applySideToAll(groupId, side) {

    const rows = document.querySelectorAll('.document-detail-row.' + groupId);

    if (!rows.length) {
        return;
    }

    rows.forEach(function(row) {
        const select = row.querySelector('.print-side');

        if (!select) {
            return;
        }

        select.value = side;
        updateDocumentCalculation(row.dataset.documentId);
    });
}


/*
 * Download All / display all files belonging to one customer
 */

function downloadAllDocuments(groupId) {

    const rows = document.querySelectorAll(
        '.document-detail-row.' + groupId
    );

    if (!rows.length) {
        alert('No documents found for this customer.');
        return;
    }

    const documentIds = [];

    rows.forEach(function(row) {

        const documentId = row.dataset.documentId;

        if (documentId) {
            documentIds.push(documentId);
        }
    });

    if (!documentIds.length) {
        alert('No files found for this customer.');
        return;
    }

    /*
     * Send document IDs to PHP.
     *
     * PHP will:
     * 1. Verify client_id
     * 2. Download the files from media_url
     * 3. Create a ZIP
     * 4. Return the ZIP to the browser
     */

    const url =
        'download_all.php?ids=' +
        encodeURIComponent(documentIds.join(','));

    window.location.href = url;
}


/*
 * Print button
 */
function printDocument(documentId) {
    const row = document.querySelector('[data-document-id="' + documentId + '"]');
    if (!row) return;

    const printUrl = 'print.php?id=' + encodeURIComponent(documentId);
    window.open(printUrl, '_blank');
}

/*
 * Print all documents belonging to one customer
 */
function printAllDocuments(groupId, documentCount) {

    const rows = document.querySelectorAll('.document-detail-row.' + groupId);

    if (!rows.length) {
        alert('No documents found for this customer.');
        return;
    }

    const confirmed = confirm('Open all ' + rows.length + ' documents for printing?');

    if (!confirmed) {
        return;
    }

    rows.forEach(function(row) {
        const documentId = row.dataset.documentId;
        if (!documentId) return;

        const printUrl = 'print.php?id=' + encodeURIComponent(documentId);
        window.open(printUrl, '_blank');
    });
}
</script>

<style>
.drilldown-btn {
    width: 30px;
    height: 30px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--card);
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drilldown-btn:hover { background: var(--green-light); }
.drilldown-btn.expanded { background: var(--green); color: white; border-color: var(--green); }

.phone-group-row { background: var(--card); }
.phone-group-row td { vertical-align: middle; }

.drilldown-body { background: var(--offwhite); }
.document-detail-row { background: var(--offwhite); }

.document-detail-row td {
    border-top: 1px solid var(--border);
    padding-top: 14px;
    padding-bottom: 14px;
}

.document-number-cell { text-align: right; color: var(--muted); }

.document-details {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}

.document-info { display: flex; align-items: center; gap: 7px; }

.detail-label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.document-info select {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
    font-size: 13px;
    cursor: pointer;
}

.print-pages-value { color: var(--green); }
.actual-cost-value { color: var(--green); }

.phone-summary-row { background: var(--green-light); }
.phone-summary { display: flex; gap: 35px; align-items: center; }

.summary-item { display: flex; align-items: center; gap: 10px; }
.summary-item span { color: var(--muted); font-size: 13px; font-weight: 600; }
.summary-item strong { font-size: 16px; color: var(--green-dark); }

.summary-control {
    gap: 8px;
}

.phone-summary select {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
    font-size: 13px;
    cursor: pointer;
}

.print-all-wrapper {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-print-all {
    background: var(--dark-slate);
    color: white;
    border: 1px solid var(--dark-slate);
    padding: 9px 16px;
    font-size: 14px;
    font-weight: 700;
    border-radius: 7px;
    cursor: pointer;
    white-space: nowrap;
}

.btn-print-all:hover { background: var(--green); border-color: var(--green); }

.btn-download-all {
    background: var(--card);
    color: var(--green-dark);
    border: 1px solid var(--green);
    padding: 9px 16px;
    font-size: 14px;
    font-weight: 700;
    border-radius: 7px;
    cursor: pointer;
    white-space: nowrap;
}

.btn-download-all:hover {
    background: var(--green-light);
}

@media (max-width: 900px) {
    .document-details { align-items: flex-start; flex-direction: column; gap: 10px; }
    .phone-summary { flex-direction: column; align-items: flex-start; gap: 8px; }
    .print-all-wrapper { margin-left: 0; flex-wrap: wrap; }
}
</style>

<?php require __DIR__ . '/footer.php'; ?>