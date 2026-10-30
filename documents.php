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
            created_at,
            is_read
        FROM documents
        WHERE client_id = :client_id
          AND is_deleted = 0";

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

//$sql .= " ORDER BY phone ASC, created_at DESC";
$sql .= " ORDER BY created_at DESC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$documents = $stmt->fetchAll();

/*
 * Pagination
 * 20 customer groups per page.
 */
$recordsPerPage = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

/*
 * Normalize document types and group documents by type.
 */
$documentTypeGroups = [
    'all' => [],
    'pdf' => [],
    'image' => [],
    'excel' => [],
    'word' => [],
    'powerpoint' => [],
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

} elseif (
    in_array(
        $documentType,
        ['excel', 'xls', 'xlsx'],
        true
    )
) {

    $doc['_tab_type'] = 'excel';

} elseif (
    in_array(
        $documentType,
        ['word', 'docx'],
        true
    )
) {

    $doc['_tab_type'] = 'word';

} elseif (
    in_array(
        $documentType,
        ['powerpoint'],
        true
    )
) {

    $doc['_tab_type'] = 'powerpoint';

}
 else {

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

            <button
                type="button"
                class="btn btn-danger"
                onclick="closeAllDocuments()"
            >
                Close All
            </button>

        </div>

    </form>

</section>


<section class="content-card table-card">

    <?php
    $tabDefinitions = [
        'all' => 'All',
        'pdf' => 'PDF',
        'image' => 'IMAGE',
        'excel' => 'EXCEL',
        'word' => 'WORD',
        'powerpoint' => 'PPT',
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

    /*
     * Pagination is based on customer/phone groups,
     * not individual documents.
     */
    $totalGroups = count($groupedDocuments);

    $totalPages = max(
        1,
        (int)ceil($totalGroups / $recordsPerPage)
    );

    $page = min($currentPage, $totalPages);

    $offset = ($page - 1) * $recordsPerPage;

    $groupedDocuments = array_slice(
        $groupedDocuments,
        $offset,
        $recordsPerPage,
        true
    );
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
$hasUnreadDocuments = false;

foreach ($phoneDocuments as $doc) {
    if ((int)($doc['is_read'] ?? 0) === 0) {
        $hasUnreadDocuments = true;
        break;
    }
}
?>

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

                            <tr
    class="phone-group-row <?= $hasUnreadDocuments ? 'unread-customer' : '' ?>"
    data-phone="<?= e($phoneNumber) ?>"
    data-customer-key="<?= e(md5((string)$phoneNumber)) ?>"
    data-group-id="<?= e($groupId) ?>"
>

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
                                    <label class="customer-select-label" title="Select or unselect all documents for this customer">
                                        <input
                                            type="checkbox"
                                            class="customer-select-checkbox"
                                            data-customer-key="<?= e(md5((string)$phoneNumber)) ?>"
                                            data-group-id="<?= e($groupId) ?>"
                                            onchange="toggleCustomerDocuments('<?= e($groupId) ?>', '<?= e(md5((string)$phoneNumber)) ?>', this.checked)"
                                            aria-label="Select all documents for <?= e($phoneNumber) ?>"
                                        >
                                    </label>

                                </td>


                                <td class="phone-cell">

    <?php if ($hasUnreadDocuments): ?>

        <span class="unread-indicator" title="Unread documents"></span>

    <?php endif; ?>

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
                                $summaryOriginalPages += $originalPages;

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

                                        <label class="document-checkbox-label" title="Select this document">
                                            <input type="checkbox" class="document-checkbox"
                                                data-document-id="<?= (int)$doc['id'] ?>" checked
                                                onchange="syncDocumentCheckbox(<?= (int)$doc['id'] ?>, this.checked)"
                                                aria-label="Select document <?= (int)$doc['id'] ?>">
                                        </label>

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

                                            $isExcel = in_array(
                                                $documentType,
                                                ['excel', 'xls', 'xlsx'],
                                                true
                                            );

                                            $isPowerpoint = in_array(
                                                $documentType,
                                                ['powerpoint', 'pptx'],
                                                true
                                            );

                                            $isWord = in_array(
                                                $documentType,
                                                ['word', 'docx'],
                                                true
                                            );


                                            if ($isWord) {

                                                $typeLabel = 'WORD';
                                                $viewLabel = 'View WORD';

                                            } elseif ($isImage) {

                                                $typeLabel = 'IMAGE';
                                                $viewLabel = 'View IMAGE';

                                            }

                                            elseif ($isExcel) {
                                                $typeLabel = 'EXCEL';
                                                $viewLabel = 'View EXCEL';
                                            }

                                            elseif ($isWord) {
                                                $typeLabel = 'WORD';
                                                $viewLabel = 'View WORD';
                                            }

                                            elseif ($isPowerpoint) {
                                                $typeLabel = 'PPT';
                                                $viewLabel = 'View PPT';
                                            }
                                            
                                            elseif ($documentType === 'pdf') {

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

                                            <button
                                                type="button"
                                                class="btn btn-download-all"
                                                onclick="downloadAllDocuments(
                                                    '<?= e($groupId) ?>'
                                                )"
                                            >
                                                ⬇ Download All
                                            </button>

                                        </div> -->

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

    <button
        type="button"
        class="btn btn-merge-download"
        onclick="mergeAndDownloadDocuments(
            '<?= e($groupId) ?>'
        )"
    >
        ⬇ Merge & Download
    </button>

    <button
        type="button"
        class="btn btn-close-all"
        onclick="closeDocumentGroup(
            '<?= e($groupId) ?>'
        )"
    >
        ✕ Close
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

<?php if ($totalPages > 1): ?>

    <div class="pagination">

        <?php if ($page > 1): ?>

            <a
                class="pagination-btn"
                href="?<?= http_build_query([
                    'phone' => $phone,
                    'created_at' => $createdDate,
                    'page' => $page - 1
                ]) ?>"
            >
                &laquo; Previous
            </a>

        <?php endif; ?>


        <?php for ($p = 1; $p <= $totalPages; $p++): ?>

            <a
                class="pagination-btn <?= $p === $page ? 'active' : '' ?>"
                href="?<?= http_build_query([
                    'phone' => $phone,
                    'created_at' => $createdDate,
                    'page' => $p
                ]) ?>"
            >
                <?= $p ?>
            </a>

        <?php endfor; ?>


        <?php if ($page < $totalPages): ?>

            <a
                class="pagination-btn"
                href="?<?= http_build_query([
                    'phone' => $phone,
                    'created_at' => $createdDate,
                    'page' => $page + 1
                ]) ?>"
            >
                Next &raquo;
            </a>

        <?php endif; ?>

    </div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</section>


<script>
// Dynamic global rates rendered from active client settings
const SINGLE_SIDE_COST = <?= json_encode($singleSideCost) ?>;
const DOUBLE_SIDE_COST = <?= json_encode($doubleSideCost) ?>;

function syncDocumentCheckbox(documentId, checked) {
    document.querySelectorAll('.document-checkbox[data-document-id="' + documentId + '"]').forEach(function(box) {
        box.checked = checked;
    });
    updateCustomerCheckboxes();
}

/*
 * A customer checkbox selects/unselects every document for that phone number,
 * including its appearances in other document-type tabs.
 */
function toggleCustomerDocuments(groupId, customerKey, checked) {
    const customerRows = document.querySelectorAll(
        '.phone-group-row[data-customer-key="' + customerKey + '"]'
    );

    customerRows.forEach(function(customerRow) {
        const relatedGroupId = customerRow.dataset.groupId;
        if (!relatedGroupId) return;

        document.querySelectorAll('.document-detail-row.' + relatedGroupId).forEach(function(row) {
            const id = row.dataset.documentId;
            if (id) {
                document.querySelectorAll('.document-checkbox[data-document-id="' + id + '"]').forEach(function(box) {
                    box.checked = checked;
                });
            }
        });
    });

    updateCustomerCheckboxes();
}

/* Keep each customer checkbox checked, unchecked, or indeterminate as appropriate. */
function updateCustomerCheckboxes() {
    document.querySelectorAll('.customer-select-checkbox').forEach(function(customerBox) {
        const customerKey = customerBox.dataset.customerKey;
        const customerRows = document.querySelectorAll(
            '.phone-group-row[data-customer-key="' + customerKey + '"]'
        );
        const ids = new Set();

        customerRows.forEach(function(customerRow) {
            const relatedGroupId = customerRow.dataset.groupId;
            if (!relatedGroupId) return;
            document.querySelectorAll('.document-detail-row.' + relatedGroupId).forEach(function(row) {
                if (row.dataset.documentId) ids.add(row.dataset.documentId);
            });
        });

        let checkedCount = 0;
        ids.forEach(function(id) {
            const box = document.querySelector('.document-checkbox[data-document-id="' + id + '"]');
            if (box && box.checked) checkedCount++;
        });

        customerBox.checked = ids.size > 0 && checkedCount === ids.size;
        customerBox.indeterminate = checkedCount > 0 && checkedCount < ids.size;
    });
}

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
        row.style.display = currentlyHidden
            ? 'table-row'
            : 'none';
    });

    button.textContent = currentlyHidden ? '−' : '+';

    const customerRow = button.closest('.phone-group-row');

    if (currentlyHidden) {

        button.classList.add('expanded');
        if (customerRow) customerRow.classList.add('customer-expanded');

        /*
         * The customer was opened.
         * Mark all documents in this customer group as read.
         */
        markCustomerDocumentsRead(groupId);

    } else {

        button.classList.remove('expanded');
        if (customerRow) customerRow.classList.remove('customer-expanded');
    }
}

function markCustomerDocumentsRead(groupId) {

    const rows = document.querySelectorAll(
        '.document-detail-row.' + groupId
    );

    if (!rows.length) {
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
        return;
    }

    const url =
        'mark_documents_read.php?ids=' +
        encodeURIComponent(documentIds.join(','));

    fetch(url, {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {

        if (!data.success) {
            console.error(
                data.message || 'Unable to mark documents as read.'
            );
            return;
        }

        /*
         * Remove unread styling immediately.
         */
        const groupRows = document.querySelectorAll(
            '.' + groupId
        );

        groupRows.forEach(function(row) {

            if (row.classList.contains('phone-group-row')) {

                row.classList.remove('unread-customer');

                const indicator =
                    row.querySelector('.unread-indicator');

                if (indicator) {
                    indicator.remove();
                }
            }
        });

    })
    .catch(function(error) {

        console.error(
            'Error marking documents as read:',
            error
        );
    });
}


/*
 * Recalculate phone total
 */
function updatePhoneSummary(tbody, rowClass) {

    if (!tbody) {
        return;
    }

    const phoneGroupClass =
        Array.from(rowClass.split(' ')).find(function(cls) {
            return cls.indexOf('phone_') === 0;
        });

    if (!phoneGroupClass) {
        return;
    }

    const rows =
        tbody.querySelectorAll(
            '.document-detail-row.' + phoneGroupClass
        );

    let totalOriginalPages = 0;
    let totalPages = 0;
    let totalCost = 0;

    rows.forEach(function(row) {

        if (!row.classList.contains('document-detail-row')) {
            return;
        }

        const originalPages =
            parseInt(row.dataset.originalPages, 10) || 0;

        const copiesElement =
            row.querySelector('.print-copies');

        const sideElement =
            row.querySelector('.print-side');

        if (!copiesElement || !sideElement) {
            return;
        }

        const copies =
            parseInt(copiesElement.value, 10) || 1;

        const side =
            sideElement.value;

        let printPages;
        let rate;

        if (side === 'double') {

            printPages =
                Math.ceil(originalPages / 2) * copies;

            rate = DOUBLE_SIDE_COST;

        } else {

            printPages =
                originalPages * copies;

            rate = SINGLE_SIDE_COST;
        }

        const actualCost =
            printPages * rate;

        totalOriginalPages += originalPages;
        totalPages += printPages;
        totalCost += actualCost;

        // Keep individual row values synchronized.
        const documentId =
            row.dataset.documentId;

        const printPagesElement =
            document.getElementById(
                'print-pages-' + documentId
            );

        const actualCostElement =
            document.getElementById(
                'actual-cost-' + documentId
            );

        if (printPagesElement) {
            printPagesElement.textContent =
                printPages;
        }

        if (actualCostElement) {
            actualCostElement.textContent =
                '₹' + actualCost.toFixed(2);
        }
    });

    const summaryRow =
        tbody.querySelector(
            '.phone-summary-row.' + phoneGroupClass
        );

    if (!summaryRow) {
        return;
    }

    const totalOriginalElement =
        summaryRow.querySelector(
            '.phone-total-original-pages'
        );

    const totalPagesElement =
        summaryRow.querySelector(
            '.phone-total-pages'
        );

    const totalCostElement =
        summaryRow.querySelector(
            '.phone-total-cost'
        );

    if (totalOriginalElement) {
        totalOriginalElement.textContent =
            totalOriginalPages;
    }

    if (totalPagesElement) {
        totalPagesElement.textContent =
            totalPages;
    }

    if (totalCostElement) {
        totalCostElement.textContent =
            '₹' + totalCost.toFixed(2);
    }
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

function getSelectedDocumentIds(groupId) {
    const rows = groupId
        ? document.querySelectorAll('.document-detail-row.' + groupId)
        : document.querySelectorAll('.document-detail-row');
    const ids = [];
    const seen = new Set();
    rows.forEach(function(row) {
        const checkbox = row.querySelector('.document-checkbox');
        const id = row.dataset.documentId;
        if (checkbox && checkbox.checked && id && !seen.has(id)) {
            ids.push(id);
            seen.add(id);
        }
    });
    return ids;
}

function downloadAllDocuments(groupId) {
    const ids = getSelectedDocumentIds(groupId);
    if (!ids.length) { alert('Please select at least one document to download.'); return; }
    window.location.href = 'download_all.php?ids=' + encodeURIComponent(ids.join(','));
}

/*
 * Merge all documents belonging to one customer
 * and download the generated PDF.
 */
function mergeAndDownloadDocuments(groupId) {
    const ids = getSelectedDocumentIds(groupId);
    if (!ids.length) { alert('Please select at least one document to merge.'); return; }
    if (!confirm('Merge and download the ' + ids.length + ' selected document(s)?')) return;
    window.location.href = 'merge_and_download.php?ids=' + encodeURIComponent(ids.join(','));
}

function updateDocumentCalculation(documentId) {

    const row = document.querySelector(
        '[data-document-id="' + documentId + '"]'
    );

    if (!row) {
        return;
    }

    const originalPages =
        parseInt(row.dataset.originalPages, 10) || 0;

    const copiesElement =
        row.querySelector('.print-copies');

    const sideElement =
        row.querySelector('.print-side');

    if (!copiesElement || !sideElement) {
        return;
    }

    const copies =
        parseInt(copiesElement.value, 10) || 1;

    const side =
        sideElement.value;

    let printPages;
    let rate;

    if (side === 'double') {

        // Two Side:
        // 1 physical sheet = 2 original pages
        printPages =
            Math.ceil(originalPages / 2) * copies;

        rate = DOUBLE_SIDE_COST;

    } else {

        // Single Side:
        // 1 physical sheet = 1 original page
        printPages =
            originalPages * copies;

        rate = SINGLE_SIDE_COST;
    }

    const actualCost =
        printPages * rate;

    const printPagesElement =
        document.getElementById(
            'print-pages-' + documentId
        );

    const actualCostElement =
        document.getElementById(
            'actual-cost-' + documentId
        );

    if (printPagesElement) {
        printPagesElement.textContent =
            printPages;
    }

    if (actualCostElement) {
        actualCostElement.textContent =
            '₹' + actualCost.toFixed(2);
    }

    /*
     * Recalculate the complete customer total
     * using the current settings of every document.
     */
    updatePhoneSummary(
        row.closest('tbody'),
        row.className
    );
}

function closeDocumentGroup(groupId) {

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
        alert('No documents found for this customer.');
        return;
    }


    const confirmed = confirm(
        'Are you sure you want to close all ' +
        documentIds.length +
        ' documents for this customer?'
    );

    if (!confirmed) {
        return;
    }


    const url =
        'close_documents.php?ids=' +
        encodeURIComponent(documentIds.join(','));


    fetch(url, {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {

        if (!data.success) {
            throw new Error(
                data.message || 'Unable to close documents.'
            );
        }

        /*
         * Remove the whole phone group from the page.
         */
        const allRows = document.querySelectorAll(
            '.' + groupId
        );

        allRows.forEach(function(row) {
            row.remove();
        });

        alert(
            data.updated +
            ' document(s) closed successfully.'
        );

    })
    .catch(function(error) {

        console.error(error);

        alert(
            error.message ||
            'Unable to close documents.'
        );
    });
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
    const ids = getSelectedDocumentIds(groupId);
    if (!ids.length) { alert('Please select at least one document to print.'); return; }
    if (!confirm('Open ' + ids.length + ' selected document(s) for printing?')) return;
    ids.forEach(function(id) { window.open('print.php?id=' + encodeURIComponent(id), '_blank'); });
}

function closeAllDocuments() {

    const confirmed = confirm(
        'Are you sure you want to close ALL documents?'
    );

    if (!confirmed) {
        return;
    }

    fetch('close_all_documents.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('Request failed.');
        }

        return response.json();
    })
    .then(function(data) {

        if (!data.success) {
            alert('Unable to close documents.');
            return;
        }

        /*
         * Reload the documents page so all closed
         * documents disappear from the list.
         */
        window.location.href = 'documents.php';

    })
    .catch(function(error) {

        console.error(
            'Close All error:',
            error
        );

        alert(
            'Unable to close documents. Please try again.'
        );
    });
}

document.addEventListener('DOMContentLoaded', updateCustomerCheckboxes);

</script>

<style>
.customer-select-label { display: none; align-items: center; margin-left: 8px; vertical-align: middle; }
.phone-group-row.customer-expanded .customer-select-label { display: inline-flex; }
.customer-select-checkbox { width: 22px; height: 22px; accent-color: var(--green); cursor: pointer; }
.document-checkbox-label { display: inline-flex; align-items: center; margin-right: 7px; vertical-align: middle; }
.document-checkbox { width: 16px; height: 16px; accent-color: var(--green); cursor: pointer; }

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