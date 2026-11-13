<?php
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=uniform_store_mgmt_system;charset=utf8mb4',
    'root', '', 
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$q   = isset($_GET['q']) ? trim($_GET['q']) : '';

// 1. Fetch active banners safely (Storefront Promos & Announcements)
$active_banners = [];
try {
    $current_date = date('Y-m-d H:i:s');
    $banner_sql = "SELECT title, message, banner_type FROM storefront_banners 
                   WHERE is_active = 1 
                   AND (start_date IS NULL OR start_date <= :now1) 
                   AND (end_date IS NULL OR end_date >= :now2)
                   ORDER BY created_at DESC";
    $b_stmt = $pdo->prepare($banner_sql);
    $b_stmt->execute([':now1' => $current_date, ':now2' => $current_date]);
    $active_banners = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If the table doesn't exist yet, ignore the error to prevent crashing
    $active_banners = [];
}

// 2. Build the WHERE clause from whichever filters are active
$where  = ["p.status = 'active'"];
$params = [];

if ($cat) {
    $where[] = "p.category_id = :cat";
    $params[':cat'] = $cat;
}
if ($q !== '') {
    $where[] = "(p.product_name LIKE :q1 OR p.description LIKE :q2)";
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}

// 3. Fetch products
$sql = "SELECT p.product_id, p.product_name, p.image_url,
               MIN(v.price) AS min_price,
               COALESCE(SUM(v.stock_quantity), 0) AS total_stock
        FROM products p
        LEFT JOIN product_variants v ON v.product_id = p.product_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY p.product_id
        ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch variants
$variants = [];
$vstmt = $pdo->query("SELECT variant_id, product_id, size, price, stock_quantity
                      FROM product_variants WHERE stock_quantity > 0
                      ORDER BY product_id, variant_id");
foreach ($vstmt as $v) {
    $variants[$v['product_id']][] = $v;
}

// 5. Categories for the circles
$categories = $pdo->query(
    "SELECT category_id, category_name FROM categories ORDER BY category_id"
)->fetchAll(PDO::FETCH_ASSOC);

// Placeholder colors, used until you add category images
$circleColors = ['#E4D285', '#727F90', '#D9D9D9', '#84A592', '#EDC937'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HARIBONified Manila</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Lato', sans-serif;
        color: #17212B;
        background: #F5EAD8;
    }
    a { color: inherit; text-decoration: none; }

    .top-navbar {
        height: 30px;
        width: 100%;
        padding: 0 24px;
        background: #193E57;
        color: #F5EAD8;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        z-index: 2;
    }
    .help-link { display: flex; align-items: center; gap: 8px; }
    .help-icon { width: 16px; height: 16px; display: block; }

    .top-right { display: flex; align-items: center; gap: 22px; }
    .logout-link { font-weight: 500; }

    .lang-dropdown { position: relative; }
    .lang-toggle { all: unset; cursor: pointer; }
    .lang-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        min-width: 200px;
        margin: 8px 0 0;
        padding: 0;
        list-style: none;
        background: #fff;
        color: #17212B;
        border-radius: 4px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, .2);
    }
    .lang-menu.open { display: block; }
    .lang-menu li { padding: 8px 14px; cursor: pointer; }
    .lang-menu li:hover,
    .lang-menu li[aria-selected="true"] { background: #193E57; color: #F5EAD8; }

    .navbar {
        height: 88px;
        width: 100%;
        background: #0D2B45;
        padding: 0 24px;
        color: #F5EAD8;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 30px;
        position: relative;
        z-index: 1;
    }
    .logo-link { display: flex; align-items: center; gap: 12px; }
    .logo-link img { display: block; }

    .search-box {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 3px;
        width: 100%;
        max-width: 300px;
        height: 30px;
        padding: 0 12px;
        background: #B8AB52;
        border-radius: 999px;
    }
    .search-box img { width: 14px; height: 14px; display: block; }
    .search-box input {
        flex: 1;
        min-width: 0;
        border: none;
        outline: none;
        background: transparent;
        font-family: inherit;
        font-size: 13px;
        color: #17212B;
    }
    .search-box input::placeholder { color: #17212B; opacity: .8; }

    .nav-icons { display: flex; align-items: center; gap: 28px; }
    .nav-icons a { display: flex; align-items: center; }
    .nav-icons img { width: 28px; height: 28px; display: block; object-fit: contain; }
    .nav-icons svg { width: 32px; height: 32px; display: block; }

    /* ---------- MOVING MARQUEE PROMOS ---------- */
    .banner-container {
        width: 100%;
        display: flex;
        flex-direction: column;
        z-index: 10;
        position: relative;
        overflow: hidden; /* Crucial: hides text off-screen */
    }
    
    .promo-banner {
        padding: 14px 0; /* Horizontal padding removed to allow full-width scroll */
        font-size: 14.5px;
        font-weight: 500;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        animation: slideDown 0.4s ease-out forwards;
        white-space: nowrap; /* Forces text to stay on one line */
        position: relative;
    }
    
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .promo-banner.discount {
        background: linear-gradient(135deg, #F7D23E 0%, #E0B200 100%);
        color: #0D2B45;
        border-bottom: 2px solid #C49A00;
    }
    
    .promo-banner.announcement {
        background: linear-gradient(135deg, #193E57 0%, #0D2B45 100%);
        color: #F5EAD8;
        border-bottom: 2px solid #061522;
    }

    /* The moving text block */
    .marquee-content {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        will-change: transform;
        /* Scroll from right to left continuously over 20 seconds */
        animation: marqueeScroll 20s linear infinite;
    }
    
    /* Pause the moving text when the user hovers over it */
    .marquee-content:hover {
        animation-play-state: paused;
        cursor: default;
    }

    @keyframes marqueeScroll {
        from { transform: translateX(100vw); } /* Start all the way on the right edge */
        to { transform: translateX(-100%); }   /* Move until it's completely past the left edge */
    }

    .promo-banner strong {
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        background: rgba(255, 255, 255, 0.2);
        padding: 3px 10px;
        border-radius: 99px;
        font-size: 12px;
    }
    
    .banner-icon {
        width: 22px;
        height: 22px;
        flex-shrink: 0;
    }
    /* ------------------------------------------- */

    .header {
        width: 100%;
        height: 560px;
        background-image: url("posa_black.jpg");
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
    }

    /* ---------- Category circles ---------- */
    .category-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 40px;
        padding: 32px 60px 0;
    }
    .category-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        font-size: 12px;
        text-align: center;
    }
    .category-circle {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background-size: cover;
        background-position: center;
    }

    .category-filter {
        position: relative;
        margin: 40px 68px 0;
    }
    .category-filter summary {
        list-style: none;
        display: inline-block;
        padding: 2px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #193E57;
        background: #F7D23E;
        border: 1px solid #8A7A2A;
        border-radius: 999px;
        cursor: pointer;
    }
    .category-filter summary::-webkit-details-marker { display: none; }
    .category-menu {
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 5;
        min-width: 180px;
        margin: 6px 0 0;
        padding: 0;
        list-style: none;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, .2);
    }
    .category-menu a {
        display: block;
        padding: 15px 14px;
        font-size: 15px;
        color: #193E57;
    }
    .category-menu a:hover { background: #F7D23E; }

    /* ---------- Product grid ---------- */
    .product-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        padding: 56px 68px 60px;
    }
    .search-info {
        grid-column: 1 / -1;
        margin: 0;
        font-size: 14px;
        color: #193E57;
    }
    .search-info a { font-weight: 700; text-decoration: underline; margin-left: 8px; }
    .product-card {
        background: #fff;
        border-radius: 24px;
        overflow: hidden;
    }
    .product-img {
        aspect-ratio: 145 / 132;
        background-size: cover;
        background-position: center;
    }
    .product-info {
        padding: 12px 16px 16px;
        color: #193E57;
    }
    .product-name {
        margin: 0 0 6px;
        font-size: 13px;
        font-weight: 700;
    }
    .product-price {
        margin: 0 0 8px;
        font-size: 13px;
        font-weight: 700;
    }
    .size-select {
        display: block;
        width: 100%;
        margin: 0 0 8px;
        padding: 4px 8px;
        font-family: inherit;
        font-size: 12px;
        color: #193E57;
        border: 1px solid #ccc;
        border-radius: 6px;
    }
    .add-btn {
        display: block;
        margin: 0 auto;
        padding: 10px 16px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        color: #193E57;
        background: #EDC937;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }
    .add-btn:hover { background: #e0b92a; }
    .add-btn:disabled { background: #ccc; cursor: not-allowed; }
</style>
</head>
<body>
    <div class="top-navbar">
        <div class="help-link">
            <img src="help.png" class="help-icon" alt="">
            <span>Help Center</span>
        </div>

        <div class="top-right">
            <div class="lang-dropdown">
                <button class="lang-toggle" type="button" aria-haspopup="listbox" aria-expanded="false">
                    <span id="lang-label">English (United States)</span> &#9662;
                </button>
                <ul class="lang-menu" role="listbox">
                    <li role="option" data-lang="en" aria-selected="true">English (United States)</li>
                    <li role="option" data-lang="tl" aria-selected="false">Tagalog (Filipino)</li>
                </ul>
            </div>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </div>

    <div class="navbar">
        <a href="dashboard_customer.php" class="logo-link">
            <img src="logo.png" alt="Store logo" width="47" height="47">
            <img src="logotext.png" alt="HARIBONified Store" width="258" height="47">
        </a>

        <!-- Submits back to this same page and jumps to the product list -->
        <form class="search-box" action="#products" method="get">
            <img src="search.png" alt="">
            <input type="text" name="q" placeholder="Search"
                   value="<?= htmlspecialchars($q) ?>">
            <?php if ($cat): ?>
                <input type="hidden" name="cat" value="<?= $cat ?>">
            <?php endif; ?>
        </form>

        <div class="nav-icons">
            <a href="notifications.php" aria-label="Notifications">
                <img src="notification.png" alt="">
            </a>
            <a href="cart.php" aria-label="Cart">
                <svg viewBox="0 0 24 24" fill="none" stroke="#E0B200" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 3h3l2.5 12h11L21 6H6"/>
                    <circle cx="9" cy="20" r="1.4"/>
                    <circle cx="17" cy="20" r="1.4"/>
                </svg>
            </a>
            <a href="customer_profile.php" aria-label="Profile">
                <img src="user_ic.png" alt="">
            </a>
        </div>
    </div>

    <!-- Active Storefront Promos & Banners Display -->
    <?php if (!empty($active_banners)): ?>
        <div class="banner-container">
            <?php foreach ($active_banners as $banner): ?>
                <div class="promo-banner <?= htmlspecialchars($banner['banner_type']) ?>">
                    
                    <!-- MARQUEE MOVING CONTENT -->
                    <div class="marquee-content">
                        
                        <!-- Dynamic Icons based on Type -->
                        <?php if ($banner['banner_type'] === 'discount'): ?>
                            <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                                <line x1="7" y1="7" x2="7.01" y2="7"></line>
                            </svg>
                        <?php else: ?>
                            <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 5L6 9H2v6h4l5 4V5z"></path>
                                <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                            </svg>
                        <?php endif; ?>
                        
                        <span>
                            <strong><?= htmlspecialchars($banner['title']) ?></strong> 
                            <?= htmlspecialchars($banner['message']) ?>
                        </span>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="header">
        <!-- Notice text removed -->
    </div>

    <div class="category-row">
    <?php foreach ($categories as $i => $c): ?>
        <a href="?cat=<?= $c['category_id'] ?>#products" class="category-item">
            <div class="category-circle"
                 style="background-color:<?= $circleColors[$i % count($circleColors)] ?>"></div>
            <span><?= htmlspecialchars($c['category_name']) ?></span>
        </a>
    <?php endforeach; ?>
    </div>

    <div class="category-filter">
        <details>
            <summary>Categories &#9662;</summary>
            <ul class="category-menu">
                <li><a href="?#products">All products</a></li>
                <?php foreach ($categories as $c): ?>
                    <li><a href="?cat=<?= $c['category_id'] ?>#products"><?= htmlspecialchars($c['category_name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>

    <div class="product-grid" id="products">

    <?php if ($q !== ''): ?>
        <p class="search-info">
            <?= count($products) ?> result<?= count($products) === 1 ? '' : 's' ?>
            for "<strong><?= htmlspecialchars($q) ?></strong>"
            <a href="?<?= $cat ? 'cat=' . $cat : '' ?>#products">Clear search</a>
        </p>
    <?php endif; ?>

    <?php if (empty($products)): ?>
        <p class="search-info">No products found.</p>
    <?php endif; ?>

    <?php foreach ($products as $p): ?>
        <div class="product-card">
            <div class="product-img"
                 style="<?= $p['image_url']
                     ? "background-image:url('" . htmlspecialchars($p['image_url']) . "')"
                     : "background-color:#D9D9D9" ?>"></div>
            <div class="product-info">
                <h3 class="product-name"><?= htmlspecialchars($p['product_name']) ?></h3>
                <?php if (!empty($variants[$p['product_id']])): ?>
                <form action="add_to_cart.php" method="post">
                    <select name="variant_id" class="size-select" required>
                        <?php foreach ($variants[$p['product_id']] as $v): ?>
                            <option value="<?= $v['variant_id'] ?>">
                                <?= htmlspecialchars($v['size']) ?> – ₱<?= number_format($v['price'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="quantity" value="1">
                    <button class="add-btn" type="submit">Add to cart</button>
                </form>
                <?php else: ?>
                    <button class="add-btn" type="button" disabled>Out of stock</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

<script>
    const toggle = document.querySelector('.lang-toggle');
    const menu = document.querySelector('.lang-menu');
    const label = document.getElementById('lang-label');
    const items = menu.querySelectorAll('li');

    toggle.addEventListener('click', () => {
        const isOpen = menu.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen);
    });

    items.forEach(item => {
        item.addEventListener('click', () => {
            items.forEach(i => i.setAttribute('aria-selected', 'false'));
            item.setAttribute('aria-selected', 'true');
            label.textContent = item.textContent;
            menu.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.lang-dropdown')) {
            menu.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
</script>
</body>
</html>