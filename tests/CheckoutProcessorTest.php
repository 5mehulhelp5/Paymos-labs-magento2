<?php

declare(strict_types=1);

use Paymos\Payment\Service\CheckoutProcessor;
use Paymos\Payment\Service\Config;
use Paymos\Payment\Tests\FakeClient;
use Paymos\Payment\Tests\FakeInvoices;
use Paymos\Payment\Tests\InMemorySnapshotRepository;

function test_magento_checkout_creates_invoice_and_snapshot()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository();
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100',
        'currency' => 'usd',
        'customer_id' => 77,
    ), 'sandbox');

    assertSameValue('inv_123', $result['invoice_id'], 'The invoice id must be returned.');
    assertSameValue('https://paymos.test/pay/inv_123', $result['payment_url'], 'The payment URL must be returned.');
    assertFalseValue($result['reused'], 'A fresh invoice must not be flagged reused.');

    $payload = $invoices->createPayloads[0];
    assertSameValue('prj_123', $payload['project_id'], 'project_id must come from the credential config.');
    assertSameValue('100', $payload['amount'], 'Amount must be an exact normalized decimal string.');
    assertSameValue('USD', $payload['currency'], 'Currency must be upper-cased.');
    assertSameValue('100000042-0', $payload['external_order_id'], 'external_order_id must be increment id + renew suffix.');
    assertSameValue('77', $payload['client_id'], 'client_id must be the Magento customer id.');

    // Forbidden fields must never be sent.
    assertFalseValue(array_key_exists('allow_multiple_payments', $payload), 'Partial payments are always enabled; the retired switch must be omitted.');
    assertFalseValue(isset($payload['lifetime']), 'No lifetime field — TTL is server-side.');
    assertFalseValue(isset($payload['merchant_id']), 'merchant_id is never sent (derived from project_id).');
    assertFalseValue(isset($payload['return_url']), 'No return_url field.');
    assertFalseValue(isset($payload['webhook_url']), 'No webhook_url field.');

    assertSameValue(1, count($snapshots->rows), 'A snapshot row must be persisted.');
    assertSameValue('100', $snapshots->rows[0]['amount'], 'Snapshot amount must be stored for AmountGuard.');
}

function test_magento_checkout_omits_client_id_for_guest()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => null,
    ), 'sandbox');

    $payload = $invoices->createPayloads[0];
    assertFalseValue(isset($payload['client_id']), 'Guest checkout must omit client_id (never send the email).');
}

function test_magento_checkout_reuses_snapshot_when_amount_unchanged()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertTrueValue($result['reused'], 'A matching snapshot must be reused.');
    assertSameValue(0, count($invoices->createPayloads), 'Reuse must NOT create a second invoice.');
}

function test_magento_checkout_renews_invoice_when_amount_changes()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_renew',
        'payment_url' => 'https://paymos.test/pay/inv_renew',
        'status' => 'awaiting_client',
    ), array('invoice_id' => 'inv_123', 'status' => 'awaiting_client'));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '150.00', // cart changed
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'A changed amount must NOT reuse the old invoice.');
    assertSameValue('100000042-1', $invoices->createPayloads[0]['external_order_id'], 'A changed order must bump the renew suffix.');
    // BUG-166: the old invoice is cancelled on the server first, or the buyer
    // could pay both.
    assertSameValue(array('cancel inv_123', 'create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'the old invoice is cancelled before the new one is created.');
    assertSameValue('cancelled', $snapshots->rows[0]['status'], 'the old row records the cancel, so its cancelled webhook is ignored as stale.');
}

function test_magento_checkout_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090 (Magento): the buyer returns to the redirect after the Paymos
    // invoice's 30 minutes ran out. Same amount and currency, but the old link
    // leads to an expired checkout — a new invoice must be cut.
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_fresh',
        'payment_url' => 'https://paymos.test/pay/inv_fresh',
        'status' => 'awaiting_client',
    ), array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'awaiting_client',
        'is_test' => true,
        'expires_at' => time() - 3600,
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'an expired invoice must not be reused.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'the buyer must get the fresh invoice.');
    assertSameValue('100000042-1', $invoices->createPayloads[0]['external_order_id'], 'the fresh invoice needs a new external order id.');
    // Still awaiting_client on the server (its expiry job has not run): it is
    // cancelled before the replacement.
    assertSameValue(array('get inv_123', 'cancel inv_123', 'create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'read, cancel, create.');
}

function test_magento_checkout_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'cancelled'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_fresh',
        'payment_url' => 'https://paymos.test/pay/inv_fresh',
        'status' => 'awaiting_client',
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'a cancelled invoice must not be reused.');
    assertSameValue(1, count($invoices->createPayloads), 'a fresh invoice must be created.');
    assertSameValue(array('create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'a recorded final status needs neither a read nor a cancel.');
}

function test_magento_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. Only the server's answer decides; an open invoice is kept.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $config = Config::fromArray(paymos_m2_generated_config());
        $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
        $invoices = new FakeInvoices(array(
            'invoice_id' => 'inv_second',
            'payment_url' => 'https://paymos.test/pay/inv_second',
            'status' => 'awaiting_client',
        ), array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'is_final' => false,
            'expires_at' => time() - 3600,
        ));
        $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
            return new FakeClient($invoices);
        });

        $result = $processor->start(array(
            'order_id' => 42,
            'increment_id' => '100000042',
            'amount' => '100.00',
            'currency' => 'USD',
            'customer_id' => 77,
        ), 'sandbox');

        assertTrueValue($result['reused'], $status . ': the open invoice is reused.');
        assertSameValue('https://paymos.test/pay/inv_123', $result['payment_url'], $status . ': the buyer returns to the same invoice.');
        assertSameValue(0, count($invoices->createPayloads), $status . ': no second invoice is created.');
    }
}

function paymos_m2_changed_order()
{
    return array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '150.00',
        'currency' => 'USD',
        'customer_id' => 77,
    );
}

function paymos_m2_start_expecting_block(CheckoutProcessor $processor, array $order, $environment = 'sandbox')
{
    try {
        $processor->start($order, $environment);
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('checkout must refuse to replace an invoice it cannot prove closed.');
}

function test_magento_checkout_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    // BUG-166: only awaiting_client is cancellable on the server. A network
    // picked, funds confirming, a part paid or a full payment keeps the old
    // invoice; no second one is cut.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $config = Config::fromArray(paymos_m2_generated_config());
        $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
        $invoices = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => $status));
        $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
            return new FakeClient($invoices);
        });

        $e = paymos_m2_start_expecting_block($processor, paymos_m2_changed_order());

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('cancel inv_123', 'get inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), $status . ': cancel, read, and no create.');
        assertSameValue('awaiting_client', $snapshots->rows[0]['status'], $status . ': an open or paid status read here is not recorded.');
        assertSameValue(1, count($snapshots->rows), $status . ': no new snapshot row.');
    }
}

function test_magento_checkout_does_not_replace_an_invoice_the_server_answers_404_for()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices();
    $invoices->cancelException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $e = paymos_m2_start_expecting_block($processor, paymos_m2_changed_order());

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(array('cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'no invoice is created after a 404.');
}

function test_magento_checkout_does_not_renew_when_the_lookup_answers_404()
{
    // Same amount; before BUG-166 a 404 on the read cut a new invoice.
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices();
    $invoices->getException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $invoices->cancelException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    paymos_m2_start_expecting_block($processor, array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ));

    assertSameValue(array('get inv_123', 'cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'read, cancel attempt, and no create.');
}

function test_magento_checkout_cancels_the_old_invoice_in_its_own_environment()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $sandbox = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => 'awaiting_client'));
    $live = new FakeInvoices(array(
        'invoice_id' => 'inv_live',
        'payment_url' => 'https://paymos.test/pay/inv_live',
        'status' => 'awaiting_client',
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function ($environment) use ($sandbox, $live) {
        return new FakeClient($environment === 'live' ? $live : $sandbox);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'live');

    assertSameValue('inv_live', $result['invoice_id'], 'the live invoice is issued.');
    assertSameValue(array('cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($sandbox), 'the sandbox invoice is cancelled with sandbox credentials.');
    assertSameValue(array('create'), \Paymos\Payment\Tests\paymos_m2_calls($live), 'only the create goes to live.');
}

function test_magento_checkout_sends_single_product_and_omits_optional_fields()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository();
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '47.00',
        'currency' => 'USD',
        'customer_id' => 77,
        'products' => array(
            array(
                'title' => '<b>Canvas</b> &amp; Blue',
                'description' => '<p>Archival print</p>',
                'quantity' => 2,
                'unit_amount' => '24.50',
            ),
        ),
    ), 'sandbox');

    $payload = $invoices->createPayloads[0];
    assertSameValue('47', $payload['amount'], 'Native grand total must never be replaced by sum(qty * unit_amount).');
    assertFalseValue(array_key_exists('image_url', $payload['products'][0]), 'Product images must be absent.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas & Blue',
                'quantity' => 2,
                'unit_amount' => '24.50',
                'description' => 'Archival print',
            ),
        ),
        $payload['products'],
        'Single valid product must be normalized and sent.'
    );

    $minimalInvoices = new FakeInvoices();
    $minimalProcessor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($minimalInvoices) {
        return new FakeClient($minimalInvoices);
    });
    $minimalProcessor->start(array(
        'order_id' => 43,
        'increment_id' => '100000043',
        'amount' => '12.00',
        'currency' => 'USD',
        'products' => array(
            array(
                'title' => 'Priority Support',
                'description' => "<p>&nbsp;\xc2\xa0</p>",
                'quantity' => '1',
                'unit_amount' => '12.00',
            ),
        ),
    ), 'sandbox');

    assertSameValue(
        array(
            array(
                'title' => 'Priority Support',
                'quantity' => 1,
                'unit_amount' => '12.00',
            ),
        ),
        $minimalInvoices->createPayloads[0]['products'],
        'Blank description must be omitted per item without rejecting the product.'
    );
}

function test_magento_checkout_sends_cart_and_falls_back_all_or_nothing()
{
    $config = Config::fromArray(paymos_m2_generated_config());

    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($invoices) {
        return new FakeClient($invoices);
    });
    $processor->start(array(
        'order_id' => 44,
        'increment_id' => '100000044',
        'amount' => '42.50',
        'currency' => 'USD',
        'products' => array(
            array(
                'title' => 'Canvas',
                'description' => 'Signed print',
                'quantity' => 2,
                'unit_amount' => '15.00',
            ),
            array(
                'title' => 'Notebook',
                'description' => null,
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
    ), 'sandbox');

    assertSameValue('42.5', $invoices->createPayloads[0]['amount'], 'Grand total with shipping/discount/tax must be preserved.');
    assertSameValue(2, count($invoices->createPayloads[0]['products']), 'Multi-item cart must preserve all items.');
    assertFalseValue(array_key_exists('description', $invoices->createPayloads[0]['products'][1]), 'Missing description must be omitted.');
    assertFalseValue(array_key_exists('image_url', $invoices->createPayloads[0]['products'][1]), 'Product images must be absent.');

    $fifty = array();
    for ($i = 1; $i <= 50; $i++) {
        $fifty[] = array('title' => 'Item ' . $i, 'quantity' => 1, 'unit_amount' => 1.25);
    }
    $fiftyInvoices = new FakeInvoices();
    $fiftyProcessor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($fiftyInvoices) {
        return new FakeClient($fiftyInvoices);
    });
    $fiftyProcessor->start(array(
        'order_id' => 45,
        'increment_id' => '100000045',
        'amount' => '62.50',
        'currency' => 'USD',
        'products' => $fifty,
    ), 'sandbox');
    assertSameValue(50, count($fiftyInvoices->createPayloads[0]['products']), 'Exactly 50 items must be sent.');
    assertSameValue('1.25', $fiftyInvoices->createPayloads[0]['products'][0]['unit_amount'], 'Numeric unit_amount must convert to decimal string.');

    $fiftyOne = $fifty;
    $fiftyOne[] = array('title' => 'Item 51', 'quantity' => 1, 'unit_amount' => '1.25');

    $fallbackCases = array(
        'empty' => array(),
        'over_50' => $fiftyOne,
        'fractional_qty' => array(
            array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Fabric', 'quantity' => 1.5, 'unit_amount' => '10.00'),
        ),
        'blank_title' => array(
            array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => '<b>&nbsp;</b>', 'quantity' => 1, 'unit_amount' => '10.00'),
        ),
        'negative_unit_amount' => array(
            array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Adjustment', 'quantity' => 1, 'unit_amount' => '-5.00'),
        ),
        'non_array_row' => array(
            array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            'not-an-item',
        ),
    );

    foreach ($fallbackCases as $label => $badProducts) {
        $fallbackInvoices = new FakeInvoices();
        $fallbackProcessor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($fallbackInvoices) {
            return new FakeClient($fallbackInvoices);
        });
        $fallbackProcessor->start(array(
            'order_id' => 46,
            'increment_id' => '100000046',
            'amount' => '30.00',
            'currency' => 'USD',
            'products' => $badProducts,
        ), 'sandbox');

        assertFalseValue(
            array_key_exists('products', $fallbackInvoices->createPayloads[0]),
            $label . ': invalid or out-of-range carts must fall back all-or-nothing to amount-only.'
        );
        assertSameValue('30', $fallbackInvoices->createPayloads[0]['amount'], $label . ': grand total remains intact.');
    }
}

function test_magento_checkout_retry_ignores_presentation_metadata_differences()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository();
    $invoices = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => 'awaiting_client'));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $first = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
        'products' => array(
            array(
                'title' => 'Studio Pro',
                'description' => 'Version A',
                'quantity' => 2,
                'unit_amount' => '50.00',
            ),
        ),
    ), 'sandbox');

    $second = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
        'products' => array(
            array(
                'title' => 'Studio Pro Updated',
                'description' => 'Version B',
                'quantity' => 1,
                'unit_amount' => '100.00',
            ),
        ),
    ), 'sandbox');

    assertFalseValue($first['reused'], 'First call creates the invoice.');
    assertTrueValue($second['reused'], 'Changed presentation metadata with unchanged money must reuse the invoice.');
    assertSameValue(array('create', 'get inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'No cancel or second create when only presentation metadata changed.');
    assertSameValue(1, count($invoices->createPayloads), 'Only the initial invoice was created.');

    // Changing money still cancels and renews with the latest products.
    $renewed = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '120.00',
        'currency' => 'USD',
        'customer_id' => 77,
        'products' => array(
            array(
                'title' => 'Studio Pro Updated',
                'quantity' => 1,
                'unit_amount' => '120.00',
            ),
        ),
    ), 'sandbox');

    assertFalseValue($renewed['reused'], 'Changed amount must create a fresh invoice.');
    assertSameValue('100000042-1', $invoices->createPayloads[1]['external_order_id'], 'Renewed invoice bumps external_order_id.');
    assertSameValue('Studio Pro Updated', $invoices->createPayloads[1]['products'][0]['title'], 'Renewed invoice sends current products.');

    // Lost create response retry returning an already-paid invoice with products[] remains applicable.
    $replaySnapshots = new InMemorySnapshotRepository();
    $replayInvoices = new FakeInvoices(array(
        'invoice_id' => 'inv_paid',
        'payment_url' => 'https://paymos.test/pay/inv_paid',
        'status' => 'paid',
    ));
    (new CheckoutProcessor($config, $replaySnapshots, static function () use ($replayInvoices) {
        return new FakeClient($replayInvoices);
    }))->start(array(
        'order_id' => 47,
        'increment_id' => '100000047',
        'amount' => '100.00',
        'currency' => 'USD',
        'products' => array(
            array('title' => 'Studio Pro', 'quantity' => 2, 'unit_amount' => '50.00'),
        ),
    ), 'sandbox');
    assertSameValue('awaiting_client', $replaySnapshots->rows[0]['status'], 'Recovered paid create with products[] stays applicable for OrderMapper.');
    assertSameValue('100', $replaySnapshots->rows[0]['amount'], 'Snapshot keeps canonical amount.');
}

function test_magento_redirect_extracts_visible_items_and_handles_fractional_fallback()
{
    $makeOrder = static function (array $items, string $grandTotal) {
        return new class($items, $grandTotal) extends \Magento\Sales\Model\Order {
            /** @var array<int, object> */
            private $items;
            /** @var string */
            private $grandTotal;

            public function __construct(array $items, string $grandTotal)
            {
                $this->items = $items;
                $this->grandTotal = $grandTotal;
            }

            public function reset() { return $this; }
            public function getResource() { return $this; }
            public function load($order, $id) { return $this; }
            public function getState() { return 'new'; }
            public function getTotalPaid() { return '0'; }
            public function getTotalRefunded() { return '0'; }
            public function getId() { return 42; }
            public function getEntityId() { return 42; }
            public function getIncrementId() { return '100000042'; }
            public function getGrandTotal() { return $this->grandTotal; }
            public function getOrderCurrencyCode() { return 'USD'; }
            public function getCustomerId() { return 77; }
            public function getStoreId() { return 1; }
            public function getStatus() { return 'pending'; }
            public function setStatus($status) { return $this; }
            public function addCommentToStatusHistory($comment, $status = false) { return $this; }
            public function save() { return $this; }
            public function getAllVisibleItems() { return $this->items; }
            public function getStore()
            {
                throw new \LogicException('Product media base URLs must not be read.');
            }
        };
    };

    $makeItem = static function (array $spec) {
        return new class($spec) {
            /** @var array<string, mixed> */
            private $spec;
            public function __construct(array $spec) { $this->spec = $spec; }
            public function isDeleted() { return !empty($this->spec['deleted']); }
            public function getParentItemId() { return $this->spec['parent_item_id'] ?? null; }
            public function getName() { return $this->spec['name'] ?? null; }
            public function getProductOptions() { return $this->spec['options'] ?? null; }
            public function getDescription() { return $this->spec['description'] ?? null; }
            public function getQtyOrdered() { return $this->spec['qty'] ?? null; }
            public function getPrice() { return $this->spec['price'] ?? null; }
            public function getProduct()
            {
                if (!empty($this->spec['deleted_product'])) {
                    return null;
                }
                $shortDesc = $this->spec['short_description'] ?? null;
                return new class($shortDesc) {
                    private $shortDesc;
                    public function __construct($shortDesc)
                    {
                        $this->shortDesc = $shortDesc;
                    }
                    public function getImage() { throw new \LogicException('Product images must not be read.'); }
                    public function getShortDescription() { return $this->shortDesc; }
                };
            }
        };
    };

    $runRedirect = static function ($order, FakeInvoices $invoices) {
        $session = new class($order) extends \Magento\Checkout\Model\Session {
            private $order;
            public function __construct($order) { $this->order = $order; }
            public function getLastRealOrder() { return $this->order; }
        };
        $messages = new class implements \Magento\Framework\Message\ManagerInterface {
            public $errors = array();
            public function addErrorMessage($message, $group = null) { $this->errors[] = (string) $message; return $this; }
        };
        $snapshots = new class(new InMemorySnapshotRepository()) extends \Paymos\Payment\Service\InvoiceSnapshotRepository {
            public $inner;
            public function __construct(InMemorySnapshotRepository $inner) { $this->inner = $inner; }
            public function synchronized(int $orderId, callable $action) { return $this->inner->synchronized($orderId, $action); }
            public function assertLocked(int $orderId): void { $this->inner->assertLocked($orderId); }
            public function save(array $row): void { $this->inner->save($row); }
            public function findByExternalOrderId(string $externalOrderId) { return $this->inner->findByExternalOrderId($externalOrderId); }
            public function findByOrderId(int $orderId) { return $this->inner->findByOrderId($orderId); }
            public function updateStatus(string $paymosInvoiceId, string $status): void { $this->inner->updateStatus($paymosInvoiceId, $status); }
            public function findUnpaidRecent(int $limit, int $createdAfterUnix): array { return $this->inner->findUnpaidRecent($limit, $createdAfterUnix); }
        };
        $configProvider = new class extends \Paymos\Payment\Service\GeneratedConfigProvider {
            public function __construct() {}
            public function get(): Config { return Config::fromArray(paymos_m2_generated_config()); }
        };
        $clientFactory = new class($invoices) extends \Paymos\Payment\Service\PaymosClientFactory {
            private $invoices;
            public function __construct(FakeInvoices $invoices) { $this->invoices = $invoices; }
            public function asCallable(): callable
            {
                $invoices = $this->invoices;
                return static function () use ($invoices) { return new FakeClient($invoices); };
            }
        };

        $controller = new \Paymos\Payment\Controller\Payment\Redirect(
            new \Magento\Framework\Controller\ResultFactory(),
            $session,
            $configProvider,
            $snapshots,
            $clientFactory,
            paymos_m2_settings(),
            $messages,
            new \Paymos\Payment\Service\Logger()
        );

        return $controller->execute();
    };

    $invoices = new FakeInvoices();
    $order = $makeOrder(array(
        $makeItem(array(
            'name' => 'Canvas',
            'product_type' => 'configurable',
            'options' => array('attributes_info' => array(array('label' => 'Color', 'value' => 'Blue'))),
            'short_description' => '<p>Giclée print</p>',
            'qty' => '2.0000',
            'price' => '24.5000',
        )),
        $makeItem(array(
            'name' => 'Deleted Catalog Item',
            'deleted_product' => true,
            'qty' => '1.0000',
            'price' => '10.0000',
        )),
        $makeItem(array(
            'name' => 'Hidden Child Row',
            'parent_item_id' => 1,
            'qty' => '2.0000',
            'price' => '0.0000',
        )),
    ), '47.0000');

    $redirect = $runRedirect($order, $invoices);
    assertSameValue('https://paymos.test/pay/inv_123', $redirect->url, 'Redirect must send buyer to Paymos invoice.');
    $payload = $invoices->createPayloads[0];
    assertSameValue('47', $payload['amount'], 'Grand total comes from Magento order grand total.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas · Blue',
                'quantity' => 2,
                'unit_amount' => '24.50',
                'description' => 'Giclée print',
            ),
            array(
                'title' => 'Deleted Catalog Item',
                'quantity' => 1,
                'unit_amount' => '10.00',
            ),
        ),
        $payload['products'],
        'Magento Redirect must extract visible items with DECIMAL(12,4) qty and DECIMAL(20,4) price, excluding child rows.'
    );

    // Fractional qty_ordered in any visible line triggers all-or-nothing amount-only fallback.
    $fractionalInvoices = new FakeInvoices();
    $fractionalOrder = $makeOrder(array(
        $makeItem(array('name' => 'Shirt', 'qty' => '1.0000', 'price' => '20.0000')),
        $makeItem(array('name' => 'Fabric', 'qty' => '1.5000', 'price' => '10.0000')),
    ), '35.0000');
    $runRedirect($fractionalOrder, $fractionalInvoices);
    assertFalseValue(array_key_exists('products', $fractionalInvoices->createPayloads[0]), 'Fractional qty_ordered must fall back to amount-only.');
    assertSameValue('35', $fractionalInvoices->createPayloads[0]['amount'], 'Fallback preserves Magento grand total.');
}
