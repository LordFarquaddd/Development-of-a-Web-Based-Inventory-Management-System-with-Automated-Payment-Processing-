<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$product_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT p.*, c.category_name
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     WHERE p.product_id = ? AND p.status = 'active'"
);
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    die('Product not found.');
}

$stmt = $pdo->prepare(
    "SELECT * FROM product_variants WHERE product_id = ? ORDER BY size, color"
);
$stmt->execute([$product_id]);
$variants = $stmt->fetchAll();

// The price lives on the variants only; show the lowest one ("From ..." when sizes differ).
$prices = array_column($variants, 'price');
$minPrice = $prices ? min($prices) : null;
$maxPrice = $prices ? max($prices) : null;

$added = isset($_GET['added']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($product['product_name']) ?> - HARIBONified Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { 
        box-sizing: border-box; 
    }
    body { 
        font-family: 'Lato', sans-serif; 
        background: #f4f7fa; 
        margin: 0; 
        color: #193E57; 
    }
    .navbar {
        background: #17364B;
        padding: 14px 30px; 
        display: flex; 
        justify-content: space-between;
        align-items: center; 
        color: #f6f8ff;
        font-family: 'Montserrat', sans-serif;
        font-weight: bold;
    }
    .navbar a { 
        color: #e0b400; 
        text-decoration: none; 
        font-size: 14px; 
    }
    .content { 
        max-width: 900px; 
        margin: 30px auto; 
        padding: 0 20px; 
    }
    .product-view { 
        display: flex; 
        gap: 30px; 
        background: #fff; 
        border-radius: 8px; 
        padding: 24px;
        box-shadow: 0 2px 6px #00000014; 
        flex-wrap: wrap; 
    }
    .product-image { 
        width: 280px; 
        height: 280px; 
        background: #708090; 
        border-radius: 6px; 
        flex-shrink: 0;
        background-size: cover; 
        background-position: center; 
    }
    .product-details { 
        flex: 1; 
        min-width: 240px; 
    }
    .category-tag { 
        font-size: 11px; 
        text-transform: uppercase; 
        color: #708090; 
        margin-bottom: 6px; 
    }
    h1 { 
        font-size: 22px; 
        margin: 0 0 10px; 
    }
    .desc { 
        color: #444; 
        font-size: 14px; 
        margin-bottom: 16px; 
    }
    .price { 
        font-size: 22px; 
        font-weight: bold; 
        color: #17364B; 
        margin-bottom: 20px; 
    }
    label { 
        display: block; 
        font-weight: bold; 
        font-size: 13px; 
        margin-top: 12px; 
    }
    select, input[type=number] {
        width: 100%; 
        padding: 8px; 
        margin-top: 4px; 
        border: 1px solid #ccc; 
        border-radius: 4px; 
        font-size: 14px;
    }
    .btn-add {
        margin-top: 20px; 
        width: 100%; 
        padding: 12px; 
        background: #e0b400; 
        color: #193E57;
        border: none; 
        border-radius: 6px; 
        font-weight: bold;
        font-size: 15px; 
        cursor: pointer;
        font-family: 'Lato', sans-serif;
    }
    .btn-add:hover { 
        background: #957908; 
    }
    .success-msg {
        background: #eafaf1; 
        color: #1e7e34; 
        padding: 10px 14px; 
        border-radius: 6px;    
        margin-bottom: 16px;
        display: flex; 
        justify-content: space-between; 
        align-items: center;
    }
    .success-msg a { 
        color: #1e7e34; 
        font-weight: bold; 
    }
    .out-of-stock { 
        color: #b91c1c; 
        font-size: 13px; 
        margin-top: 6px; 
    }
    .back-link { 
        display: inline-block; 
        margin-bottom: 16px; 
        color: #17364B; 
        font-size: 14px; 
        text-decoration: none; }
</style>
</head>
<body>

<?php require 'includes/customer_header.php'; ?>

<div class="content">
    <a class="back-link" href="dashboard_customer.php">Continue shopping</a>

    <?php if ($added): ?>
        <div class="success-msg">
            <span>Added to cart!</span>
            <a href="cart.php">View cart</a>
        </div>
    <?php endif; ?>

    <div class="product-view">
        <div class="product-image"
             style="<?= $product['image_url'] ? 'background-image:url(' . htmlspecialchars($product['image_url']) . ')' : '' ?>">
        </div>
        <div class="product-details">
            <div class="category-tag"><?= htmlspecialchars($product['category_name']) ?></div>
            <h1><?= htmlspecialchars($product['product_name']) ?></h1>
            <div class="desc"><?= htmlspecialchars($product['description']) ?></div>
            <?php if ($minPrice !== null): ?>
                <div class="price"><?= $minPrice == $maxPrice ? '' : 'From ' ?>&#8369;<?= number_format($minPrice, 2) ?></div>
            <?php endif; ?>

            <?php if (empty($variants)): ?>
                <div class="out-of-stock">This product has no available sizes/variants yet.</div>
            <?php else: ?>
                <form method="post" action="add_to_cart.php">
                    <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">

                    <label>Size / Variant</label>
                    <select name="variant_id" required>
                        <?php foreach ($variants as $v): ?>
                            <option value="<?= (int)$v['variant_id'] ?>" <?= $v['stock_quantity'] <= 0 ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($v['size']) ?><?= $v['color'] ? ' - ' . htmlspecialchars($v['color']) : '' ?>
                                (&#8369;<?= number_format($v['price'], 2) ?>)
                                <?= $v['stock_quantity'] <= 0 ? ' - Out of stock' : ' - ' . $v['stock_quantity'] . ' left' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Quantity</label>
                    <input type="number" name="quantity" value="1" min="1" required>

                    <button type="submit" class="btn-add">Add to Cart</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>