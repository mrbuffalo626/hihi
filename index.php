<?php
declare(strict_types=1);

session_start();

$products = [
    'ballpen' => ['name' => 'Ballpen', 'price' => 1200, 'icon' => 'bi-pen', 'color' => 'blue'],
    'pencil' => ['name' => 'Pencil', 'price' => 1000, 'icon' => 'bi-pencil', 'color' => 'orange'],
    'notebook' => ['name' => 'Notebook', 'price' => 3500, 'icon' => 'bi-journal-text', 'color' => 'purple'],
    'yellow-pad' => ['name' => 'Yellow Pad', 'price' => 4500, 'icon' => 'bi-file-earmark-text', 'color' => 'yellow'],
    'eraser' => ['name' => 'Eraser', 'price' => 800, 'icon' => 'bi-eraser', 'color' => 'pink'],
    'ruler' => ['name' => 'Ruler', 'price' => 1500, 'icon' => 'bi-rulers', 'color' => 'green'],
];

$_SESSION['cart'] ??= [];
$_SESSION['payment_input'] ??= '';
$_SESSION['receipt'] ??= null;
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function peso(int $amountInCents): string
{
    return '₱' . number_format($amountInCents / 100, 2);
}

function redirectToPage(): never
{
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Your session could not be verified. Please try again.'];
        redirectToPage();
    }

    $action = (string) ($_POST['action'] ?? '');
    $_SESSION['flash'] = null;

    if ($action === 'new_transaction') {
        $_SESSION['cart'] = [];
        $_SESSION['payment_input'] = '';
        $_SESSION['receipt'] = null;
    } elseif ($_SESSION['receipt'] !== null) {
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Start a new transaction before changing the completed order.'];
    } elseif ($action === 'add' || $action === 'increase' || $action === 'decrease' || $action === 'remove') {
        $productId = (string) ($_POST['product_id'] ?? '');
        if (!isset($products[$productId])) {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'That product could not be found.'];
        } elseif ($action === 'add' || $action === 'increase') {
            $quantity = (int) ($_SESSION['cart'][$productId] ?? 0);
            if ($quantity >= 99) {
                $_SESSION['flash'] = ['type' => 'warning', 'text' => 'You can add up to 99 of each product per order.'];
            } else {
                $_SESSION['cart'][$productId] = $quantity + 1;
            }
        } elseif ($action === 'decrease') {
            $quantity = (int) ($_SESSION['cart'][$productId] ?? 0) - 1;
            if ($quantity <= 0) {
                unset($_SESSION['cart'][$productId]);
            } else {
                $_SESSION['cart'][$productId] = $quantity;
            }
        } else {
            unset($_SESSION['cart'][$productId]);
        }
    } elseif ($action === 'pay') {
        $paymentInput = trim((string) ($_POST['amount_paid'] ?? ''));
        $_SESSION['payment_input'] = $paymentInput;
        $total = 0;
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            if (isset($products[$productId])) {
                $total += $products[$productId]['price'] * (int) $quantity;
            }
        }

        if ($total === 0) {
            $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Add at least one product before confirming payment.'];
        } elseif ($paymentInput === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Enter the cash amount received.'];
        } elseif (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $paymentInput)) {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Enter a valid amount using up to two decimal places.'];
        } elseif (str_starts_with($paymentInput, '-')) {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'The payment amount cannot be negative.'];
        } else {
            [$wholePesos, $centavos] = array_pad(explode('.', $paymentInput, 2), 2, '0');
            if (strlen(ltrim($wholePesos, '0')) > 9) {
                $_SESSION['flash'] = ['type' => 'danger', 'text' => 'The payment amount is too large.'];
            } else {
                $amountPaid = ((int) $wholePesos * 100) + (int) str_pad($centavos, 2, '0');
                if ($amountPaid < $total) {
                    $_SESSION['flash'] = ['type' => 'danger', 'text' => 'The payment is less than the total amount due.'];
                } else {
                    $receiptItems = [];
                    foreach ($_SESSION['cart'] as $productId => $quantity) {
                        if (isset($products[$productId])) {
                            $receiptItems[] = [
                                'name' => $products[$productId]['name'],
                                'quantity' => (int) $quantity,
                                'price' => $products[$productId]['price'],
                                'subtotal' => $products[$productId]['price'] * (int) $quantity,
                            ];
                        }
                    }
                    $_SESSION['receipt'] = [
                        'reference' => 'SS-' . strtoupper(bin2hex(random_bytes(4))),
                        'date' => date('F j, Y g:i A'),
                        'items' => $receiptItems,
                        'total' => $total,
                        'paid' => $amountPaid,
                        'change' => $amountPaid - $total,
                    ];
                    $_SESSION['payment_input'] = '';
                    $_SESSION['cart'] = [];
                    $_SESSION['flash'] = ['type' => 'success', 'text' => 'Payment confirmed. Your transaction is complete.'];
                }
            }
        }
    }

    redirectToPage();
}

$cart = $_SESSION['cart'];
$receipt = $_SESSION['receipt'];
$flash = $_SESSION['flash'] ?? null;
$_SESSION['flash'] = null;
$total = 0;
foreach ($cart as $productId => $quantity) {
    if (isset($products[$productId])) {
        $total += $products[$productId]['price'] * (int) $quantity;
    }
}
$cartCount = array_sum($cart);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Supplies | Order Desk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --ink: #172b4d;
            --muted: #718096;
            --paper: #f5f7fb;
            --brand: #405cf5;
        }
        body { background: var(--paper); color: var(--ink); }
        .navbar { background: #fff; }
        .brand-mark { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 14px; background: #e9edff; color: var(--brand); }
        .hero { background: linear-gradient(120deg, #344fe5, #687cf8); color: #fff; border-radius: 24px; }
        .hero .eyebrow { color: #dce2ff; letter-spacing: .12em; text-transform: uppercase; font-size: .75rem; font-weight: 700; }
        .panel, .product-card { border: 0; border-radius: 18px; box-shadow: 0 8px 28px rgba(31, 48, 85, .06); }
        .product-card { height: 100%; transition: transform .18s ease, box-shadow .18s ease; }
        .product-card:hover { transform: translateY(-3px); box-shadow: 0 14px 34px rgba(31, 48, 85, .1); }
        .product-icon { width: 54px; height: 54px; display: grid; place-items: center; border-radius: 16px; font-size: 1.45rem; }
        .icon-blue { background: #e8efff; color: #4169e1; }
        .icon-orange { background: #fff0df; color: #e58b2a; }
        .icon-purple { background: #f0eaff; color: #8357db; }
        .icon-yellow { background: #fff7d6; color: #bb9000; }
        .icon-pink { background: #ffeaf0; color: #dd6486; }
        .icon-green { background: #e4f7ed; color: #289562; }
        .section-label { color: var(--muted); font-size: .78rem; letter-spacing: .1em; text-transform: uppercase; font-weight: 700; }
        .muted { color: var(--muted); }
        .price { color: var(--brand); font-weight: 750; }
        .cart-row + .cart-row { border-top: 1px solid #edf0f5; }
        .qty-control { display: inline-flex; align-items: center; gap: .55rem; }
        .qty-control form { display: inline; }
        .qty-control .btn { width: 29px; height: 29px; padding: 0; display: inline-grid; place-items: center; }
        .summary-total { border-top: 1px dashed #dce1eb; }
        .receipt-paper { border: 1px dashed #cbd3e1; border-radius: 14px; background: #fbfcff; }
        .receipt-line + .receipt-line { border-top: 1px solid #edf0f5; }
        @media (max-width: 767.98px) {
            .hero { border-radius: 18px; }
            .cart-details { min-width: 0; }
        }
    </style>
</head>
<body>
<nav class="navbar border-bottom">
    <div class="container py-2">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold mb-0" href="<?= escape($_SERVER['PHP_SELF']) ?>">
            <span class="brand-mark"><i class="bi bi-backpack2-fill"></i></span>
            Student Supplies
        </a>
        <span class="small muted"><i class="bi bi-bag me-1"></i><?= (int) $cartCount ?> items</span>
    </div>
</nav>

<main class="container py-4 py-lg-5">
    <section class="hero p-4 p-md-5 mb-4 mb-lg-5">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="eyebrow mb-2">Student Supplies Ordering System</div>
                <h1 class="display-6 fw-bold mb-2">cute ko</h1>
                <p class="mb-0 text-white-50">Pick up the essentials, add them to your cart, and check out in a few easy steps.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="badge rounded-pill text-bg-light text-primary px-3 py-2"><i class="bi bi-lightning-charge-fill me-1"></i> Quick &amp; easy checkout</span>
            </div>
        </div>
    </section>

    <?php if ($flash !== null): ?>
        <div class="alert alert-<?= escape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= escape($flash['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <section class="col-lg-7" aria-labelledby="products-heading">
            <div class="d-flex justify-content-between align-items-end mb-3">
                <div>
                    <div class="section-label mb-1">Browse essentials</div>
                    <h2 id="products-heading" class="h4 fw-bold mb-0">Product List</h2>
                </div>
                <span class="small muted">6 products</span>
            </div>
            <div class="row row-cols-1 row-cols-sm-2 g-3">
                <?php foreach ($products as $productId => $product): ?>
                    <div class="col">
                        <article class="card product-card">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-4">
                                    <span class="product-icon icon-<?= escape($product['color']) ?>"><i class="bi <?= escape($product['icon']) ?>" aria-hidden="true"></i></span>
                                    <span class="badge rounded-pill text-bg-light muted">School essential</span>
                                </div>
                                <h3 class="h5 fw-bold mb-1"><?= escape($product['name']) ?></h3>
                                <div class="price mb-3"><?= peso($product['price']) ?></div>
                                <?php if ($receipt === null): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="product_id" value="<?= escape($productId) ?>">
                                        <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-plus-lg me-1"></i>Add to Cart</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary w-100" type="button" disabled>Transaction complete</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="col-lg-5">
            <section class="card panel mb-4" aria-labelledby="cart-heading">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <div class="section-label mb-1">Your selection</div>
                            <h2 id="cart-heading" class="h4 fw-bold mb-0">Shopping Cart</h2>
                        </div>
                        <span class="badge rounded-pill text-bg-primary"><?= (int) $cartCount ?></span>
                    </div>
                    <?php if ($cart === []): ?>
                        <div class="text-center py-4">
                            <div class="product-icon icon-blue mx-auto mb-3"><i class="bi bi-cart3"></i></div>
                            <p class="fw-semibold mb-1">Your cart is waiting</p>
                            <p class="small muted mb-0">Add a school supply to get started.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($cart as $productId => $quantity): ?>
                            <?php if (!isset($products[$productId])) { continue; } ?>
                            <div class="cart-row py-3 d-flex justify-content-between align-items-center gap-2">
                                <div class="cart-details">
                                    <div class="fw-semibold"><?= escape($products[$productId]['name']) ?></div>
                                    <div class="small muted"><?= peso($products[$productId]['price']) ?> each</div>
                                </div>
                                <div class="text-end">
                                    <div class="qty-control mb-1">
                                        <?php if ($receipt === null): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="decrease">
                                                <input type="hidden" name="product_id" value="<?= escape($productId) ?>">
                                                <button class="btn btn-sm btn-light rounded-circle" type="submit" aria-label="Decrease <?= escape($products[$productId]['name']) ?> quantity"><i class="bi bi-dash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <span class="small fw-semibold"><?= (int) $quantity ?></span>
                                        <?php if ($receipt === null): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="increase">
                                                <input type="hidden" name="product_id" value="<?= escape($productId) ?>">
                                                <button class="btn btn-sm btn-light rounded-circle" type="submit" aria-label="Increase <?= escape($products[$productId]['name']) ?> quantity"><i class="bi bi-plus"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small fw-bold"><?= peso($products[$productId]['price'] * (int) $quantity) ?></div>
                                    <?php if ($receipt === null): ?>
                                        <form method="post" class="mt-1">
                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="product_id" value="<?= escape($productId) ?>">
                                            <button class="btn btn-link btn-sm text-danger p-0 text-decoration-none" type="submit"><i class="bi bi-trash3 me-1"></i>Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="summary-total pt-3 mt-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="section-label">Order Summary</div>
                                <div class="small muted">Total amount due</div>
                            </div>
                            <div class="h4 fw-bold price mb-0"><?= peso($total) ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card panel mb-4" aria-labelledby="payment-heading">
                <div class="card-body p-4">
                    <div class="section-label mb-1">Cash checkout</div>
                    <h2 id="payment-heading" class="h5 fw-bold mb-3">Payment</h2>
                    <?php if ($receipt === null): ?>
                        <form method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="pay">
                            <label for="amount-paid" class="form-label small fw-semibold">Amount paid (₱)</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text">₱</span>
                                <input id="amount-paid" name="amount_paid" type="number" class="form-control" step="0.01" inputmode="decimal" placeholder="0.00" value="<?= escape($_SESSION['payment_input']) ?>">
                            </div>
                            <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirm Payment</button>
                            <div class="small muted mt-2 text-center">Cash only · Change is calculated automatically</div>
                        </form>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-2 text-success">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                            <div><div class="fw-semibold">Payment confirmed</div><div class="small muted">Receipt is ready below.</div></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($receipt !== null): ?>
                <section class="card panel" aria-labelledby="receipt-heading">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="section-label mb-1">Transaction complete</div>
                                <h2 id="receipt-heading" class="h5 fw-bold mb-0">Digital Receipt</h2>
                            </div>
                            <i class="bi bi-receipt-cutoff fs-4 text-primary"></i>
                        </div>
                        <div class="receipt-paper p-3">
                            <div class="d-flex justify-content-between small mb-1"><span class="muted">Reference</span><span class="fw-semibold"><?= escape($receipt['reference']) ?></span></div>
                            <div class="d-flex justify-content-between small mb-3"><span class="muted">Date &amp; time</span><span><?= escape($receipt['date']) ?></span></div>
                            <?php foreach ($receipt['items'] as $item): ?>
                                <div class="receipt-line py-2 small">
                                    <div class="d-flex justify-content-between fw-semibold">
                                        <span><?= escape($item['name']) ?> × <?= (int) $item['quantity'] ?></span>
                                        <span><?= peso($item['subtotal']) ?></span>
                                    </div>
                                    <div class="muted"><?= peso($item['price']) ?> each</div>
                                </div>
                            <?php endforeach; ?>
                            <div class="receipt-line pt-3 mt-1 small">
                                <div class="d-flex justify-content-between mb-1"><span>Total amount due</span><span class="fw-bold"><?= peso($receipt['total']) ?></span></div>
                                <div class="d-flex justify-content-between mb-1"><span>Amount paid</span><span><?= peso($receipt['paid']) ?></span></div>
                                <div class="d-flex justify-content-between fw-bold text-success"><span>Change</span><span><?= peso($receipt['change']) ?></span></div>
                            </div>
                        </div>
                        <div class="alert alert-success py-2 small mt-3 mb-3"><i class="bi bi-check-circle-fill me-1"></i>Payment received. Thank you for your purchase!</div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="new_transaction">
                            <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>New Transaction</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
    <footer class="text-center small muted mt-5">Student Supplies Ordering System <span class="mx-1">·</span> Cashier checkout</footer>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
