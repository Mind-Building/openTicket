<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Voucher;
use App\Services\EmailService;

Auth::requireAdmin();
Session::start();

$eventModel = new Event();
$seatPlanModel = new SeatPlan();
$categoryModel = new SeatCategory();
$reservationModel = new Reservation();
$customerModel = new Customer();
$voucherModel = new Voucher();

$step = $_GET['step'] ?? 'event';
$eventId = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';
$selectedSeatsJson = $_GET['seats'] ?? '';

$events = $eventModel->getAll(false); // Presales: show all events, including inactive
$activeEventsCount = count($events);

// If only one event is active, auto-select it
if ($activeEventsCount === 1 && $eventId === 0) {
    $eventId = (int)$events[0]['id'];
}

// Get event dates for selected event
$eventDates = [];
if ($eventId > 0) {
    $eventDates = $eventModel->getDates($eventId);
}

// Prepare JavaScript-safe values for datepicker and seatplan
$allowedDatesJs = json_encode(array_map(function($ed) {
    return $ed['date'];
}, $eventDates));

// Get categories for the seat plan
$categories = [];
if ($eventId > 0) {
    $categories = $categoryModel->getByEvent($eventId);
}
$categoriesJs = json_encode($categories);

// Prepare URLs
$urlSell = json_encode(url('admin/sell.php'));
$jsPresalesFreeApi = json_encode(url('api/presales_free_seat.php'));
$jsPresalesReserveApi = json_encode(url('api/presales_reserve_seat.php'));
$jsCustomerSearchApi = json_encode(url('api/customer_search.php'));
$flatpickrJs = url('libs/js/flatpickr.min.js');
$flatpickrDeJs = url('libs/js/flatpickr.de.js');

// Get best default date: first event date >= today, fallback to first available
$today = date('Y-m-d');
$defaultDate = null;
if (!empty($eventDates)) {
    // Sort by date ASC to ensure correct order
    usort($eventDates, fn($a, $b) => strcmp($a['date'], $b['date']));
    foreach ($eventDates as $ed) {
        if ($ed['date'] >= $today) {
            $defaultDate = $ed['date'];
            break;
        }
    }
    // If all dates are past, use first date
    if (!$defaultDate) {
        $defaultDate = $eventDates[0]['date'];
    }
}
$earliestDateJs = json_encode($defaultDate);

$jsEventId = $eventId > 0 ? (int)$eventId : 0;
$jsEventDate = json_encode($eventDate);

$error = '';
$success = '';

// Cleanup expired cart items when entering the seat selection step
if ($step === 'seats') {
    $reservationModel->cleanupExpiredCartItems();
}

// Guard: step 'seats' requires a valid event_id and date; fall back to step 'event' with an error
if ($step === 'seats' && ($eventId === 0 || empty($eventDate))) {
    $step = 'event';
    $error = $eventId === 0
        ? 'Bitte wählen Sie eine Veranstaltung aus.'
        : 'Bitte wählen Sie ein Datum aus.';
}

// Handle form submissions
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_seats'])) {
        // Step 2: Seats selected, go to payment/reservation selection
        $selectedSeats = $_POST['seats'] ?? [];
        if (empty($selectedSeats)) {
            $error = 'Bitte wählen Sie mindestens einen Platz aus.';
            $step = 'seats';
        } else {
            $selectedSeatsJson = json_encode($selectedSeats);
            $step = 'payment';
        }
    } elseif (isset($_POST['create_order'])) {
        // Step 3: Create order/reservation
        try {
            $selectedSeats = json_decode($_POST['seats_json'], true);
            if (empty($selectedSeats)) {
                throw new \Exception('Keine Plätze ausgewählt.');
            }
            
            $eventId = (int)$_POST['event_id'];
            $eventDate = $_POST['event_date'];

            // Price type per seat chosen in step 3 (validated against what is allowed for the seat)
            $selectedSeats = \App\Helpers\SalePriceChoices::apply($selectedSeats, (array)($_POST['price_type'] ?? []), $eventId, $eventDate, (array)($_POST['fn_card_number'] ?? []));
            
            // Check if event date is in the past (presales can book even after deadline, but not past dates)
            $today = date('Y-m-d');
            if ($eventDate < $today) {
                throw new \Exception('Das Veranstaltungsdatum liegt in der Vergangenheit.');
            }

            // Events without a seat plan: re-check availability to prevent overselling
            $orderEvent = $eventModel->getById($eventId);
            if ($orderEvent && empty($orderEvent['use_seat_plan']) && ($orderEvent['max_seats'] ?? null) !== null) {
                $maxSeats = (int)$orderEvent['max_seats'];
                $existing = $reservationModel->getAll(['event_id' => $eventId, 'event_date' => $eventDate]);
                $reservedCount = 0;
                foreach ($existing as $r) {
                    if ((int)$r['status_id'] >= 2) {
                        $reservedCount++;
                    } elseif ((int)$r['status_id'] === 1 && (time() - strtotime($r['created_at'])) < 900) {
                        $reservedCount++;
                    }
                }
                $available = max(0, $maxSeats - $reservedCount);
                if (count($selectedSeats) > $available) {
                    throw new \Exception('Es sind nur noch ' . $available . ' Tickets verfügbar.');
                }
            }

            $orderType = $_POST['order_type'] ?? 'buy'; // 'buy' or 'reserve'
            $paymentType = $_POST['payment_type'] ?? 'cash'; // 'cash', 'customer', or 'voucher'
            $customerId = null;
            $statusId = 4; // Default: Bezahlt (Vorverkauf)
            $redeemedVoucherId = null;

            if ($orderType === 'reserve') {
                $statusId = 3; // Reserviert (Vorverkauf)
                $paymentType = 'customer'; // Reservierungen benötigen immer Kundendaten
            }

            // Handle voucher payment
            if ($paymentType === 'voucher') {
                $voucherCode = strtoupper(trim($_POST['voucher_code'] ?? ''));
                if (empty($voucherCode)) {
                    throw new \Exception('Bitte geben Sie einen Gutscheincode ein.');
                }
                $voucher = $voucherModel->getByCode($voucherCode);
                if (!$voucher) {
                    throw new \Exception('Gutscheincode nicht gefunden.');
                }
                if ((int)$voucher['status_id'] !== 0) {
                    throw new \Exception('Dieser Gutschein ist bereits eingelöst oder ungültig.');
                }
                if ((int)$voucher['event_id'] !== $eventId) {
                    throw new \Exception('Dieser Gutschein ist nicht für diese Veranstaltung gültig.');
                }
                $statusId = 8; // Bezahlt per Gutschein
                $redeemedVoucherId = (int)$voucher['id'];
                // Use customer from voucher if available
                if (!empty($voucher['customer_id'])) {
                    $customerId = (int)$voucher['customer_id'];
                }
            }

            // Handle customer data
            if ($paymentType === 'customer') {
                if ($orderType === 'reserve' || !empty($_POST['first_name'])) {
                    // Create or get customer
                    $customerData = [
                        'salutation' => $_POST['salutation'] ?? null,
                        'first_name' => $_POST['first_name'] ?? '',
                        'last_name' => $_POST['last_name'] ?? '',
                        'street' => $_POST['street'] ?? '',
                        'house_number' => $_POST['house_number'] ?? '',
                        'postal_code' => $_POST['postal_code'] ?? '',
                        'city' => $_POST['city'] ?? '',
                        'email' => $_POST['email'] ?? '',
                        'phone_prefix' => $_POST['phone_prefix'] ?? null,
                        'phone_number' => $_POST['phone_number'] ?? null,
                        'session_id' => session_id(),
                        'item_type' => 'ticket'
                    ];
                    
                    // Allow presales to skip email if customer has none
                    $skipEmail = !empty($_POST['skip_email']);

                    // Validate required fields
                    $required = ['first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'phone_prefix', 'phone_number'];
                    if (!$skipEmail) {
                        $required[] = 'email';
                    }
                    foreach ($required as $field) {
                        if (empty($customerData[$field])) {
                            throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
                        }
                    }

                    // Validate email format only if not skipped
                    $email = trim($customerData['email']);
                    if ($skipEmail) {
                        $customerData['email'] = '';
                    } else {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            throw new \Exception('Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                        }
                        // Validate email confirmation (if provided)
                        if (isset($_POST['email_confirm'])) {
                            $emailConfirm = trim($_POST['email_confirm']);
                            if ($email !== $emailConfirm) {
                                throw new \Exception('Die E-Mail-Adressen stimmen nicht überein. Bitte überprüfen Sie Ihre Eingabe.');
                            }
                        }
                    }

                    // Reuse the customer created earlier in THIS checkout only when a
                    // real, matching e-mail is present. An empty e-mail must never match
                    // another empty e-mail, otherwise distinct e-mail-less buyers would
                    // collapse onto one customer record (and thus one order group).
                    $existingCustomer = $customerModel->getBySession(session_id(), 'ticket');
                    if ($existingCustomer
                        && $customerData['email'] !== ''
                        && strcasecmp(trim($existingCustomer['email'] ?? ''), trim($customerData['email'])) === 0) {
                        $customerModel->update($existingCustomer['id'], $customerData);
                        $customerId = $existingCustomer['id'];
                    } else {
                        $customerId = $customerModel->createOrGet($customerData);
                    }
                }
            } else {
                // Barverkauf - no customer needed
                $customerId = null;
            }
            
            // Create reservations
            $createdCount = 0;
            foreach ($selectedSeats as $seatData) {
                if (!is_array($seatData)) {
                    $seatData = json_decode($seatData, true);
                }
                if (!$seatData) continue;

                $isCompanion = !empty($seatData['is_companion']);

                try {
                    $reservationModel->updateOrCreate([
                        'session_id' => session_id(),
                        'customer_id' => $customerId,
                        'event_id' => $eventId,
                        'event_date' => $eventDate,
                        'seat_identifier' => $seatData['id'],
                        'category_id' => $seatData['category'] ?? null,
                        'status_id' => $statusId,
                        'is_reduced' => $isCompanion ? 0 : ($seatData['is_reduced'] ?? 0),
                        'is_fn_card' => $isCompanion ? 0 : ($seatData['is_fn_card'] ?? 0),
                        'fn_card_number' => $isCompanion ? null : ($seatData['fn_card_number'] ?? null),
                        'is_honor_card' => 0,
                        'is_companion' => $isCompanion ? 1 : 0,
                        'voucher_id' => $redeemedVoucherId,
                    ]);
                    $createdCount++;
                } catch (\Exception $e) {
                    error_log("Error creating reservation: " . $e->getMessage());
                }
            }
            
            if ($createdCount > 0) {
                // Mark voucher as redeemed
                if ($redeemedVoucherId) {
                    $voucherModel->update($redeemedVoucherId, ['status_id' => 3]);
                }

                // Collect detailed sale information for logging
                $customerName = '';
                $customerEmail = '';
                $totalPrice = 0;
                $seatIdentifiers = [];
                $eventName = '';
                // Note: $eventDate is already set from $_POST['event_date'] above – do NOT reset it here
                $orderType = $_POST['order_type'] ?? 'buy';
                $paymentType = $_POST['payment_type'] ?? 'customer';
                
                if ($customerId) {
                    $customer = $customerModel->getById($customerId);
                    if ($customer) {
                        $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                        $customerEmail = $customer['email'] ?? '';
                    }
                }
                
                // Get event details
                if ($eventId > 0) {
                    $event = $eventModel->getById($eventId);
                    if ($event) {
                        $eventName = $event['name'] ?? '';
                    }
                }
                
                // Calculate total and collect seat info
                foreach ($selectedSeats as $seatData) {
                    if (!is_array($seatData)) {
                        $seatData = json_decode($seatData, true);
                    }
                    if (!$seatData) continue;
                    
                    $seatIdentifiers[] = $seatData['id'] ?? 'unknown';
                    
                    // Calculate price (companions are free)
                    $seatCategory = null;
                    foreach ($categories as $cat) {
                        if ((int)$cat['id'] === (int)($seatData['category'] ?? 0)) {
                            $seatCategory = $cat;
                            break;
                        }
                    }
                    $categoryPrice = $seatCategory ? (float)$seatCategory['price'] : (float)($seatData['category_price'] ?? 0);
                    if (!empty($seatData['is_companion'])) {
                        $categoryPrice = 0;
                    } elseif (!empty($seatData['is_reduced']) && $seatCategory && (float)($seatCategory['reduced_price'] ?? 0) > 0) {
                        $categoryPrice = (float)$seatCategory['reduced_price'];
                    }
                    $totalPrice += $categoryPrice;
                }
                
                // Log admin sale with detailed information
                if (function_exists('logAdminAction')) {
                    $userId = (string)Auth::id();
                    $username = Auth::username() ?? 'unknown';
                    logAdminAction($userId, 'admin_sale', [
                        'customer_name'  => $customerName,
                        'amount'         => $totalPrice,
                        'ticket_count'   => $createdCount,
                        'payment_method' => $paymentType,
                        'event_id'       => $eventId,
                        'event_name'     => $eventName,
                        'event_date'     => $eventDate,
                        'seats'          => $seatIdentifiers,
                        'seat_count'     => count($seatIdentifiers),
                        'order_type'     => $orderType, // 'buy' or 'reserve'
                        'total_price'    => $totalPrice,
                        'customer_email' => substr($customerEmail, 0, 3) . '***' . (strpos($customerEmail, '@') ? '@***' : ''),
                        'admin_user'     => $username,
                    ]);
                }
                
                // Get created reservation IDs
                $createdReservations = $reservationModel->getAllBySession(session_id());
                $reservationIds = [];
                foreach ($createdReservations as $res) {
                    if ($res['event_id'] == $eventId && 
                        $res['event_date'] == $eventDate && 
                        (int)$res['status_id'] == $statusId) {
                        // Check if this reservation matches one of our selected seats
                        foreach ($selectedSeats as $seatData) {
                            if (!is_array($seatData)) {
                                $seatData = json_decode($seatData, true);
                            }
                            if ($seatData && $res['seat_identifier'] == $seatData['id']) {
                                $reservationIds[] = $res['id'];
                                break;
                            }
                        }
                    }
                }
                
                // Send email notification to customer
                $emailEnabled = Config::get('email_enabled', true);
                // Result is passed to the success page (email=sent/failed) so
                // staff sees when the mail did not go out and can resend it.
                $emailStatus = null;
                if ($emailEnabled && $customerId && !empty($customerEmail) && !empty($reservationIds)) {
                    try {
                        $emailService = new EmailService();
                        if ($statusId === 4) {
                            // Bezahlt (Vorverkauf) – Bestätigungsmail OHNE Download-Link senden
                            $presalesReservations = [];
                            foreach ($reservationIds as $resId) {
                                $resDetail = $reservationModel->getById((int)$resId);
                                if ($resDetail) {
                                    $presalesReservations[] = $resDetail;
                                }
                            }
                            if (!empty($presalesReservations)) {
                                $emailSent = $emailService->sendPresalesEmail(
                                    $customerEmail,
                                    $customerName ?: $customerEmail,
                                    $presalesReservations
                                );
                                $emailStatus = $emailSent ? 'sent' : 'failed';
                            }
                        } elseif ($statusId === 3) {
                            // Reserviert – send reservation confirmation email with ALL reserved tickets
                            $allResDetails = [];
                            foreach ($reservationIds as $resId) {
                                $resDetail = $reservationModel->getById((int)$resId);
                                if ($resDetail) {
                                    $allResDetails[] = $resDetail;
                                }
                            }
                            if (!empty($allResDetails)) {
                                $emailSent = $emailService->sendReservationEmail(
                                    $customerEmail,
                                    $customerName ?: $customerEmail,
                                    $allResDetails,
                                    ''
                                );
                                $emailStatus = $emailSent ? 'sent' : 'failed';
                            }
                        }
                    } catch (\Exception $emailException) {
                        $emailStatus = 'failed';
                        error_log("Presales sell - Email error: " . $emailException->getMessage());
                    }
                }

                // Start a fresh session for the next sale so each order keeps its own
                // session_id. Orders are grouped by session_id + customer_id + event +
                // date; on a long-lived staff session distinct sales would otherwise
                // merge. Reservations are already saved (with the old session_id) and
                // the print link is token-based, so this is safe. Auth data is kept.
                Session::regenerate();

                // Redirect to checkout success page
                $redirectParams = [];
                if (!empty($reservationIds)) {
                    $redirectParams[] = 'reservation_ids=' . implode(',', $reservationIds);
                }
                if ($emailStatus !== null) {
                    $redirectParams[] = 'email=' . $emailStatus;
                }
                $redirectUrl = url('admin/checkout_success.php');
                if (!empty($redirectParams)) {
                    $redirectUrl .= '?' . implode('&', $redirectParams);
                }
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                $error = 'Keine Reservierungen konnten erstellt werden.';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $step = 'payment';
            // Keep the seats so the order form can be corrected and sent again
            $selectedSeatsJson = (string)($_POST['seats_json'] ?? '');
        }
    }
}

$event = null;
$seatPlan = [];
$categories = [];
$reservedSeats = [];
$ownCartSeats = []; // this session's cart seats of the chosen event/date
$selectedSeats = [];
$noSeatplan = null; // For events without a seat plan: category + availability info

if ($eventId > 0 && !empty($eventDate)) {
    // Check if event date is still bookable (for customers, presales can always book)
    // Note: Presales might need to book even after deadline, so we only check for past dates
    $today = date('Y-m-d');
    if ($eventDate < $today) {
        $_SESSION['error_message'] = 'Das Veranstaltungsdatum liegt in der Vergangenheit.';
        header('Location: ' . url('admin/sell.php?step=date&event_id=' . $eventId));
        exit;
    }
    $event = $eventModel->getById($eventId);
    if ($event && $event['use_seat_plan']) {
        $seatPlan = $seatPlanModel->getSeatPlanStructure($eventId);
        $categories = $categoryModel->getByEvent($eventId);
        $reservedSeats = $reservationModel->getReservedSeats($eventId, $eventDate);
        // Seats already in this session's cart (e.g. after "Zurück" from step 3) are shown as
        // selected again instead of as taken; the JS restores them into the selection
        foreach ($reservationModel->getBySession(session_id()) as $cartItem) {
            $cartValid = strtotime((string)$cartItem['created_at']) > time() - Reservation::CART_LIFETIME_MINUTES * 60;
            if ($cartValid && (int)$cartItem['event_id'] === (int)$eventId && $cartItem['event_date'] === $eventDate) {
                unset($reservedSeats[$cartItem['seat_identifier']]);
                $ownCartSeats[] = [
                    'seat_identifier' => $cartItem['seat_identifier'],
                    'is_reduced' => (int)($cartItem['is_reduced'] ?? 0),
                    'is_fn_card' => (int)($cartItem['is_fn_card'] ?? 0),
                    'fn_card_number' => $cartItem['fn_card_number'] ?? null,
                    'is_companion' => (int)($cartItem['is_companion'] ?? 0),
                ];
            }
        }
    } elseif ($event && !$event['use_seat_plan']) {
        // Event without seat plan: quantity-based selling (same model as customer event.php)
        $nsCategory = null;
        if (!empty($event['seat_category_id'])) {
            $nsCategory = $categoryModel->getById((int)$event['seat_category_id']);
        }
        $nsMaxSeats = $event['max_seats'] ?? null;
        $nsAvailable = $nsMaxSeats;
        if ($nsMaxSeats !== null) {
            $existing = $reservationModel->getAll([
                'event_id' => $eventId,
                'event_date' => $eventDate,
            ]);
            $reservedCount = 0;
            foreach ($existing as $r) {
                if ((int)$r['status_id'] >= 2) {
                    $reservedCount++;
                } elseif ((int)$r['status_id'] === 1 && (time() - strtotime($r['created_at'])) < 900) {
                    $reservedCount++;
                }
            }
            $nsAvailable = max(0, (int)$nsMaxSeats - $reservedCount);
        }
        $nsReducedActive = Config::get('reduced_prices_active', false)
            && !empty($event['allow_reduced_prices'])
            && $nsCategory && !empty($nsCategory['reduced_price']) && $nsCategory['reduced_price'] > 0;
        $noSeatplan = [
            'category' => $nsCategory,
            'max_seats' => $nsMaxSeats,
            'available' => $nsAvailable,
            'reduced_active' => (bool)$nsReducedActive,
        ];
    }
}

if (!empty($selectedSeatsJson)) {
    $selectedSeats = json_decode($selectedSeatsJson, true) ?? [];
}

// Handle clear-cart action (GET ?clear_cart=1)
if (isset($_GET['clear_cart']) && $_GET['clear_cart'] == '1') {
    $cartItems = $reservationModel->getAll(['session_id' => session_id(), 'status_id' => 1]);
    $userId = (string)($_SESSION['user_id'] ?? '');
    foreach ($cartItems as $ci) {
        logSeatsReleased(session_id(), (int)$ci['event_id'], $ci['event_date'], [$ci['seat_identifier']], $userId ?: null, 'presales_cart_cleared');
        $reservationModel->delete((int)$ci['id']);
    }
    if (!empty($cartItems)) {
        \App\Services\LoggerService::logPresales('cart_cleared_manual', [
            'user_id'    => $userId,
            'session_id' => substr(session_id(), 0, 32),
            'count'      => count($cartItems),
        ]);
    }
    header('Location: ' . url('admin/sell.php'));
    exit;
}

// Cart warning: show if step=event and there are stale cart items for this session
$cartReservations = [];
if ($step === 'event') {
    $cartReservations = $reservationModel->getAll(['session_id' => session_id(), 'status_id' => 1]);
}

$pageTitle = 'Verkauf / Reservierung';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Verkauf / Reservierung</h2>
    <a href="' . url('admin/reservations.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> ' . htmlspecialchars($error) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

if ($success) {
    $content .= '<div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> ' . htmlspecialchars($success) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

// Cart warning banner
if (!empty($cartReservations)) {
    $cartCount = count($cartReservations);
    $cartEventName = htmlspecialchars($cartReservations[0]['event_name'] ?? '');
    $cartDate = $cartReservations[0]['event_date'] ? date('d.m.Y', strtotime($cartReservations[0]['event_date'])) : '';
    $cartEventId = (int)($cartReservations[0]['event_id'] ?? 0);
    $cartEventDate = $cartReservations[0]['event_date'] ?? '';
    $clearCartUrl = url('admin/sell.php?clear_cart=1');
    $reservationsUrl = url('admin/reservations.php');

    // Build resume URL: reconstruct seats JSON from cart items
    $resumeSeats = array_map(fn($r) => [
        'id'         => $r['seat_identifier'],
        'category'   => $r['category_id'] ?? null,
        'is_reduced' => (int)($r['is_reduced'] ?? 0),
        'is_fn_card' => (int)($r['is_fn_card'] ?? 0),
    ], $cartReservations);
    $resumeUrl = url('admin/sell.php') . '?step=payment&event_id=' . $cartEventId . '&date=' . urlencode($cartEventDate) . '&seats=' . urlencode(json_encode($resumeSeats));

    $content .= '
    <div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
        <i class="bi bi-cart-x fs-4 flex-shrink-0 mt-1"></i>
        <div class="flex-grow-1">
            <strong>Offener Warenkorb:</strong> ' . $cartCount . ' ' . ($cartCount > 1 ? 'Pl&auml;tze' : 'Platz') . ' noch im Warenkorb
            ' . ($cartEventName ? '(' . $cartEventName . ($cartDate ? ', ' . $cartDate : '') . ')' : '') . '.
            Diese sind für 15 Minuten reserviert und werden danach automatisch freigegeben.
            <div class="mt-2 d-flex flex-wrap gap-2">
                <a href="' . $resumeUrl . '" class="btn btn-sm btn-warning">
                    <i class="bi bi-cart-check"></i> Bestellung fortsetzen
                </a>
                <a href="' . $clearCartUrl . '" class="btn btn-sm btn-outline-danger"
                   onclick="return confirm(\'Warenkorb wirklich leeren? Die ' . $cartCount . ' reservierten Plätze werden freigegeben.\')">
                    <i class="bi bi-trash"></i> Warenkorb leeren
                </a>
            </div>
        </div>
    </div>';
}

// Step 1: Event and Date Selection
if ($step === 'event') {
    $eventSelectHtml = '';
    if ($activeEventsCount > 1) {
        $eventSelectHtml = '
                    <div class="col-md-6 mb-3">
                        <label for="event_id" class="form-label">Veranstaltung <span class="text-danger">*</span></label>
                        <select class="form-select" id="event_id" name="event_id" required onchange="loadEventDates()">
                            <option value="">Bitte wählen...</option>';
        foreach ($events as $ev) {
            $selected = $eventId === (int)$ev['id'] ? 'selected' : '';
            $label = htmlspecialchars($ev['name']) . (!$ev['active'] ? ' (inaktiv)' : '');
            $eventSelectHtml .= '<option value="' . $ev['id'] . '" ' . $selected . '>' . $label . '</option>';
        }
        $eventSelectHtml .= '
                        </select>
                    </div>';
    } else {
        // Only one event - hide selection, auto-select
        $eventSelectHtml = '<input type="hidden" id="event_id" name="event_id" value="' . $eventId . '">';
        if ($eventId > 0) {
            $eventSelectHtml .= '
                    <div class="col-md-12 mb-3">
                        <div class="alert alert-info">
                            <strong>Veranstaltung:</strong> ' . htmlspecialchars($events[0]['name']) . '
                        </div>
                    </div>';
        }
    }
    
    $content .= '
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Schritt 1: Veranstaltung und Datum wählen</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" id="event-date-form">
                <input type="hidden" name="step" value="seats">
                <div class="row">
                    ' . $eventSelectHtml . '
                    <div class="col-md-6 mb-3">
                        <label for="date" class="form-label">Datum <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="date" name="date" value="' . htmlspecialchars($eventDate) . '" required readonly style="background-color: #fff; cursor: pointer;">
                            <button class="btn btn-outline-secondary" type="button" onclick="if(window.fp) { window.fp.open(); } else { alert(\'Kalender nicht initialisiert!\'); }">
                                <i class="bi bi-calendar3"></i>
                            </button>
                        </div>
                        <small class="text-muted">' . ($eventId > 0 && empty($eventDates) ? 'Für diese Veranstaltung sind noch keine Termine verfügbar.' : 'Bitte wählen Sie ein Datum aus dem Kalender') . '</small>
                    </div>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary" id="continue-btn" ' . (empty($eventDate) ? 'disabled' : '') . '>
                        Weiter <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>';
}

// Step 2: Seat Selection
elseif ($step === 'seats' && $eventId > 0 && !empty($eventDate)) {
    // Get show start time from event_date
    $showTime = $event['show_start_time'] ?? '20:30:00';
    $eventDateData = $eventModel->getDateByEventAndDate($eventId, $eventDate);
    if ($eventDateData && !empty($eventDateData['show_start_time'])) {
        $showTime = $eventDateData['show_start_time'];
    }
    $timeFormatted = date('H:i', strtotime($showTime)) . ' Uhr';
    
    $content .= '
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Schritt 2: Plätze auswählen</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <strong>' . htmlspecialchars($event['name']) . '</strong> - ' . date('d.m.Y', strtotime($eventDate)) . ' um ' . $timeFormatted . '
            </div>
            <form method="POST" id="seat-selection-form">
                ' . \App\Core\Csrf::field() . '
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="event_date" value="' . $eventDate . '">

                <div class="row g-3">
                    <div class="col-lg-10">
                        <div id="companion-banner" class="alert alert-info d-none justify-content-between align-items-center mb-2">
                            <span class="banner-text"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cancelCompanionMode()">Abbrechen</button>
                        </div>
                        <div id="seatplan-container">';
    
    if (!empty($seatPlan)) {
        // Render seat plan (similar to reservation_create.php)
        $seatPlanHtml = '<div class="seatplan-container seatplan-fit" style="--n: ' . \App\Helpers\SeatPlanLayout::maxSlots($seatPlan ?? []) . '">';
        foreach ($seatPlan as $row) {
            if ($row['type'] === 'row' && isset($row['no'])) {
                if ($row['no'] === 0) {
                    $seatPlanHtml .= '<div class="seatplan-row" style="width: 100%;">
                        <div class="stage-element" style="width: 100%; flex: 1;">
                            <i class="bi bi-music-note-beamed"></i> BÜHNE
                        </div>
                    </div>';
                } else {
                    $seatPlanHtml .= '<div class="seatplan-row">
                        <div class="row-label">Reihe ' . $row['no'] . '</div>
                        <div class="seatplan-elements">';
                    
                    foreach ($row['content'] as $element) {
                        $seatId = '';
                        $isReserved = false;
                        
                        if ($element['type'] === 'seat') {
                            $seatId = 'seat_' . $row['no'] . '_' . $element['no'];
                            $isReserved = isset($reservedSeats[$seatId]);
                        } elseif ($element['type'] === 'wheelchair') {
                            $seatId = 'wheelchair_' . $row['no'] . '_' . $element['no'];
                            $isReserved = isset($reservedSeats[$seatId]);
                        } elseif ($element['type'] === 'table') {
                            $seatId = 'table_' . $element['no'];
                        }
                        
                        $category = null;
                        if (isset($element['category']) && $element['category']) {
                            foreach ($categories as $cat) {
                                if ($cat['id'] == $element['category']) {
                                    $category = $cat;
                                    break;
                                }
                            }
                        }
                        $bgColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';
                        
                        if ($element['type'] === 'seat') {
                            $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '"
                                  data-seat-id="' . htmlspecialchars($seatId) . '"
                                  data-row="' . $row['no'] . '"
                                  data-seat="' . $element['no'] . '"
                                  data-category="' . ($element['category'] ?? '') . '"
                                  style="border-color: ' . $bgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white;"
                                  onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                  title="' . ($isReserved ? 'Reserviert' : 'Verfügbar') . '">
                                ' . $element['no'] . '
                            </span>';
                        } elseif ($element['type'] === 'wheelchair') {
                            $seatPlanHtml .= '<span class="seat-element wheelchair-seat ' . ($isReserved ? 'reserved' : '') . '"
                                  data-seat-id="' . htmlspecialchars($seatId) . '"
                                  data-row="' . $row['no'] . '"
                                  data-seat="' . $element['no'] . '"
                                  data-category="' . ($element['category'] ?? '') . '"
                                  data-wheelchair="1"
                                  style="border-color: ' . $bgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white;"
                                  onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                  title="' . ($isReserved ? 'Rollstuhlplatz reserviert' : 'Rollstuhlplatz – Begleitperson wird automatisch mitgebucht (kostenfrei)') . '">
                                <i class="bi bi-person-wheelchair"></i> ' . $element['no'] . '
                            </span>';
                        } elseif ($element['type'] === 'table') {
                            // Render table with seats around it (same as customer view)
                            $tableSeats = $element['seats'] ?? [];
                            $seatPlanHtml .= '<div class="table-with-seats" style="display: inline-flex; flex-direction: column; align-items: center; gap: 0.25rem;">';
                            
                            // Front seats
                            if (!empty($tableSeats['front'])) {
                                $seatPlanHtml .= '<div style="display: flex; gap: 0.25rem;">';
                                foreach ($tableSeats['front'] as $seat) {
                                    $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isReserved = isset($reservedSeats[$tableSeatId]);
                                    
                                    $seatCategory = null;
                                    if ($seat['category']) {
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $seat['category']) {
                                                $seatCategory = $cat;
                                                break;
                                            }
                                        }
                                    }
                                    $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                    
                                    $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '" 
                                          data-seat-id="' . htmlspecialchars($tableSeatId) . '"
                                          data-row="' . $row['no'] . '"
                                          data-seat="' . $seat['number'] . '"
                                          data-table="' . $element['no'] . '"
                                          data-category="' . ($seat['category'] ?? '') . '"
                                          style="border-color: ' . $seatBgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white;"
                                          onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                          title="' . ($isReserved ? 'Reserviert' : 'Tisch ' . $element['no'] . ', Platz ' . $seat['number']) . '">
                                        ' . $seat['number'] . '
                                    </span>';
                                }
                                $seatPlanHtml .= '</div>';
                            }
                            
                            // Left, table, right
                            $seatPlanHtml .= '<div style="display: flex; align-items: center; gap: 0.25rem;">';
                            
                            // Left seats
                            if (!empty($tableSeats['left'])) {
                                $seatPlanHtml .= '<div style="display: flex; flex-direction: column; gap: 0.25rem;">';
                                foreach ($tableSeats['left'] as $seat) {
                                    $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isReserved = isset($reservedSeats[$tableSeatId]);
                                    
                                    $seatCategory = null;
                                    if ($seat['category']) {
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $seat['category']) {
                                                $seatCategory = $cat;
                                                break;
                                            }
                                        }
                                    }
                                    $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                    
                                    $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '" 
                                          data-seat-id="' . htmlspecialchars($tableSeatId) . '"
                                          data-row="' . $row['no'] . '"
                                          data-seat="' . $seat['number'] . '"
                                          data-table="' . $element['no'] . '"
                                          data-category="' . ($seat['category'] ?? '') . '"
                                          style="border-color: ' . $seatBgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white; font-size: 0.75rem; padding: 0.25rem 0.5rem;"
                                          onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                          title="' . ($isReserved ? 'Reserviert' : 'Tisch ' . $element['no'] . ', Platz ' . $seat['number']) . '">
                                        ' . $seat['number'] . '
                                    </span>';
                                }
                                $seatPlanHtml .= '</div>';
                            }
                            
                            // Table element
                            $seatPlanHtml .= '<span class="table-element" style="font-size: 0.9rem; padding: 0.5rem; background-color: #6c757d; color: white; border: 1px solid #5a6268;">Tisch ' . $element['no'] . '</span>';
                            
                            // Right seats
                            if (!empty($tableSeats['right'])) {
                                $seatPlanHtml .= '<div style="display: flex; flex-direction: column; gap: 0.25rem;">';
                                foreach ($tableSeats['right'] as $seat) {
                                    $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isReserved = isset($reservedSeats[$tableSeatId]);
                                    
                                    $seatCategory = null;
                                    if ($seat['category']) {
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $seat['category']) {
                                                $seatCategory = $cat;
                                                break;
                                            }
                                        }
                                    }
                                    $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                    
                                    $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '" 
                                          data-seat-id="' . htmlspecialchars($tableSeatId) . '"
                                          data-row="' . $row['no'] . '"
                                          data-seat="' . $seat['number'] . '"
                                          data-table="' . $element['no'] . '"
                                          data-category="' . ($seat['category'] ?? '') . '"
                                          style="border-color: ' . $seatBgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white; font-size: 0.75rem; padding: 0.25rem 0.5rem;"
                                          onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                          title="' . ($isReserved ? 'Reserviert' : 'Tisch ' . $element['no'] . ', Platz ' . $seat['number']) . '">
                                        ' . $seat['number'] . '
                                    </span>';
                                }
                                $seatPlanHtml .= '</div>';
                            }
                            
                            $seatPlanHtml .= '</div>';
                            
                            // Back seats
                            if (!empty($tableSeats['back'])) {
                                $seatPlanHtml .= '<div style="display: flex; gap: 0.25rem;">';
                                foreach ($tableSeats['back'] as $seat) {
                                    $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isReserved = isset($reservedSeats[$tableSeatId]);
                                    
                                    $seatCategory = null;
                                    if ($seat['category']) {
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $seat['category']) {
                                                $seatCategory = $cat;
                                                break;
                                            }
                                        }
                                    }
                                    $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                    
                                    $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '" 
                                          data-seat-id="' . htmlspecialchars($tableSeatId) . '"
                                          data-row="' . $row['no'] . '"
                                          data-seat="' . $seat['number'] . '"
                                          data-table="' . $element['no'] . '"
                                          data-category="' . ($seat['category'] ?? '') . '"
                                          style="border-color: ' . $seatBgColor . '; background-color: ' . ($isReserved ? '#dc3545' : '#28a745') . '; color: white;"
                                          onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                          title="' . ($isReserved ? 'Reserviert' : 'Tisch ' . $element['no'] . ', Platz ' . $seat['number']) . '">
                                        ' . $seat['number'] . '
                                    </span>';
                                }
                                $seatPlanHtml .= '</div>';
                            }
                            
                            $seatPlanHtml .= '</div>';
                        } elseif ($element['type'] === 'distance' || $element['type'] === 'spacer') {
                            $seatPlanHtml .= '<span class="distance-element"' . seatplanStairsAttr($element) . '>&nbsp;</span>';
                        }
                    }
                    
                    $seatPlanHtml .= '</div></div>';
                }
            }
        }
        $seatPlanHtml .= '</div>';

        // Legend
        $legendHtml = '<div class="seatplan-legend mt-3">'
            . '<div class="legend-item"><span class="legend-box" style="background:#28a745;"></span> Verfügbar</div>'
            . '<div class="legend-item"><span class="legend-box" style="background:#ffc107;"></span> Ausgewählt</div>'
            . '<div class="legend-item"><span class="legend-box" style="background:#dc3545;"></span> Reserviert</div>';
        // Only what this hall has
        $legendTypes = seatplanElementTypes($seatPlan);
        if (isset($legendTypes['stairs'])) {
            $legendHtml .= '<div class="legend-item"><span class="legend-box" style="background:#fff;border:2px solid #000;"></span> Treppe</div>';
        }
        foreach ($categories as $cat) {
            if (!empty($cat['color'])) {
                $legendHtml .= '<div class="legend-item">'
                    . '<span class="legend-box" style="background:transparent;border:4px solid ' . htmlspecialchars($cat['color']) . ';"></span> '
                    . htmlspecialchars($cat['name'])
                    . (isset($cat['price']) ? ' (' . number_format((float)$cat['price'], 2, ',', '.') . ' €)' : '')
                    . '</div>';
            }
        }
        $legendHtml .= '</div>';
        $seatPlanHtml .= $legendHtml;

        $content .= $seatPlanHtml;
    } elseif ($noSeatplan !== null) {
        // Event without seat plan: quantity-based selling
        $ns = $noSeatplan;
        if ($ns['max_seats'] === null) {
            $content .= '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i> Für diese Veranstaltung ist keine maximale Sitzanzahl konfiguriert.</div>';
        } elseif ($ns['available'] <= 0) {
            $content .= '<div class="alert alert-warning mb-0"><i class="bi bi-x-circle"></i> Diese Veranstaltung ist ausverkauft.</div>';
        } else {
            $catInfo = '';
            if ($ns['category']) {
                $catInfo = '<div class="alert alert-info"><strong>' . htmlspecialchars($ns['category']['name']) . '</strong> &ndash; '
                    . number_format((float)$ns['category']['price'], 2, ',', '.') . ' &euro; pro Ticket';
                if ($ns['reduced_active']) {
                    $catInfo .= '<br><small>Erm&auml;&szlig;igt: ' . number_format((float)$ns['category']['reduced_price'], 2, ',', '.') . ' &euro;</small>';
                }
                $catInfo .= '</div>';
            }
            $reducedField = '';
            if ($ns['reduced_active']) {
                $reducedField = '
                <div class="col-6 col-md-4">
                    <label for="ns-reduced-count" class="form-label">davon erm&auml;&szlig;igt</label>
                    <input type="number" class="form-control" id="ns-reduced-count" min="0" max="' . (int)$ns['available'] . '" value="0" oninput="updateNoSeatplanSelection()">
                </div>';
            }
            $content .= $catInfo . '
            <div class="row g-3 align-items-end">
                <div class="col-6 col-md-4">
                    <label for="ns-quantity" class="form-label">Anzahl Tickets</label>
                    <input type="number" class="form-control" id="ns-quantity" min="0" max="' . (int)$ns['available'] . '" value="0" oninput="updateNoSeatplanSelection()">
                    <small class="text-muted">Verf&uuml;gbar: ' . (int)$ns['available'] . ($ns['max_seats'] !== null ? ' von ' . (int)$ns['max_seats'] : '') . '</small>
                </div>
                ' . $reducedField . '
            </div>';
        }
    } else {
        $content .= '<p class="text-muted text-center">Für diese Veranstaltung ist noch kein Sitzplan vorhanden.</p>';
    }
    
    $content .= '
                        </div>
                    </div>
                    <div class="col-lg-2">
                        <div style="position: sticky; top: 1rem;">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0">Ausgewählte Plätze <span id="selected-seats-count" class="badge bg-secondary ms-1" style="display:none"></span></h6>
                                </div>
                                <div class="card-body">
                                    <div id="selected-seats">
                                        <p class="text-muted small">Keine Plätze ausgewählt</p>
                                    </div>
                                    <div id="total-price" class="mt-2">
                                        <strong>Gesamt: 0,00 €</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="select_seats" class="btn btn-primary" id="continue-btn" disabled>
                                    Weiter <i class="bi bi-arrow-right"></i>
                                </button>
                                <a href="' . url('admin/sell.php') . '" class="btn btn-outline-secondary">Zurück</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>';
}

// Step 3: Payment/Reservation Selection
elseif ($step === 'payment' && !empty($selectedSeats)) {
    $content .= '
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Schritt 3: Art der Bestellung</h5>
        </div>
        <div class="card-body">
            <form method="POST" id="order-form">
                ' . \App\Core\Csrf::field() . '
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="event_date" value="' . $eventDate . '">
                <input type="hidden" name="seats_json" value="' . htmlspecialchars($selectedSeatsJson) . '">
                ' . \App\Helpers\SalePriceChoices::render($selectedSeats, $eventId, $eventDate) . '
                
                <div class="mb-4">
                    <label class="form-label"><strong>Bestellart:</strong></label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="order_type" id="order_buy" value="buy" checked onchange="togglePaymentOptions()">
                        <label class="form-check-label" for="order_buy">
                            <strong>Kaufen</strong>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="order_type" id="order_reserve" value="reserve" onchange="togglePaymentOptions()">
                        <label class="form-check-label" for="order_reserve">
                            <strong>Reservieren</strong>
                        </label>
                    </div>
                </div>
                
                <div id="payment-options" class="mb-4">
                    <label class="form-label"><strong>Zahlungsart:</strong></label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="payment_type" id="payment_cash" value="cash" checked onchange="toggleCustomerForm()">
                        <label class="form-check-label" for="payment_cash">
                            <strong>Barverkauf</strong> (keine Kundendaten erforderlich)
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="payment_type" id="payment_customer" value="customer" onchange="toggleCustomerForm()">
                        <label class="form-check-label" for="payment_customer">
                            <strong>Mit Kundendaten</strong>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_type" id="payment_voucher" value="voucher" onchange="toggleCustomerForm()">
                        <label class="form-check-label" for="payment_voucher">
                            <strong>Gutschein</strong>
                        </label>
                    </div>
                </div>

                <div id="voucher-form" style="display: none;" class="mb-4">
                    <label for="voucher_code" class="form-label"><strong>Gutscheincode:</strong></label>
                    <div class="input-group" style="max-width: 320px;">
                        <span class="input-group-text"><i class="bi bi-gift"></i></span>
                        <input type="text" class="form-control text-uppercase" id="voucher_code" name="voucher_code"
                               placeholder="z.B. ABC123DEF456" autocomplete="off" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <small class="text-muted">Der Gutschein wird sofort als eingelöst markiert.</small>
                </div>
                
                <div id="customer-form" style="display: none;">
                    <div class="mb-3 position-relative">
                        <label for="customer-search" class="form-label"><i class="bi bi-search"></i> Kunde suchen</label>
                        <input type="text" class="form-control" id="customer-search" placeholder="Name oder E-Mail eingeben..." autocomplete="off">
                        <div id="customer-search-results" class="list-group shadow-sm position-absolute w-100" style="z-index: 1050; display: none; max-height: 320px; overflow-y: auto;"></div>
                    </div>
                    <h6 class="mb-3">Kundendaten</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="salutation" class="form-label">Anrede</label>
                            <select class="form-select" id="salutation" name="salutation">
                                <option value="">-</option>
                                <option value="Herr">Herr</option>
                                <option value="Frau">Frau</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="first_name" class="form-label">Vorname <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name">
                        </div>
                        <div class="col-md-5 mb-3">
                            <label for="last_name" class="form-label">Nachname <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="street" class="form-label">Straße <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="street" name="street">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="house_number" class="form-label">Hausnummer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="house_number" name="house_number">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="postal_code" class="form-label">PLZ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="postal_code" name="postal_code">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="city" class="form-label">Ort <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="city" name="city">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 mb-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="skip_email" name="skip_email" value="1" onchange="toggleEmailFields()">
                                <label class="form-check-label" for="skip_email">
                                    E-Mail-Eingabe überspringen <small class="text-muted">(falls der Kunde keine E-Mail-Adresse hat)</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3" id="email_group">
                            <label for="email" class="form-label">E-Mail <span class="text-danger email-required-mark">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                                   oninput="validateEmail()">
                            <div class="invalid-feedback" id="email-error"></div>
                        </div>
                        <div class="col-md-6 mb-3" id="email_confirm_group">
                            <label for="email_confirm" class="form-label">E-Mail bestätigen <span class="text-danger email-required-mark">*</span></label>
                            <input type="email" class="form-control" id="email_confirm" name="email_confirm" required
                                   pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                                   oninput="validateEmail()">
                            <div class="invalid-feedback" id="email_confirm-error"></div>
                            <small class="form-text text-muted">Bitte geben Sie die E-Mail-Adresse zur Bestätigung erneut ein.</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="phone_prefix" class="form-label">Telefon Vorwahl <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="phone_prefix" name="phone_prefix">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="phone_number" class="form-label">Telefonnummer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="phone_number" name="phone_number">
                        </div>
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <a href="' . url('admin/sell.php?step=seats&event_id=' . $eventId . '&date=' . $eventDate) . '" class="btn btn-outline-secondary">Zurück</a>
                    <button type="submit" name="create_order" class="btn btn-primary">
                        Bestellung abschließen <i class="bi bi-check-circle"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>';
}

$additionalCss = '
<link rel="stylesheet" href="' . url('libs/css/flatpickr.min.css') . '">
<link rel="stylesheet" href="' . assetUrl('libs/css/seatplan-fit.css') . '">
<script src="' . assetUrl('libs/js/seatplan-zoom.js') . '" defer></script>
<style>
.seatplan-container {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 0.5rem;
    margin: 1rem 0;
    width: 100%;
    overflow-x: visible;
}
.seatplan-row {
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
    gap: 0.25rem;
}
.row-label {
    min-width: 60px;
    font-weight: bold;
    text-align: right;
    font-size: 0.85rem;
}
.seatplan-elements {
    display: flex;
    flex-wrap: nowrap;
    gap: 0.25rem;
    flex: 1;
    overflow-x: visible;
}
.seat-element, .table-element, .distance-element, .stage-element {
    padding: 0.25rem 0.4rem;
    border-radius: 0.2rem;
    cursor: pointer;
    transition: all 0.2s;
    user-select: none;
    border: 4px solid transparent;
    font-size: 0.75rem;
    min-width: 28px;
    text-align: center;
    white-space: nowrap;
}
.seatplan-legend {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
    padding: 0.5rem 0;
}
.seatplan-legend .legend-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
}
.seatplan-legend .legend-box {
    display: inline-block;
    width: 22px;
    height: 22px;
    border-radius: 0.2rem;
    border: 1px solid rgba(0,0,0,.15);
    flex-shrink: 0;
}
.distance-element {
    background: #6c757d !important;
    color: white !important;
    border: 1px solid #6c757d !important;
    cursor: not-allowed !important;
    pointer-events: auto;
    width: 28px;
    min-width: 28px;
    flex-shrink: 0;
}
.distance-element:hover {
    transform: none !important;
    box-shadow: none !important;
}
.table-element {
    background: #6c757d !important;
    color: white !important;
    border: 1px solid #5a6268 !important;
}
.stage-element {
    background: #212529 !important;
    color: white !important;
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    font-weight: bold;
    text-align: center;
    border: none !important;
    cursor: default;
    margin: 0.5rem 0;
}
.seat-element {
    background: #28a745;
    color: white;
}
.seat-element:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.seat-element.reserved {
    background: #dc3545;
    color: white;
    border-color: #dc3545;
    cursor: not-allowed;
}
.seat-element.selected {
    background: #ffc107;
    color: #212529;
}
/* Tage des Folge-/Vormonats im Kalender schwarz anzeigen */
.flatpickr-day.nextMonthDay,
.flatpickr-day.prevMonthDay {
    color: #3d3d3d !important;
    opacity: 1 !important;
}
.flatpickr-day.nextMonthDay.flatpickr-disabled,
.flatpickr-day.prevMonthDay.flatpickr-disabled {
    color: #bbb !important;
    opacity: 1 !important;
}
</style>
';

$jsNsCategory = json_encode($noSeatplan['category'] ?? null);
$jsNsAvailable = (int)($noSeatplan['available'] ?? 0);
$jsNsReducedActive = !empty($noSeatplan['reduced_active']) ? 'true' : 'false';

// Seat plan sales: reduced price selectable per seat - same rule as the customer checkout.
// On a general rehearsal the rehearsal price applies, so no reduction is offered there.
$reducedAllowed = $event && Config::get('reduced_prices_active', false) && !empty($event['allow_reduced_prices']);
if ($reducedAllowed && !empty($eventDate)) {
    $reducedDateData = $eventModel->getDateByEventAndDate($eventId, $eventDate);
    if ($reducedDateData && (int)($reducedDateData['is_general_rehearsal'] ?? 0) === 1) {
        $reducedAllowed = false;
    }
}
$jsReducedAllowed = $reducedAllowed ? 'true' : 'false';
// Hint in step 2 when step 3 offers more than the normal price (reduced or FN card)
$fnCardAllowed = $event && Config::get('fn_card_active', false) && !empty($event['allow_fn_card']);
$jsPriceTypesNextStep = ($reducedAllowed || $fnCardAllowed) ? 'true' : 'false';
$jsOwnCartSeats = json_encode($ownCartSeats);

// After a failed order (e.g. FN card limit) the order form is shown again: refill it with
// what was entered, so customer data and choices are not lost
$postedOrder = null;
if (!empty($error) && isset($_POST['create_order'])) {
    $postedOrder = $_POST;
    unset($postedOrder['csrf_token'], $postedOrder['seats_json'], $postedOrder['create_order'], $postedOrder['event_id'], $postedOrder['event_date']);
}
$jsPostedOrder = json_encode($postedOrder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$additionalJs = <<<JS
<script src="{$flatpickrJs}"></script>
<script src="{$flatpickrDeJs}"></script>
<script>
let selectedSeats = [];
let allowedDates = {$allowedDatesJs};
let categories = {$categoriesJs};
let currentEventId = {$jsEventId};
let currentEventDate = {$jsEventDate};
let presalesFreeApi = {$jsPresalesFreeApi};
let presalesReserveApi = {$jsPresalesReserveApi};
let earliestDate = {$earliestDateJs};
let urlSell = {$urlSell};
let nsCategory = {$jsNsCategory};
let nsAvailable = {$jsNsAvailable};
let nsReducedActive = {$jsNsReducedActive};
let reducedAllowed = {$jsReducedAllowed};
let priceTypesNextStep = {$jsPriceTypesNextStep};
let ownCartSeats = {$jsOwnCartSeats};
let postedOrder = {$jsPostedOrder};

window.onerror = function(msg, url, line) {
    console.error("JS ERROR: " + msg + " in " + url + " line " + line);
    return false;
};

function presalesReserveSeat(seatId, categoryId) {
    if (!presalesReserveApi) return;
    var xhr = new XMLHttpRequest();
    xhr.open("POST", presalesReserveApi, true);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.send(JSON.stringify({
        event_id: currentEventId,
        event_date: currentEventDate,
        seat_identifier: seatId,
        category_id: categoryId
    }));
}

function presalesFreeSeat(seatId) {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", presalesFreeApi, true);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.send(JSON.stringify({
        event_id: currentEventId,
        event_date: currentEventDate,
        seat_identifier: seatId,
        reason: "presales_deselected"
    }));
}

function initDatePicker() {
    const dateInput = document.getElementById("date");
    if (!dateInput) return;
    
    if (typeof flatpickr !== "function") return;
    
    const flatpickrOptions = {
        // Internal value stays ISO so the form submit keeps the existing
        // backend contract (event_date is parsed as Y-m-d server-side).
        dateFormat: "Y-m-d",
        // Visible value uses the German calendar shorthand. flatpickr keeps
        // both inputs in sync automatically.
        altInput: true,
        altFormat: "d.m.Y",
        // Use the full German locale registered by flatpickr.de.js – this
        // covers weekdays, months, the "KW" week-abbreviation, the German
        // range separator, scroll/toggle tooltips and 24-hour time, all of
        // which the previous inline locale object missed.
        locale: "de",
        enableTime: false,
        minDate: "today",
        onChange: function(selectedDates, dateStr, instance) {
            const continueBtn = document.getElementById("continue-btn");
            if (continueBtn) {
                // Also require an event to be selected when a <select> is shown
                const eventSel = document.getElementById("event_id");
                const hasEvent = !eventSel || eventSel.tagName !== "SELECT" || !!eventSel.value;
                continueBtn.disabled = !dateStr || !hasEvent;
            }
        }
    };
    
    if (Array.isArray(allowedDates) && allowedDates.length > 0) {
        flatpickrOptions.enable = allowedDates;
        if (earliestDate) {
            // Pre-select the first upcoming date and jump to its month
            flatpickrOptions.defaultDate = earliestDate;
            flatpickrOptions.onReady = function(selectedDates, dateStr, instance) {
                // Jump to the month of the default date even if it differs from today
                instance.jumpToDate(earliestDate, true);
            };
        }
    }

    try {
        const fp = flatpickr(dateInput, flatpickrOptions);
        window.fp = fp; // Keep global for manual button

        // Enable continue button if a date was pre-selected
        if (earliestDate && dateInput.value) {
            const continueBtn = document.getElementById("continue-btn");
            if (continueBtn) continueBtn.disabled = false;
        }

        if (currentEventId > 0 && Array.isArray(allowedDates) && allowedDates.length > 0) {
            setTimeout(function() {
                fp.open();
            }, 600);
        }
    } catch (e) {
        console.error("Flatpickr Error:", e);
    }
    
    const eventSelect = document.getElementById("event_id");
    if (eventSelect && eventSelect.tagName === "SELECT") {
        eventSelect.addEventListener("change", function() {
            if (this.value) {
                window.location.href = urlSell + "?step=event&event_id=" + this.value;
            }
        });
    }
}

function loadEventDates() {
    const eventId = document.getElementById("event_id").value;
    if (!eventId) {
        const dateInput = document.getElementById("date");
        if (dateInput) {
            dateInput.value = "";
            const continueBtn = document.getElementById("continue-btn");
            if (continueBtn) continueBtn.disabled = true;
        }
        return;
    }
    
    window.location.href = urlSell + "?step=event&event_id=" + eventId;
}

// Tracks which wheelchair seat is currently waiting for a companion pick.
// null = no pending mode; otherwise contains the wheelchair seat_id.
var pendingCompanionFor = null;

function setPendingCompanionFor(wheelchairSeatId) {
    pendingCompanionFor = wheelchairSeatId;
    var banner = document.getElementById("companion-banner");
    if (!banner) return;
    if (wheelchairSeatId) {
        banner.classList.replace("d-none", "d-flex"); // d-flex is !important, so toggle classes instead of style.display
        banner.querySelector(".banner-text").textContent =
            "Bitte einen freien Sitz als Begleitperson für den Rollstuhlplatz anklicken (oder Auswahl abbrechen).";
    } else {
        banner.classList.replace("d-flex", "d-none");
    }
}

function cancelCompanionMode() {
    setPendingCompanionFor(null);
}

function toggleSeat(element) {
    const seatId = element.dataset.seatId;
    const isWheelchair = element.dataset.wheelchair === "1";
    const index = selectedSeats.findIndex(function(s) { return s.id === seatId; });

    // If we're in companion-pick mode and the user clicks any free non-wheelchair seat, treat it as picking the companion.
    if (pendingCompanionFor && !isWheelchair && index === -1) {
        const wheelchairId = pendingCompanionFor;
        const categoryId = element.dataset.category ? parseInt(element.dataset.category) : null;
        selectedSeats.push({
            id: seatId,
            row: element.dataset.row,
            seat: element.dataset.seat,
            category: categoryId,
            price: 0,
            is_companion: true,
            parent_wheelchair: wheelchairId
        });
        element.classList.add("selected");
        element.style.backgroundColor = "#17a2b8";
        element.style.color = "white";
        element.title = "Begleitperson (kostenfrei)";
        presalesReserveSeat(seatId, categoryId);
        setPendingCompanionFor(null);
        updateSelection();
        return;
    }

    if (index > -1) {
        // Deselect
        const wasCompanion = !!selectedSeats[index].is_companion;
        const wasWheelchair = !!selectedSeats[index].is_wheelchair;
        selectedSeats.splice(index, 1);
        element.classList.remove("selected");
        const categoryId = element.dataset.category ? parseInt(element.dataset.category) : null;
        const category = categoryId ? categories.find(function(c) { return c.id == categoryId; }) : null;
        const categoryColor = category ? (category.color || "#0d6efd") : "#0d6efd";
        element.style.borderColor = categoryColor;
        element.style.backgroundColor = "#28a745";
        element.style.color = "white";
        element.title = "Verfügbar";
        presalesFreeSeat(seatId);

        // If a wheelchair was deselected, also free its companion (if any)
        if (wasWheelchair) {
            const compIdx = selectedSeats.findIndex(function(s) { return s.is_companion && s.parent_wheelchair === seatId; });
            if (compIdx > -1) {
                const compSeatId = selectedSeats[compIdx].id;
                selectedSeats.splice(compIdx, 1);
                const compEl = document.querySelector('[data-seat-id="' + compSeatId + '"]');
                if (compEl) {
                    compEl.classList.remove("selected");
                    compEl.style.backgroundColor = "#28a745";
                    compEl.style.color = "white";
                    compEl.title = "Verfügbar";
                }
                presalesFreeSeat(compSeatId);
            }
            // If we were in companion-pick mode for THIS wheelchair, cancel
            if (pendingCompanionFor === seatId) setPendingCompanionFor(null);
        }
    } else {
        // Select
        const categoryId = element.dataset.category ? parseInt(element.dataset.category) : null;
        const category = categoryId ? categories.find(function(c) { return c.id == categoryId; }) : null;
        const seatEntry = {
            id: seatId,
            row: element.dataset.row,
            seat: element.dataset.seat,
            category: categoryId,
            category_price: category ? parseFloat(category.price) : 0,
            price: category ? parseFloat(category.price) : 0
        };
        if (isWheelchair) seatEntry.is_wheelchair = true;
        selectedSeats.push(seatEntry);
        element.classList.add("selected");
        element.style.backgroundColor = "#ffc107";
        element.style.color = "#212529";
        presalesReserveSeat(seatId, categoryId);
    }

    updateSelection();
}

function startCompanionPick(wheelchairSeatId) {
    setPendingCompanionFor(wheelchairSeatId);
}

function removeCompanionFor(wheelchairSeatId) {
    const compIdx = selectedSeats.findIndex(function(s) { return s.is_companion && s.parent_wheelchair === wheelchairSeatId; });
    if (compIdx === -1) return;
    const compSeatId = selectedSeats[compIdx].id;
    selectedSeats.splice(compIdx, 1);
    const compEl = document.querySelector('[data-seat-id="' + compSeatId + '"]');
    if (compEl) {
        compEl.classList.remove("selected");
        compEl.style.backgroundColor = "#28a745";
        compEl.style.color = "white";
        compEl.title = "Verfügbar";
    }
    presalesFreeSeat(compSeatId);
    updateSelection();
}

// Reduced price of a selected seat-plan seat, or null when no reduction can be offered
function reducedPriceFor(seat) {
    if (!reducedAllowed || seat.is_companion || seat.label) return null;
    var category = seat.category ? categories.find(function(c) { return c.id == seat.category; }) : null;
    var price = category && category.reduced_price ? parseFloat(category.reduced_price) : 0;
    return price > 0 ? price : null;
}

function updateSelection() {
    const container = document.getElementById("selected-seats");
    const totalPriceEl = document.getElementById("total-price");
    const continueBtn = document.getElementById("continue-btn");
    const countBadge = document.getElementById("selected-seats-count");

    if (countBadge) {
        if (selectedSeats.length > 0) {
            countBadge.textContent = selectedSeats.length;
            countBadge.style.display = "";
        } else {
            countBadge.style.display = "none";
        }
    }

    if (selectedSeats.length === 0) {
        container.innerHTML = "<p class=\"text-muted small\">Keine Plätze ausgewählt</p>";
        totalPriceEl.innerHTML = "<strong>Gesamt: 0,00 €</strong>";
        if (continueBtn) continueBtn.disabled = true;
    } else {
        var html = "";
        var total = 0;
        
        selectedSeats.forEach(function(seat, idx) {
            total += seat.price;
            var seatName = seat.label ? seat.label : ("Reihe " + seat.row + ", Platz " + seat.seat);
            if (seat.is_wheelchair) seatName = '<i class="bi bi-person-wheelchair"></i> ' + seatName + " (Rollstuhl)";
            if (seat.is_companion) seatName += " <span class=\"badge bg-info\">Begleitperson</span>";
            if (seat.is_reduced) seatName += " <span class=\"badge bg-secondary\">erm.</span>";
            var priceLabel = seat.is_companion ? "0,00 € (gratis)" : seat.price.toFixed(2) + " €";

            html += "<div class=\"d-flex justify-content-between align-items-center mb-1\">" +
                "<span>" + seatName + "</span>" +
                "<span>" + priceLabel + "</span>" +
                "</div>";

            // For wheelchair seats: show "+ Begleitperson" or "Begleitperson entfernen"
            if (seat.is_wheelchair) {
                var hasCompanion = selectedSeats.some(function(s) { return s.is_companion && s.parent_wheelchair === seat.id; });
                var seatIdJs = seat.id.replace(/'/g, "\\'");
                if (hasCompanion) {
                    html += "<div class=\"mb-2\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger w-100\" onclick=\"removeCompanionFor('" + seatIdJs + "')\"><i class=\"bi bi-x-circle\"></i> Begleitperson entfernen</button></div>";
                } else {
                    var isPending = (pendingCompanionFor === seat.id);
                    if (isPending) {
                        html += "<div class=\"mb-2\"><button type=\"button\" class=\"btn btn-sm btn-warning w-100\" onclick=\"cancelCompanionMode()\"><i class=\"bi bi-x-lg\"></i> Auswahl abbrechen</button></div>";
                    } else {
                        html += "<div class=\"mb-2\"><button type=\"button\" class=\"btn btn-sm btn-outline-info w-100\" onclick=\"startCompanionPick('" + seatIdJs + "')\"><i class=\"bi bi-person-plus\"></i> + Begleitperson</button></div>";
                    }
                }
            }

            html += "<input type=\"hidden\" name=\"seats[]\" value=\"" + JSON.stringify(seat).replace(/"/g, "&quot;") + "\">";
        });
        
        // Price types (reduced, FN card, ...) are chosen per seat in the next step
        if (priceTypesNextStep && selectedSeats.some(function(s) { return !s.label; })) {
            html += '<div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Ermäßigungen und FN-Card werden im nächsten Schritt pro Platz gewählt.</div>';
        }
        container.innerHTML = html;
        totalPriceEl.innerHTML = "<strong>Gesamt: " + total.toFixed(2) + " €</strong>";
        if (continueBtn) continueBtn.disabled = false;
    }
}

// Events without a seat plan: build the selectedSeats array from a quantity input.
// Reuses the existing sidebar / continue button / form-submit (seats[]) pipeline.
function updateNoSeatplanSelection() {
    var qtyEl = document.getElementById("ns-quantity");
    if (!qtyEl) return;

    var qty = parseInt(qtyEl.value, 10) || 0;
    if (qty < 0) { qty = 0; }
    if (qty > nsAvailable) { qty = nsAvailable; qtyEl.value = nsAvailable; }

    var reducedEl = document.getElementById("ns-reduced-count");
    var reducedCount = reducedEl ? (parseInt(reducedEl.value, 10) || 0) : 0;
    if (reducedCount < 0) { reducedCount = 0; }
    if (reducedCount > qty) { reducedCount = qty; if (reducedEl) reducedEl.value = qty; }

    var fullPrice = nsCategory ? parseFloat(nsCategory.price) : 0;
    var reducedPrice = (nsCategory && nsCategory.reduced_price) ? parseFloat(nsCategory.reduced_price) : fullPrice;
    var ts = Math.floor(Date.now() / 1000);

    selectedSeats = [];
    for (var i = 0; i < qty; i++) {
        var isReduced = i < reducedCount;
        selectedSeats.push({
            id: "no_seatplan_" + ts + "_" + i + "_" + (Math.floor(Math.random() * 9000) + 1000),
            label: "Ticket " + (i + 1),
            category: nsCategory ? nsCategory.id : null,
            category_price: fullPrice,
            price: isReduced ? reducedPrice : fullPrice,
            is_reduced: isReduced ? 1 : 0
        });
    }

    updateSelection();
}

var requiredCustomerFields = ['first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'email', 'email_confirm', 'phone_prefix', 'phone_number'];

function setCustomerFieldsRequired(required) {
    var skipEmail = document.getElementById('skip_email');
    var skipping = skipEmail && skipEmail.checked;
    requiredCustomerFields.forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (skipping && (id === 'email' || id === 'email_confirm')) {
            el.required = false;
        } else {
            el.required = required;
        }
    });
}

function toggleEmailFields() {
    var skipEmail = document.getElementById('skip_email');
    if (!skipEmail) return;
    var skip = skipEmail.checked;
    var emailEl = document.getElementById('email');
    var confirmEl = document.getElementById('email_confirm');
    [emailEl, confirmEl].forEach(function(el) {
        if (!el) return;
        el.disabled = skip;
        el.required = !skip;
        if (skip) {
            el.value = '';
            el.classList.remove('is-invalid', 'is-valid');
        }
    });
    document.querySelectorAll('.email-required-mark').forEach(function(m) {
        m.style.display = skip ? 'none' : '';
    });
}

function togglePaymentOptions() {
    // Only step 3 (order form) has these fields; the function also runs on page load of every step
    const orderTypeInput = document.querySelector("input[name=\"order_type\"]:checked");
    if (!orderTypeInput) return;
    const orderType = orderTypeInput.value;
    const paymentOptions = document.getElementById("payment-options");

    if (orderType === "reserve") {
        paymentOptions.style.display = "none";
        document.getElementById("payment_customer").checked = true;
        document.getElementById("customer-form").style.display = "block";
        setCustomerFieldsRequired(true);
    } else {
        paymentOptions.style.display = "block";
        toggleCustomerForm();
    }
}

function toggleCustomerForm() {
    const paymentTypeInput = document.querySelector("input[name=\"payment_type\"]:checked");
    if (!paymentTypeInput) return;
    const paymentType = paymentTypeInput.value;
    const customerForm = document.getElementById("customer-form");
    const voucherForm = document.getElementById("voucher-form");

    if (paymentType === "customer") {
        customerForm.style.display = "block";
        setCustomerFieldsRequired(true);
    } else {
        customerForm.style.display = "none";
        setCustomerFieldsRequired(false);
    }
    if (voucherForm) {
        voucherForm.style.display = (paymentType === "voucher") ? "block" : "none";
    }
}

function validateEmail() {
    const skipEmail = document.getElementById("skip_email");
    if (skipEmail && skipEmail.checked) return true; // skipped — nothing to validate

    const emailInput = document.getElementById("email");
    const emailConfirmInput = document.getElementById("email_confirm");
    const emailError = document.getElementById("email-error");
    const emailConfirmError = document.getElementById("email_confirm-error");

    if (!emailInput || !emailConfirmInput) return;

    const email = emailInput.value.trim();
    const emailConfirm = emailConfirmInput.value.trim();
    
    // Reset previous errors
    emailInput.classList.remove("is-invalid", "is-valid");
    emailConfirmInput.classList.remove("is-invalid", "is-valid");
    if (emailError) emailError.textContent = "";
    if (emailConfirmError) emailConfirmError.textContent = "";
    
    // Validate email format
    const emailRegex = /^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i;
    let isValid = true;
    
    if (email && !emailRegex.test(email)) {
        emailInput.classList.add("is-invalid");
        if (emailError) emailError.textContent = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        isValid = false;
    } else if (email) {
        emailInput.classList.add("is-valid");
    }
    
    // Validate email confirmation
    if (emailConfirm) {
        if (email !== emailConfirm) {
            emailConfirmInput.classList.add("is-invalid");
            if (emailConfirmError) emailConfirmError.textContent = "Die E-Mail-Adressen stimmen nicht überein.";
            isValid = false;
        } else if (email && emailRegex.test(email)) {
            emailConfirmInput.classList.add("is-valid");
        }
    }
    
    return isValid;
}

// Put this session's cart seats (still reserved in the database) back into the selection,
// with their price type - without reserving them a second time
function restoreOwnCartSeats() {
    if (!Array.isArray(ownCartSeats) || ownCartSeats.length === 0) return;
    const wheelchair = ownCartSeats.find(function(r) { return r.seat_identifier.indexOf("wheelchair_") === 0; });
    ownCartSeats.forEach(function(r) {
        const el = document.querySelector('[data-seat-id="' + r.seat_identifier + '"]');
        if (!el || selectedSeats.some(function(s) { return s.id === r.seat_identifier; })) return;
        const categoryId = el.dataset.category ? parseInt(el.dataset.category) : null;
        const category = categoryId ? categories.find(function(c) { return c.id == categoryId; }) : null;
        const fullPrice = category ? parseFloat(category.price) : 0;
        const entry = { id: r.seat_identifier, row: el.dataset.row, seat: el.dataset.seat, category: categoryId, category_price: fullPrice, price: fullPrice };
        if (el.dataset.wheelchair === "1") entry.is_wheelchair = true;
        if (r.is_companion) {
            entry.is_companion = true;
            entry.price = 0;
            entry.parent_wheelchair = wheelchair ? wheelchair.seat_identifier : null;
        } else if (r.is_reduced && reducedPriceFor(entry) !== null) {
            entry.is_reduced = 1;
            entry.price = reducedPriceFor(entry);
        } else if (r.is_fn_card) {
            entry.is_fn_card = 1;
            entry.fn_card_number = r.fn_card_number || null;
        }
        selectedSeats.push(entry);
        el.classList.add("selected");
        el.style.backgroundColor = r.is_companion ? "#17a2b8" : "#ffc107";
        el.style.color = r.is_companion ? "white" : "#212529";
        el.setAttribute("onclick", "toggleSeat(this)");
    });
    updateSelection();
}

// Refill the order form after a failed submit (see postedOrder)
function restorePostedOrder() {
    const form = document.getElementById("order-form");
    if (!postedOrder || !form) return;
    const setField = function(name, value) {
        form.querySelectorAll('[name="' + name + '"]').forEach(function(el) {
            if (el.type === "radio" || el.type === "checkbox") {
                el.checked = String(el.value) === String(value);
            } else if (el.type !== "hidden" && el.type !== "submit") {
                el.value = value;
            }
        });
    };
    Object.keys(postedOrder).forEach(function(key) {
        const value = postedOrder[key];
        if (value !== null && typeof value === "object") {
            Object.keys(value).forEach(function(k) { setField(key + "[" + k + "]", value[k]); });
        } else {
            setField(key, value);
        }
    });
    if (typeof updatePriceChoices === "function") updatePriceChoices();
    if (typeof toggleEmailFields === "function") toggleEmailFields();
}

window.addEventListener("DOMContentLoaded", function() {
    restoreOwnCartSeats();
    restorePostedOrder();
    initDatePicker();
    togglePaymentOptions();
    
    // Add form validation for email
    const orderForm = document.getElementById("order-form");
    if (orderForm) {
        orderForm.addEventListener("submit", function(e) {
            if (!validateEmail()) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });
    }

    // Customer search setup
    const customerSearchInput = document.getElementById("customer-search");
    const customerSearchResults = document.getElementById("customer-search-results");

    if (customerSearchInput) {
        let searchTimer = null;

        customerSearchInput.addEventListener("input", function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                runCustomerSearch(customerSearchInput.value.trim());
            }, 300);
        });

        document.addEventListener("click", function(e) {
            if (!customerSearchInput.contains(e.target) && !customerSearchResults.contains(e.target)) {
                customerSearchResults.style.display = "none";
            }
        });
    }

    // Also trigger search from first_name / last_name fields
    const firstNameInput = document.getElementById("first_name");
    const lastNameInput = document.getElementById("last_name");
    let nameSearchTimer = null;

    function triggerNameSearch() {
        // Don't fire if the dedicated search box already has a query running
        if (customerSearchInput && customerSearchInput.value.trim().length >= 2) return;
        clearTimeout(nameSearchTimer);
        nameSearchTimer = setTimeout(function() {
            const q = ((firstNameInput ? firstNameInput.value : "") + " " + (lastNameInput ? lastNameInput.value : "")).trim();
            if (q.length >= 2) runCustomerSearch(q);
        }, 400);
    }

    if (firstNameInput) firstNameInput.addEventListener("input", triggerNameSearch);
    if (lastNameInput) lastNameInput.addEventListener("input", triggerNameSearch);
});

const customerSearchApi = {$jsCustomerSearchApi};

function runCustomerSearch(query) {
    const resultsEl = document.getElementById("customer-search-results");
    if (!resultsEl) return;

    if (query.length < 2) {
        resultsEl.style.display = "none";
        return;
    }

    fetch(customerSearchApi + "?q=" + encodeURIComponent(query))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.results || data.results.length === 0) {
                resultsEl.style.display = "none";
                return;
            }

            resultsEl.innerHTML = "";
            data.results.forEach(function(c) {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "list-group-item list-group-item-action py-2";
                btn.innerHTML =
                    "<strong>" + escHtml((c.first_name + " " + c.last_name).trim()) + "</strong>" +
                    (c.email ? " <small class=\"text-muted\">– " + escHtml(c.email) + "</small>" : "") +
                    "<br><small class=\"text-muted\">" +
                    escHtml([c.street, c.house_number, c.postal_code, c.city].filter(Boolean).join(" ")) +
                    "</small>";
                btn.addEventListener("click", function() {
                    fillCustomerForm(c);
                    resultsEl.style.display = "none";
                    const searchInput = document.getElementById("customer-search");
                    if (searchInput) searchInput.value = (c.first_name + " " + c.last_name).trim();
                });
                resultsEl.appendChild(btn);
            });
            resultsEl.style.display = "block";
        })
        .catch(function() {});
}

function fillCustomerForm(c) {
    const map = {
        salutation: c.salutation || "",
        first_name: c.first_name || "",
        last_name: c.last_name || "",
        street: c.street || "",
        house_number: c.house_number || "",
        postal_code: c.postal_code || "",
        city: c.city || "",
        email: c.email || "",
        email_confirm: c.email || "",
        phone_prefix: c.phone_prefix || "",
        phone_number: c.phone_number || ""
    };
    Object.keys(map).forEach(function(field) {
        const el = document.getElementById(field);
        if (el) el.value = map[field];
    });
}

function escHtml(str) {
    const d = document.createElement("div");
    d.appendChild(document.createTextNode(str || ""));
    return d.innerHTML;
}
</script>
JS;

require __DIR__ . '/../../app/views/layouts/main.php';
