<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

$stmt = $pdo->query(
    "SELECT pv.variant_id, pv.sku, pv.size, pv.color, pv.price, p.product_name
     FROM product_variants pv
     JOIN products p ON p.product_id = pv.product_id
     ORDER BY p.product_name, pv.size"
);
$variants = $stmt->fetchAll();

$pageTitle  = 'Barcode Labels';
$activePage = 'barcodes';
$extraHead  = <<<'HTML'
<style>
    .toolbar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
    .toolbar input[type=text] {
        flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;
    }
    .btn {
        padding: 8px 16px; border-radius: 6px; font-weight: bold; font-size: 13px; border: none; cursor: pointer;
    }
    .btn-primary { background: #e0b400; color: #193E57; }
    .btn-primary:hover { background: #957908; }
    .btn-secondary { background: #dbe4ec; color: #193E57; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
    th { background: #17364B; color: #fff; font-size: 13px; }
    .sub { color: #708090; font-size: 12px; }

    .labels-sheet { display: none; }
    .label {
        display: inline-block; width: 220px; padding: 12px; margin: 6px; border: 1px dashed #999;
        text-align: center; vertical-align: top;
    }
    .label .name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
    .label .variant { font-size: 11px; color: #555; margin-bottom: 6px; }
    .label .price { font-size: 12px; margin-top: 4px; }
    .label svg { max-width: 100%; }

    @media print {
        .no-print { display: none !important; }
        .labels-sheet { display: block !important; }
        .label { border: 1px solid #000; page-break-inside: avoid; }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
HTML;

require 'includes/admin_header.php';
?>

<div class="content">
    <div class="no-print">
        <h2>Print Barcode Labels</h2>
        <div class="toolbar">
            <input type="text" id="searchBox" placeholder="Search by product name or SKU...">
            <button type="button" class="btn btn-secondary" onclick="toggleAll(true)">Select All</button>
            <button type="button" class="btn btn-secondary" onclick="toggleAll(false)">Clear</button>
            <button type="button" class="btn btn-primary" onclick="generateAndPrint()">Generate &amp; Print Selected</button>
        </div>

        <table id="variantTable">
            <tr>
                <th></th>
                <th>Product</th>
                <th>Variant</th>
                <th>SKU</th>
                <th>Price</th>
            </tr>
            <?php foreach ($variants as $v): ?>
                <tr class="variant-row"
                    data-search="<?= strtolower(htmlspecialchars($v['product_name'] . ' ' . $v['sku'])) ?>">
                    <td><input type="checkbox" class="row-check" value="<?= (int)$v['variant_id'] ?>"></td>
                    <td><?= htmlspecialchars($v['product_name']) ?></td>
                    <td><?= htmlspecialchars($v['size']) ?><?= $v['color'] ? ' - ' . htmlspecialchars($v['color']) : '' ?></td>
                    <td><?= htmlspecialchars($v['sku']) ?></td>
                    <td>&#8369;<?= number_format($v['price'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="labels-sheet" id="labelsSheet"></div>
</div>

<script>
    var variantData = <?= json_encode($variants) ?>;

    function toggleAll(state) {
        document.querySelectorAll('.row-check').forEach(function (cb) {
            if (cb.closest('tr').style.display !== 'none') {
                cb.checked = state;
            }
        });
    }

    document.getElementById('searchBox').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.variant-row').forEach(function (row) {
            row.style.display = row.dataset.search.indexOf(q) !== -1 ? '' : 'none';
        });
    });

    function generateAndPrint() {
        var checked = Array.from(document.querySelectorAll('.row-check:checked')).map(cb => parseInt(cb.value));

        if (checked.length === 0) {
            alert('Select at least one product variant first.');
            return;
        }

        var sheet = document.getElementById('labelsSheet');
        sheet.innerHTML = '';

        checked.forEach(function (variantId) {
            var v = variantData.find(function (item) { return parseInt(item.variant_id) === variantId; });
            if (!v) return;

            var label = document.createElement('div');
            label.className = 'label';

            var variantText = v.size + (v.color ? ' - ' + v.color : '');

            label.innerHTML =
                '<div class="name">' + escapeHtml(v.product_name) + '</div>' +
                '<div class="variant">' + escapeHtml(variantText) + '</div>' +
                '<svg class="barcode-svg"></svg>' +
                '<div class="price">\u20B1' + parseFloat(v.price).toFixed(2) + '</div>';

            sheet.appendChild(label);

            JsBarcode(label.querySelector('.barcode-svg'), v.sku, {
                format: "CODE128",
                displayValue: true,
                fontSize: 12,
                height: 50,
                margin: 4
            });
        });

        setTimeout(function () { window.print(); }, 150);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
</script>

<?php require 'includes/admin_footer.php'; ?>