<?php
session_start();

/* =========================================
   MIXECONIK STORE
   PHP + JSON + MAKE.COM WEBHOOK ONLY
========================================= */

/* MAKE.COM CONFIGURATION */

define(
    'MAKE_WEBHOOK_URL',
    'https://hook.eu1.make.com/ozq1ho92vz5lp86rcpvhb5thxueklww2'
);


/* DIRECTORIES */

$base = __DIR__;
$dataDir = $base . '/data';
$uploadDir = $base . '/uploads';

foreach ([$dataDir, $uploadDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

$productsFile = $dataDir . '/products.json';
$ordersFile = $dataDir . '/orders.json';

foreach ([$productsFile, $ordersFile] as $f) {
    if (!file_exists($f)) {
        file_put_contents($f, '[]', LOCK_EX);
    }
}


/* =========================================
   HELPER FUNCTIONS
========================================= */

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function readJSON($file) {
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveJSON($file, $data) {

    $fp = fopen($file, 'c+');

    if (!$fp) return false;

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    rewind($fp);

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    $ok = $json !== false && fwrite($fp, $json) !== false;

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok;
}


/* =========================================
   SEND ORDER TO MAKE.COM ONLY
========================================= */

function sendOrderToMake($order) {

    if (!function_exists('curl_init')) {
        error_log('Make.com notification failed: PHP cURL is unavailable.');
        return false;
    }

    $customer = $order['customer'] ?? [];

    /*
     * Structured payload for Make.com.
     * Customer details are available both as
     * individual fields and inside customer.
     */

    $payloadData = [

        'event' => 'new_order',

        'source' => 'Mixeconik Store',

        'order_id' => $order['id'] ?? '',

        'product_id' => $order['product_id'] ?? '',

        'product_name' => $order['product_name'] ?? '',

        'product_price' => $order['price'] ?? 0,

        'quantity' => $order['quantity'] ?? 0,

        'total_amount' => $order['total'] ?? 0,

        'customer_name' => $customer['name'] ?? '',

        'customer_phone' => $customer['phone'] ?? '',

        'house_name' => $customer['house'] ?? '',

        'landmark' => $customer['landmark'] ?? '',

        'city' => $customer['city'] ?? '',

        'state' => $customer['state'] ?? '',

        'pincode' => $customer['pincode'] ?? '',

        'customer' => $customer,

        'payment_method' => $order['payment'] ?? 'Cash on Delivery',

        'payment_status' => $order['payment_status'] ?? 'Pay on Delivery',

        'order_status' => $order['delivery_status'] ?? 'Order Placed',

        'order_date' => $order['created_at'] ?? '',

        'order_details' => $order
    ];

    $payload = json_encode(
        $payloadData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {
        error_log('Make.com notification failed: JSON encoding error.');
        return false;
    }

    $ch = curl_init(MAKE_WEBHOOK_URL);

    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $payload,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 5,

        CURLOPT_TIMEOUT => 15,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $error = curl_error($ch);

    curl_close($ch);

    if (
        $response === false ||
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        error_log(
            'Make.com notification failed. HTTP: ' .
            $httpCode . ' Error: ' . $error
        );

        return false;
    }

    return true;
}


/* =========================================
   CSRF TOKEN
========================================= */

if (empty($_SESSION['store_csrf'])) {
    $_SESSION['store_csrf'] = bin2hex(random_bytes(32));
}

$notice = '';

$products = readJSON($productsFile);


/* =========================================
   SAVE CUSTOMER ADDRESS
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_address'])
) {

    if (!hash_equals(
        $_SESSION['store_csrf'],
        $_POST['csrf'] ?? ''
    )) {

        $notice = 'Invalid request. Please refresh the page.';

    } else {

        $customer = [

            'name' => trim($_POST['name'] ?? ''),

            'phone' => trim($_POST['phone'] ?? ''),

            'state' => trim($_POST['state'] ?? ''),

            'city' => trim($_POST['city'] ?? ''),

            'house' => trim($_POST['house'] ?? ''),

            'pincode' => trim($_POST['pincode'] ?? ''),

            'landmark' => trim($_POST['landmark'] ?? '')
        ];

        if (in_array('', $customer, true)) {

            $notice = 'Please complete all address fields.';

        } elseif (!preg_match(
            '/^[0-9+\-\s]{7,20}$/',
            $customer['phone']
        )) {

            $notice = 'Please enter a valid phone number.';

        } elseif (!preg_match(
            '/^[0-9]{6}$/',
            $customer['pincode']
        )) {

            $notice = 'Please enter a valid 6-digit PIN code.';

        } else {

            $_SESSION['store_customer'] = $customer;

            $notice = 'Address saved successfully.';
        }
    }
}


/* =========================================
   PREPARE ORDER CONFIRMATION
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['preview_order'])
) {

    if (!hash_equals(
        $_SESSION['store_csrf'],
        $_POST['csrf'] ?? ''
    )) {

        $notice = 'Invalid request. Please refresh the page.';

    } elseif (empty($_SESSION['store_customer'])) {

        $notice = 'Please add your delivery address first.';

    } else {

        $products = readJSON($productsFile);

        $product = null;

        foreach ($products as $p) {

            if (
                (string)($p['id'] ?? '') ===
                (string)($_POST['product_id'] ?? '')
            ) {
                $product = $p;
                break;
            }
        }

        $quantity = filter_var(
            $_POST['quantity'] ?? 1,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 20
                ]
            ]
        );

        if (
            !$product ||
            $quantity === false ||
            !is_numeric($product['price'] ?? null) ||
            (float)$product['price'] < 0
        ) {

            $notice = 'Invalid product or quantity.';

        } else {

            $_SESSION['pending_order'] = [

                'product_id' => $product['id'],

                'product_name' => $product['name'],

                'price' => (float)$product['price'],

                'photos' => $product['photos'] ?? [],

                'quantity' => $quantity,

                'total' => (float)$product['price'] * $quantity,

                'customer' => $_SESSION['store_customer']
            ];

            header('Location: index.php?confirm=1');
            exit;
        }
    }
}


/* =========================================
   CONFIRM ORDER + SAVE JSON + MAKE.COM
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_order'])
) {

    if (!hash_equals(
        $_SESSION['store_csrf'],
        $_POST['csrf'] ?? ''
    )) {

        $notice = 'Invalid request. Please refresh the page.';

    } elseif (empty($_SESSION['pending_order'])) {

        $notice = 'Your order session expired. Please try again.';

    } else {

        $pending = $_SESSION['pending_order'];

        $products = readJSON($productsFile);

        $currentProduct = null;

        foreach ($products as $p) {

            if (
                (string)($p['id'] ?? '') ===
                (string)$pending['product_id']
            ) {
                $currentProduct = $p;
                break;
            }
        }

        if (!$currentProduct) {

            unset($_SESSION['pending_order']);

            $notice = 'This product is no longer available.';

        } elseif (
            (float)$currentProduct['price'] !==
            (float)$pending['price']
        ) {

            unset($_SESSION['pending_order']);

            $notice = 'The product price has changed. Please order again.';

        } else {

            $orders = readJSON($ordersFile);

            $orderId = strtoupper(bin2hex(random_bytes(5)));

            $order = [

                'id' => $orderId,

                'product_id' => $pending['product_id'],

                'product_name' => $pending['product_name'],

                'price' => $pending['price'],

                'quantity' => $pending['quantity'],

                'total' => $pending['total'],

                'customer' => $pending['customer'],

                'payment' => 'Cash on Delivery',

                'payment_status' => 'Pay on Delivery',

                'delivery_status' => 'Order Placed',

                'created_at' => date('Y-m-d H:i:s')
            ];

            $orders[] = $order;


            /* FIRST SAVE ORDER TO JSON */

            if (saveJSON($ordersFile, $orders)) {

                /*
                 * AFTER SAVING THE ORDER,
                 * SEND IT EXCLUSIVELY TO MAKE.COM.
                 */

                $makeNotification = sendOrderToMake($order);


                /* SUCCESS SESSION */

                $_SESSION['completed_order'] = [

                    'id' => $orderId,

                    'name' => $pending['product_name'],

                    'total' => $pending['total'],

                    'make_notification_sent' => $makeNotification
                ];

                unset($_SESSION['pending_order']);

                header(
                    'Location: index.php?success=' .
                    urlencode($orderId)
                );

                exit;

            } else {

                $notice = 'Could not save your order. Please try again.';
            }
        }
    }
}


/* =========================================
   PAGE STATE
========================================= */

$products = readJSON($productsFile);

$isConfirmPage = isset($_GET['confirm']) &&
    !empty($_SESSION['pending_order']);

$isSuccessPage = isset($_GET['success']) &&
    !empty($_SESSION['completed_order']) &&
    hash_equals(
        (string)$_SESSION['completed_order']['id'],
        (string)$_GET['success']
    );

$pending = $_SESSION['pending_order'] ?? [];

$completed = $_SESSION['completed_order'] ?? [];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Mixeconik Store</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #080808;
    color: white;
    font-family: Arial, sans-serif;
}

header {
    text-align: center;
    padding: 22px 12px;
    border-bottom: 1px solid #333;
    background: #080808;
}

header h1 {
    color: #168bff;
    margin: 0;
    font-size: 27px;
}

.container {
    max-width: 1100px;
    margin: auto;
    padding: 18px;
}

.box {
    background: #151515;
    border: 1px solid #333;
    padding: 16px;
    border-radius: 10px;
    margin-bottom: 20px;
}

input {
    width: 100%;
    padding: 12px;
    margin: 7px 0;
    background: #222;
    color: white;
    border: 1px solid #444;
    border-radius: 6px;
}

.btn {
    display: inline-block;
    background: #168bff;
    color: white;
    border: 0;
    padding: 12px 16px;
    border-radius: 7px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
}

.btn:hover {
    background: #006bd6;
}

.secondary {
    background: #292929;
    color: white;
    border: 1px solid #444;
}

.search {
    width: 100%;
    margin: 10px 0 20px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.card {
    background: #151515;
    border: 1px solid #333;
    border-radius: 9px;
    overflow: hidden;
}

.card img {
    width: 100%;
    height: 165px;
    object-fit: cover;
    display: block;
}

.cardbody {
    padding: 12px;
}

.price {
    color: #168bff;
    font-weight: bold;
}

.notice {
    background: #292929;
    border-left: 4px solid #168bff;
    padding: 12px;
    margin-bottom: 15px;
}

.intro {
    position: fixed;
    inset: 0;
    background: #000;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.intro h1 {
    font-size: 38px;
    margin: 0;
}

.blue {
    color: #168bff;
}

/* PRODUCT GALLERY */

.gallery-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.94);
    z-index: 200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 12px;
    overflow-y: auto;
}

.gallery-box {
    width: 100%;
    max-width: 550px;
    max-height: 95vh;
    overflow-y: auto;
    background: #151515;
    border: 1px solid #168bff;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    position: relative;
}

.close-gallery {
    position: absolute;
    right: 12px;
    top: 10px;
    width: 35px;
    height: 35px;
    border: none;
    border-radius: 50%;
    background: #168bff;
    color: white;
    font-size: 22px;
    cursor: pointer;
}

.gallery-image-area {
    position: relative;
    width: 100%;
    margin-top: 30px;
    background: #050505;
    border-radius: 8px;
    overflow: hidden;
}

.gallery-main-image {
    width: 100%;
    height: 320px;
    object-fit: contain;
    display: block;
}

.gallery-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(22,139,255,.9);
    color: white;
    border: none;
    width: 40px;
    height: 48px;
    font-size: 25px;
    cursor: pointer;
    border-radius: 6px;
    z-index: 2;
}

.gallery-prev {
    left: 8px;
}

.gallery-next {
    right: 8px;
}

.gallery-count {
    color: #168bff;
    margin: 10px 0;
    font-size: 14px;
}

.thumbnails {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding: 10px 0;
}

.thumbnails img {
    width: 65px;
    height: 65px;
    object-fit: cover;
    border: 2px solid #444;
    border-radius: 6px;
    cursor: pointer;
    flex-shrink: 0;
}

.thumbnails img.active {
    border-color: #168bff;
}

.gallery-description {
    color: #ccc;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.modal-order {
    margin-top: 12px;
    text-align: left;
}

.modal-order label {
    display: block;
    margin-top: 8px;
}

.modal-order .btn {
    width: 100%;
    margin-top: 8px;
}

/* ORDER CONFIRMATION */

.modal {
    position: fixed;
    inset: 0;
    background: #000e;
    z-index: 300;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    overflow: auto;
}

.modalbox {
    width: 100%;
    max-width: 460px;
    max-height: 90vh;
    overflow: auto;
    background: #171717;
    border: 1px solid #168bff;
    border-radius: 12px;
    padding: 22px;
    text-align: center;
}

.modalbox h2 {
    color: #168bff;
}

.modalbox .btn {
    display: block;
    width: 100%;
    margin-top: 12px;
}

.product-photo {
    width: 100%;
    max-height: 250px;
    object-fit: contain;
    background: #090909;
    border-radius: 8px;
}

.address-text {
    text-align: left;
    line-height: 1.8;
    color: #ddd;
    overflow-wrap: anywhere;
}

footer {
    text-align: center;
    color: #777;
    padding: 25px;
}

@media(min-width:700px) {

    .grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .card img {
        height: 210px;
    }
}

@media(max-width:450px) {

    .gallery-main-image {
        height: 250px;
    }
}

</style>

</head>

<body>

<?php if (!$isConfirmPage && !$isSuccessPage): ?>

<div class="intro" id="intro">

    <div>

        <h1>
            <span>Mixeconik</span>
            <span class="blue">Store</span>
        </h1>

        <p>Welcome</p>

    </div>

</div>

<?php endif; ?>


<header>

    <h1>Mixeconik Store</h1>

</header>


<main class="container">

<?php if ($notice): ?>

<div class="notice">
    <?= h($notice) ?>
</div>

<?php endif; ?>


<?php if ($isConfirmPage): ?>

<?php $p = $pending; ?>

<section class="box">

    <h2>Confirm Your Order</h2>

    <p>Please check your product and delivery details before confirming.</p>

    <?php if (!empty($p['photos'][0])): ?>

        <img
            class="product-photo"
            src="<?= h($p['photos'][0]) ?>"
            alt=""
        >

    <?php endif; ?>

    <h3><?= h($p['product_name']) ?></h3>

    <p class="price">
        Price: ₹<?= number_format($p['price'], 2) ?>
    </p>

    <p>Quantity: <?= h($p['quantity']) ?></p>

    <h3>
        Total:
        <span class="blue">
            ₹<?= number_format($p['total'], 2) ?>
        </span>
    </h3>

    <hr style="border-color:#333">

    <h3>Delivery Address</h3>

    <div class="address-text">

        <strong><?= h($p['customer']['name']) ?></strong><br>

        Phone: <?= h($p['customer']['phone']) ?><br>

        House: <?= h($p['customer']['house']) ?><br>

        Landmark: <?= h($p['customer']['landmark']) ?><br>

        City: <?= h($p['customer']['city']) ?><br>

        State: <?= h($p['customer']['state']) ?><br>

        PIN Code: <?= h($p['customer']['pincode']) ?>

    </div>

    <p>
        Payment Method:
        <strong>Cash on Delivery</strong>
    </p>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['store_csrf']) ?>"
        >

        <button
            class="btn"
            type="submit"
            name="confirm_order"
            value="1"
        >
            Confirm Order
        </button>

    </form>

    <a class="btn secondary" href="index.php">
        Back to Home
    </a>

</section>


<?php elseif ($isSuccessPage): ?>

<section class="modal">

    <div class="modalbox">

        <h2>Order Confirmed!</h2>

        <p>Your order has been placed successfully.</p>

        <p>
            <strong>Order ID:</strong>
            <?= h($completed['id']) ?>
        </p>

        <p>
            <strong>Product:</strong>
            <?= h($completed['name']) ?>
        </p>

        <p>
            <strong>Total:</strong>
            ₹<?= number_format($completed['total'], 2) ?>
        </p>

        <p>Payment Method: Cash on Delivery</p>

        <a class="btn" href="index.php">
            Back to Home
        </a>

        <a class="btn secondary" href="index.php#products">
            Continue Shopping
        </a>

    </div>

</section>


<?php else: ?>


<!-- DELIVERY ADDRESS -->

<section class="box">

    <h2>Delivery Address</h2>

    <?php if (!empty($_SESSION['store_customer'])): ?>

        <?php $c = $_SESSION['store_customer']; ?>

        <div class="address-text">

            <strong><?= h($c['name']) ?></strong><br>

            Phone: <?= h($c['phone']) ?><br>

            <?= h($c['house']) ?>,
            <?= h($c['landmark']) ?><br>

            <?= h($c['city']) ?>,
            <?= h($c['state']) ?> -
            <?= h($c['pincode']) ?>

        </div>

    <?php else: ?>

        <p>Please add your delivery address before ordering.</p>

    <?php endif; ?>


    <button
        class="btn"
        type="button"
        onclick="toggleAddress()"
    >
        Add / Edit Address
    </button>


    <form
        method="post"
        id="addressForm"
        style="display:none;margin-top:15px"
    >

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['store_csrf']) ?>"
        >

        <input
            type="hidden"
            name="save_address"
            value="1"
        >

        <input
            name="name"
            placeholder="Name"
            required
            value="<?= h($_SESSION['store_customer']['name'] ?? '') ?>"
        >

        <input
            name="phone"
            placeholder="Phone Number"
            required
            value="<?= h($_SESSION['store_customer']['phone'] ?? '') ?>"
        >

        <input
            name="state"
            placeholder="State"
            required
            value="<?= h($_SESSION['store_customer']['state'] ?? '') ?>"
        >

        <input
            name="city"
            placeholder="City"
            required
            value="<?= h($_SESSION['store_customer']['city'] ?? '') ?>"
        >

        <input
            name="house"
            placeholder="House Name"
            required
            value="<?= h($_SESSION['store_customer']['house'] ?? '') ?>"
        >

        <input
            name="pincode"
            placeholder="PIN Code"
            maxlength="6"
            required
            value="<?= h($_SESSION['store_customer']['pincode'] ?? '') ?>"
        >

        <input
            name="landmark"
            placeholder="Landmark"
            required
            value="<?= h($_SESSION['store_customer']['landmark'] ?? '') ?>"
        >

        <button class="btn" type="submit">
            Save Address
        </button>

    </form>

</section>


<!-- SEARCH -->

<input
    class="search"
    id="search"
    placeholder="Search products..."
    oninput="filterProducts()"
>

<h2 id="products">Products</h2>


<!-- PRODUCT GRID -->

<div class="grid">

<?php foreach ($products as $p): ?>

    <?php

    $photos = $p['photos'] ?? [];

    $validPhotos = [];

    foreach ($photos as $ph) {

        $ph = (string)$ph;

        if (
            preg_match('/^[a-zA-Z0-9_\/.-]+$/', $ph) &&
            strpos($ph, '..') === false &&
            is_file(__DIR__ . '/' . $ph)
        ) {
            $validPhotos[] = $ph;
        }
    }

    $description =
        $p['description'] ?? ($p['details'] ?? '');

    ?>

    <article
        class="card"
        data-name="<?= h(strtolower($p['name'] ?? '')) ?>"
    >

        <?php if (!empty($validPhotos[0])): ?>

            <img
                src="<?= h($validPhotos[0]) ?>"
                alt="<?= h($p['name'] ?? '') ?>"
            >

        <?php else: ?>

            <div style="height:165px;display:grid;place-items:center;color:#888">
                No Image
            </div>

        <?php endif; ?>


        <div class="cardbody">

            <h3><?= h($p['name'] ?? '') ?></h3>

            <p class="price">
                ₹<?= number_format((float)($p['price'] ?? 0), 2) ?>
            </p>

            <button
                class="btn"
                style="width:100%"
                type="button"
                onclick='openGallery(
                    <?= json_encode($validPhotos, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    <?= json_encode($p["name"] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    <?= json_encode((float)($p["price"] ?? 0)) ?>,
                    <?= json_encode($description, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    <?= json_encode($p["id"] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>
                )'
            >
                View
            </button>

        </div>

    </article>

<?php endforeach; ?>

</div>


<?php if (!$products): ?>

    <p>No products available. Please visit again later.</p>

<?php endif; ?>


<!-- PRODUCT GALLERY -->

<div class="gallery-modal" id="galleryModal">

    <div class="gallery-box">

        <button
            class="close-gallery"
            type="button"
            onclick="closeGallery()"
        >
            ×
        </button>


        <div class="gallery-image-area">

            <img
                id="galleryImage"
                class="gallery-main-image"
                src=""
                alt="Product"
            >

            <button
                class="gallery-arrow gallery-prev"
                type="button"
                onclick="changePhoto(-1)"
            >
                &#10094;
            </button>

            <button
                class="gallery-arrow gallery-next"
                type="button"
                onclick="changePhoto(1)"
            >
                &#10095;
            </button>

        </div>


        <div class="gallery-count" id="galleryCount"></div>

        <div class="thumbnails" id="galleryThumbnails"></div>

        <h2 id="galleryName"></h2>

        <h3 class="price" id="galleryPrice"></h3>

        <p
            class="gallery-description"
            id="galleryDescription"
        ></p>


        <?php if (!empty($_SESSION['store_customer'])): ?>

            <form
                method="post"
                action="index.php"
                class="modal-order"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['store_csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="product_id"
                    id="galleryProductId"
                >

                <label>Quantity</label>

                <input
                    type="number"
                    name="quantity"
                    min="1"
                    max="20"
                    value="1"
                    required
                >

                <button
                    class="btn"
                    type="submit"
                    name="preview_order"
                    value="1"
                >
                    Order
                </button>

            </form>

        <?php else: ?>

            <button
                class="btn"
                style="width:100%;margin-top:12px"
                type="button"
                onclick="closeGallery();toggleAddress()"
            >
                Add Address to Order
            </button>

        <?php endif; ?>

    </div>

</div>

<?php endif; ?>

</main>


<footer>
    Mixeconik Store
</footer>


<script>

/* INTRO */

setTimeout(function() {

    const intro = document.getElementById('intro');

    if (intro) {
        intro.style.display = 'none';
    }

}, 2000);


/* ADDRESS FORM */

function toggleAddress() {

    const form = document.getElementById('addressForm');

    if (!form) return;

    form.style.display =
        form.style.display === 'block' ? 'none' : 'block';

    form.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest'
    });
}


/* SEARCH */

function filterProducts() {

    const q = document
        .getElementById('search')
        .value.toLowerCase();

    document.querySelectorAll('.card').forEach(card => {

        card.style.display =
            card.dataset.name.includes(q) ? '' : 'none';

    });
}


/* PRODUCT GALLERY */

let galleryPhotos = [];

let currentPhoto = 0;

let touchStartX = 0;


function openGallery(photos, name, price, description, productId) {

    galleryPhotos = Array.isArray(photos) ? photos : [];

    currentPhoto = 0;

    document.getElementById('galleryName').textContent = name;

    document.getElementById('galleryPrice').textContent =
        '₹' + Number(price).toFixed(2);

    document.getElementById('galleryDescription').textContent =
        description || '';

    const productIdField =
        document.getElementById('galleryProductId');

    if (productIdField) {
        productIdField.value = productId;
    }

    document.getElementById('galleryModal').style.display = 'flex';

    showPhoto();
}


function closeGallery() {

    document.getElementById('galleryModal').style.display = 'none';

}


function showPhoto() {

    const image = document.getElementById('galleryImage');

    const count = document.getElementById('galleryCount');

    const thumbs = document.getElementById('galleryThumbnails');

    thumbs.innerHTML = '';

    if (galleryPhotos.length === 0) {

        image.src = '';

        image.style.display = 'none';

        count.textContent = 'No product photos available';

        return;
    }

    image.style.display = 'block';

    image.src = galleryPhotos[currentPhoto];

    count.textContent =
        'Photo ' + (currentPhoto + 1) +
        ' of ' + galleryPhotos.length;


    galleryPhotos.forEach((photo, index) => {

        const thumb = document.createElement('img');

        thumb.src = photo;

        thumb.alt = 'Product photo ' + (index + 1);

        if (index === currentPhoto) {
            thumb.classList.add('active');
        }

        thumb.onclick = function() {

            currentPhoto = index;

            showPhoto();
        };

        thumbs.appendChild(thumb);

    });


    document.querySelector('.gallery-prev').style.display =
        galleryPhotos.length > 1 ? 'block' : 'none';

    document.querySelector('.gallery-next').style.display =
        galleryPhotos.length > 1 ? 'block' : 'none';

}


function changePhoto(direction) {

    if (galleryPhotos.length <= 1) return;

    currentPhoto += direction;

    if (currentPhoto < 0) {
        currentPhoto = galleryPhotos.length - 1;
    }

    if (currentPhoto >= galleryPhotos.length) {
        currentPhoto = 0;
    }

    showPhoto();
}


/* MOBILE SWIPE */

const galleryImage = document.getElementById('galleryImage');

if (galleryImage) {

    galleryImage.addEventListener('touchstart', function(e) {

        touchStartX = e.changedTouches[0].screenX;

    }, { passive: true });


    galleryImage.addEventListener('touchend', function(e) {

        const touchEndX = e.changedTouches[0].screenX;

        const difference = touchStartX - touchEndX;

        if (Math.abs(difference) > 40) {

            if (difference > 0) {
                changePhoto(1);
            } else {
                changePhoto(-1);
            }

        }

    }, { passive: true });

}


/* KEYBOARD NAVIGATION */

document.addEventListener('keydown', function(e) {

    const modal = document.getElementById('galleryModal');

    if (!modal || modal.style.display !== 'flex') return;

    if (e.key === 'ArrowRight') {
        changePhoto(1);
    }

    if (e.key === 'ArrowLeft') {
        changePhoto(-1);
    }

    if (e.key === 'Escape') {
        closeGallery();
    }

});

</script>

</body>
</html>