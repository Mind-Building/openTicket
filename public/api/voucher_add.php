<?php
/**
 * API Endpoint to add vouchers to cart (customer purchase)
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Voucher;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\Reservation;
use App\Services\LoggerService;

Session::start();

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('voucher', 10, 60);

try {
    $sessionId = session_id();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
        exit;
    }
    
    $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    
    if ($eventId <= 0 || $categoryId <= 0 || $quantity <= 0 || $quantity > 10) {
        throw new \Exception('Ungültige Eingabedaten.');
    }
    
    // Verify event allows voucher sales
    $eventModel = new Event();
    $event = $eventModel->getById($eventId);
    if (!$event || !$event['sell_vouchers']) {
        throw new \Exception('Gutscheinverkauf ist für diese Veranstaltung nicht aktiviert.');
    }
    if (!$event['active'] || !empty($event['is_finished'])) {
        throw new \Exception('Diese Veranstaltung ist bereits vorüber. Es können keine Gutscheine mehr gekauft werden.');
    }
    
    // Get category for price
    $categoryModel = new SeatCategory();
    $category = $categoryModel->getById($categoryId);
    if (!$category) {
        throw new \Exception('Kategorie nicht gefunden.');
    }
    
    $voucherMode = $_POST['voucher_mode'] ?? 'single';

    $voucherModel = new Voucher();
    $createdVouchers = [];

    if ($voucherMode === 'separate') {
        // Separate codes: one voucher per ticket
        for ($i = 0; $i < $quantity; $i++) {
            $voucherId = $voucherModel->create([
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'quantity' => 1,
                'customer_id' => null,
                'session_id' => $sessionId,
                'purchase_intent' => 'buy',
                'status_id' => 1,
                'is_reduced' => 0,
                'is_fn_card' => 0,
            ]);
            $createdVouchers[] = $voucherId;
        }
    } else {
        // Single code: one voucher covering all tickets
        $voucherId = $voucherModel->create([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'quantity' => $quantity,
            'customer_id' => null,
            'session_id' => $sessionId,
            'purchase_intent' => 'buy',
            'status_id' => 1,
            'is_reduced' => 0,
            'is_fn_card' => 0,
        ]);
        $createdVouchers[] = $voucherId;
    }
    
    // Reset countdown for all cart items
    $reservationModel = new Reservation();
    $allCartItems = $reservationModel->getBySession($sessionId);
    if (!empty($allCartItems)) {
        $connection = $reservationModel->getConnection();
        foreach ($allCartItems as $item) {
            $sql = "UPDATE reservations SET created_at = NOW() WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $item['id']]);
        }
    }
    
    // Log status change for each created voucher
    $voucherModelForLog = new Voucher();
    foreach ($createdVouchers as $vid) {
        $v = $voucherModelForLog->getById($vid);
        if ($v) {
            logVoucherStatusChange('customer', $v['voucher_code'], null, 1, 'cart_add_buy', [
                'voucher_id' => $vid,
                'session_id' => substr($sessionId, 0, 32),
            ]);
        }
    }

    LoggerService::logCustomer('voucher_added_to_cart', [
        'session_id' => $sessionId,
        'event_id' => $eventId,
        'category_id' => $categoryId,
        'quantity' => $quantity,
        'voucher_mode' => $voucherMode,
        'voucher_ids' => $createdVouchers,
    ]);

    echo json_encode([
        'success' => true,
        'message' => $quantity . ' Gutschein(e) wurden zum Warenkorb hinzugefügt.',
        'voucher_ids' => $createdVouchers
    ]);
} catch (\Exception $e) {
    LoggerService::logCustomer('voucher_add_error', [
        'session_id' => $sessionId ?? 'unknown',
        'error' => $e->getMessage(),
    ], 'error');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
exit;
