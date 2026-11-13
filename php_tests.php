<?php
header('Content-Type: text/plain');  
@unlink('cookies.txt');
@unlink('staff.txt');

require_once 'config.php'; // gives you $pdo

// reset test data before every run
$pdo->prepare(
    "UPDATE orders SET order_status='pending', previous_status=NULL, cancellation_reason=NULL
     WHERE order_number = 'ORD-TEST-CANCEL'"
)->execute();

function call($url, $post = null, $jar = 'cookies.txt') {
    $ch = curl_init("http://localhost/websystem/$url");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}
[$code, $res] = call('login.php', ['email' => 'test@email.com', 'password' => 'password123']);
echo "HTTP code: $code\n";
echo substr($res, 0, 600) . "\n";

function check($name, $cond) {
    echo ($cond ? "PASS" : "FAIL") . " - $name\n";
}

// customer log in
[$code, $res] = call('login.php', ['email' => 'test@email.com', 'password' => 'password123']);
check('login redirects to dashboard', $code === 302 && str_contains($res, 'dashboard_customer.php'));

// cancel request, no reason, show error
[$code, $res] = call('cancel_request.php?order_id=19', ['order_id' => 19, 'cancellation_reason' => '']);
check('empty reason is rejected', $code === 200 && str_contains($res, 'Please choose a reason'));

// cancel request, with reason, redirect
[$code, $res] = call('cancel_request.php?order_id=19', ['order_id' => 19, 'cancellation_reason' => 'better_price']);
check('valid reason redirects', $code === 302 && str_contains($res, 'cancel_requested=1'));

// customer not access admin
[$code, ] = call('order_detail.php?id=1');
check('customer blocked from admin page', $code === 403);

// log in as staff with their own cookie jar
call('login.php', ['email' => 'staff@email.com', 'password' => 'password123'], 'staff.txt');

// valid SKU from main.sql
[$code, $res] = call('pos.php', ['scan' => 1, 'sku' => 'PLMPOL-S'], 'staff.txt');
check('valid SKU is added', str_contains($res, 'Added PLM Boys Polo Uniform'));

// unknown SKU
[$code, $res] = call('pos.php', ['scan' => 1, 'sku' => 'FAKE-123'], 'staff.txt');
check('unknown SKU rejected', str_contains($res, 'Product not found'));

// empty scan
[$code, $res] = call('pos.php', ['scan' => 1, 'sku' => ''], 'staff.txt');
check('empty SKU rejected', str_contains($res, 'Scan or type a SKU'));

// customer can't use POS
[$code, ] = call('pos.php');   // uses cookies.txt (customer)
check('customer blocked from POS', $code === 403);