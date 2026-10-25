<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\SeatPlan;
use App\Models\Voucher;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();

// Prevent caching - checkout should never be served from cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Server-side reservation timeout check
if (Session::isReservationExpired()) {
    // Clean up expired reservations
    $reservationModel = new Reservation();
    $expiredItems = $reservationModel->getBySession(session_id());
    foreach ($expiredItems as $item) {
        if ((int)$item['status_id'] === 1) {
            $reservationModel->delete($item['id']);
        }
    }
    Session::clearReservationTimeout();
    header('Location: ' . url('cart_timeout.php'));
    exit;
}

$sessionId = session_id();

// The customer is actively in checkout -> restart the expiry clock for seats and
// cart vouchers so the cleanup (triggered from other users' requests) cannot
// delete them while the payment is still in progress. Keeps the DB clocks and the
// session countdown in sync. Seats another session has taken over meanwhile are
// dropped from the cart and reported below instead of being silently revived.
$touchResult = (new Reservation())->touchCartItems($sessionId);
(new Voucher())->touchCartItems($sessionId);
Session::resetReservationTimeout();

$seatConflicts = array_column($touchResult['conflicts'], 'seat');

// Log that user entered checkout
if (function_exists('logPaymentStarted')) {
    $userId = $_SESSION['user_id'] ?? null;
    $reservationModel = new Reservation();
    $cartItems = $reservationModel->getBySession($sessionId);
    $totalItems = count($cartItems);
    logPaymentStarted($sessionId, 'checkout_started', 0, 'EUR', $userId);
}

$reservationModel = new Reservation();
$customerModel = new Customer();
$eventModel = new Event();
$categoryModel = new SeatCategory();
$seatPlanModel = new SeatPlan();
$voucherModel = new Voucher();

$fnCardActive = Config::get('fn_card_active', false);
$fnCardFactor = (float)Config::get('fn_card_factor', 0.1);
$reducedPricesActive = Config::get('reduced_prices_active', false);

$cartItems = $reservationModel->getBySession($sessionId);

// Check if all event dates in cart are still bookable
foreach ($cartItems as $item) {
    $event = $eventModel->getById($item['event_id']);
    if ($event) {
        $maxReservationTime = $event['max_reservation_time'] ?? '12:00:00';
        $bookableCheck = isEventDateBookable($item['event_date'], $maxReservationTime);
        if (!$bookableCheck['bookable']) {
            // Remove expired items from cart
            $reservationModel->delete($item['id']);
        }
    }
}

// Re-fetch cart items after cleanup
$cartItems = $reservationModel->getBySession($sessionId);

// Price type chosen per seat at checkout (radio buttons pricetype_<id>): store it before any
// price is calculated - otherwise a chosen reduction only changed the displayed price
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedPriceTypes = [];
    foreach ($_POST as $key => $value) {
        if (preg_match('/^pricetype_(\d+)$/', (string)$key, $m)) {
            $postedPriceTypes[(int)$m[1]] = (string)$value;
        }
    }
    // FN card chosen but number not validated -> the order is not submitted (error below)
    if ($postedPriceTypes && $reservationModel->unvalidatedFnCardItems($cartItems, $postedPriceTypes)) {
        $priceTypeError = 'Bitte die FN-Card-Nummer mit „Prüfen“ bestätigen – sonst kann der FN-Card-Preis nicht berechnet werden.';
    }
    if ($postedPriceTypes && $reservationModel->applyCustomerPriceTypes($cartItems, $postedPriceTypes) > 0) {
        $cartItems = $reservationModel->getBySession($sessionId);
    }
}
// Get vouchers to BUY (purchase_intent = 'buy') - these are items to purchase
$cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');

// Gutscheine für vorüber gegangene Veranstaltungen können nicht mehr gekauft werden
$removedFinishedVouchers = 0;
foreach ($cartVouchersToBuy as $voucherItem) {
    $voucherEvent = $eventModel->getById((int)$voucherItem['event_id']);
    if ($voucherEvent && !empty($voucherEvent['is_finished'])) {
        $voucherModel->delete((int)$voucherItem['id']);
        $removedFinishedVouchers++;
    }
}
if ($removedFinishedVouchers > 0) {
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
}

// Get vouchers to REDEEM (purchase_intent = 'redeem') - these are discounts
$cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');

// Redirect to cart if cart is completely empty (no items AND no vouchers)
if (empty($cartItems) && empty($cartVouchersToBuy) && empty($cartVouchersToRedeem)) {
    header('Location: ' . url('cart.php'));
    exit;
}

$error = $_GET['error'] ?? '';
if ($removedFinishedVouchers > 0) {
    $error = 'Gutscheine für bereits vergangene Veranstaltungen wurden aus dem Warenkorb entfernt.';
}
$success = false;
$paypalActive = Config::get('paypal_active', false);
$customerAllowPaypal = Config::get('customer_allow_paypal', true);
$customerAllowReservation = Config::get('customer_allow_reservation', false);
$customerAllowCash = Config::get('customer_allow_cash', false);

// Calculate total price and voucher discount BEFORE POST processing
$groupedItemsTemp = [];
$totalPriceCalc = 0;

foreach ($cartItems as $item) {
    $category = null;
    if ($item['category_id']) {
        $category = $categoryModel->getById($item['category_id']);
    }
    
    // Same rule as the PayPal charge (App\Helpers\TicketPrice)
    $price = \App\Helpers\TicketPrice::forReservation($item)['price'];
    
    $totalPriceCalc += $price;
}

// Add vouchers to BUY to total price
foreach ($cartVouchersToBuy as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $totalPriceCalc += ($price * $quantity);
}

// Calculate voucher discount from REDEEMED vouchers
$voucherDiscountCalc = 0;
foreach ($cartVouchersToRedeem as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $voucherDiscountCalc += ($price * $quantity);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get selected payment method
    $paymentMethod = $_POST['payment_method'] ?? 'paypal';

    // Validate payment method is allowed for customers
    if ($paymentMethod === 'paypal' && !$customerAllowPaypal) {
        $error = 'PayPal-Zahlung ist für Kunden nicht verfügbar.';
    } elseif ($paymentMethod === 'reservation' && !$customerAllowReservation) {
        $error = 'Online-Reservierung ist nicht verfügbar.';
    } elseif ($paymentMethod === 'cash' && !$customerAllowCash) {
        $error = 'Barzahlung ist nicht verfügbar.';
    }
    
    if (!$error && !empty($priceTypeError)) {
        $error = $priceTypeError;
    }

    // Validate form data (customer data required unless cash payment without customer requirement)
    $customerDataRequired = !($paymentMethod === 'cash' && !$customerAllowCash);
    if ($customerDataRequired) {
        $required = ['first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'email'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
                break;
            }
        }
        
        // Validate email format
        if (!$error && !empty($_POST['email'])) {
            $email = trim($_POST['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
        }
        
        // Validate email confirmation (if provided)
        if (!$error && !empty($_POST['email']) && isset($_POST['email_confirm'])) {
            $email = trim($_POST['email']);
            $emailConfirm = trim($_POST['email_confirm']);
            if ($email !== $emailConfirm) {
                $error = 'Die E-Mail-Adressen stimmen nicht überein. Bitte überprüfen Sie Ihre Eingabe.';
            }
        }
    }
    
    if (!$error) {
        try {
            // Create or update customer (if customer data provided)
            // For PayPal, we MUST save customer data before redirecting
            $customerId = null;
            $shouldSaveCustomer = false;
            
            // Save customer data if:
            // 1. Customer data is required (not cash payment without requirement), OR
            // 2. PayPal payment (we need customer data for email)
            if ($paymentMethod === 'paypal' && $paypalActive && $customerAllowPaypal) {
                $shouldSaveCustomer = true; // Always save for PayPal
            } elseif ($customerDataRequired) {
                $shouldSaveCustomer = true;
            }
            
            if ($shouldSaveCustomer && !empty($_POST['first_name'])) {
                $customer = $customerModel->getBySession($sessionId, 'ticket');
                if ($customer) {
                    $customerModel->update($customer['id'], $_POST);
                    $customerId = $customer['id'];
                } else {
                    $customerId = $customerModel->createOrGet(array_merge($_POST, [
                        'session_id' => $sessionId,
                        'item_type' => 'ticket'
                    ]));
                }
            }
            
            // Assign REDEEMED vouchers to reservations (distribute evenly or to first reservations)
            $voucherIds = array_column($cartVouchersToRedeem, 'id');
            $voucherIndex = 0;
            
            // Update reservations with customer ID and voucher_id (only if there are reservations)
            if (!empty($cartItems)) {
                foreach ($cartItems as $item) {
                    $updateData = [
                        'customer_id' => $customerId
                    ];
                    
                    // Assign voucher if available (one voucher per reservation, round-robin)
                    if (!empty($voucherIds) && $voucherIndex < count($voucherIds)) {
                        $updateData['voucher_id'] = $voucherIds[$voucherIndex];
                        $voucherIndex++;
                    }
                    
                    $reservationModel->update($item['id'], $updateData);
                }
            }
            
            // Mark ALL vouchers (buy + redeem) as assigned to customer
            $allCartVouchers = array_merge($cartVouchersToBuy, $cartVouchersToRedeem);
            foreach ($allCartVouchers as $voucher) {
                $voucherModel->update($voucher['id'], [
                    'customer_id' => $customerId
                ]);
            }
            
            // Determine status based on payment method
            $finalTotal = $totalPriceCalc - $voucherDiscountCalc;
            $statusId = 1; // Default: in cart
            
            // Determine status based on payment method and situation
            // If final total is 0 (fully covered by vouchers OR only vouchers to buy with 0€ price)
            if ($finalTotal <= 0) {
                // Fully covered by vouchers - no payment needed
                $statusId = 8; // Paid with voucher
                $paidReservationIds = [];
                
                // Get customer data (should be saved already)
                $savedCustomer = null;
                if ($customerId) {
                    $savedCustomer = $customerModel->getById($customerId);
                }
                
                // Reset voucher index for 0€ order processing
                $voucherIndex = 0;
                
                // Update reservations if any
                if (!empty($cartItems)) {
                    foreach ($cartItems as $item) {
                        $updateData = [
                            'status_id' => $statusId,
                            'customer_id' => $customerId
                        ];
                        
                        // Assign voucher if available (one voucher per reservation, round-robin)
                        if (!empty($voucherIds) && $voucherIndex < count($voucherIds)) {
                            $updateData['voucher_id'] = $voucherIds[$voucherIndex];
                            $voucherIndex++;
                        }
                        
                        $reservationModel->update($item['id'], $updateData);
                        $paidReservationIds[] = $item['id'];
                    }
                }
                
                // Mark REDEEMED vouchers as used (status_id = 3)
                foreach ($cartVouchersToRedeem as $voucher) {
                    $voucherModel->update($voucher['id'], [
                        'status_id' => 3, // Redeemed/used as discount
                        'customer_id' => $customerId
                    ]);
                    logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 3, 'checkout_direct', [
                        'voucher_id' => $voucher['id'],
                        'session_id' => substr(session_id(), 0, 32),
                    ]);
                }
                // Mark PURCHASED vouchers as paid (status_id = 2)
                $paidVoucherIds = [];
                foreach ($cartVouchersToBuy as $voucher) {
                    $voucherModel->update($voucher['id'], [
                        'status_id' => 2, // Paid
                        'customer_id' => $customerId
                    ]);
                    logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 2, 'checkout_direct', [
                        'voucher_id' => $voucher['id'],
                        'session_id' => substr(session_id(), 0, 32),
                    ]);
                    $paidVoucherIds[] = $voucher['id'];
                }
                
                // Send email if enabled and customer exists
                if (Config::get('email_enabled', true) && $savedCustomer && !empty($savedCustomer['email'])) {
                    try {
                        $emailService = new EmailService();
                        $customerName = trim(($savedCustomer['first_name'] ?? '') . ' ' . ($savedCustomer['last_name'] ?? ''));
                        
                        // Get updated reservations for email
                        $updatedReservations = [];
                        foreach ($paidReservationIds as $resId) {
                            $res = $reservationModel->getById($resId);
                            if ($res) {
                                $updatedReservations[] = $res;
                            }
                        }
                        
                        // Determine if we should send combined email or separate emails
                        $hasTickets = !empty($updatedReservations);
                        $hasVouchers = !empty($cartVouchersToBuy);
                        $hasMultipleVouchers = count($cartVouchersToBuy) > 1;
                        
                        if ($hasTickets && $hasVouchers) {
                            // Combined email for tickets + vouchers
                            $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                            $baseUrl = ensurePublicInUrl($baseUrl);
                            
                            // Generate ticket download link
                            $token = generateTicketDownloadToken($paidReservationIds);
                            $reservationIdsParam = implode(',', $paidReservationIds);
                            $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token;

                            // Generate voucher link(s)
                            $voucherLink = $baseUrl . '/vouchers.php';

                            // Check if vouchers use templates
                            $voucherDownloadLink = null;
                            if (!empty($cartVouchersToBuy)) {
                                $firstVoucher = reset($cartVouchersToBuy);
                                $voucherEvent = $eventModel->getById($firstVoucher['event_id']);
                                if ($voucherEvent && !empty($voucherEvent['voucher_use_template'])) {
                                    $voucherToken = generateVoucherDownloadToken($paidVoucherIds);
                                    $voucherIdsParam = implode(',', $paidVoucherIds);
                                    $voucherDownloadLink = $baseUrl . '/api/voucher_download.php?voucher_ids=' . $voucherIdsParam . '&token=' . $voucherToken;
                                }
                            }
                            
                            $firstReservation = reset($updatedReservations);
                            $eventIdForEmail = $firstReservation['event_id'] ?? null;
                            $emailService->sendCombinedPurchaseEmail(
                                $savedCustomer['email'],
                                $customerName ?: $savedCustomer['email'],
                                $updatedReservations,
                                $cartVouchersToBuy,
                                $ticketLink,
                                $voucherDownloadLink ?: $voucherLink,
                                false,
                                $eventIdForEmail
                            );
                        } elseif ($hasMultipleVouchers) {
                            // Single email for multiple vouchers
                            $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                            $baseUrl = ensurePublicInUrl($baseUrl);
                            
                            // Check if vouchers use templates
                            $voucherLink = $baseUrl . '/vouchers.php';
                            $voucherDownloadLink = null;
                            if (!empty($cartVouchersToBuy)) {
                                $firstVoucher = reset($cartVouchersToBuy);
                                $voucherEvent = $eventModel->getById($firstVoucher['event_id']);
                                if ($voucherEvent && !empty($voucherEvent['voucher_use_template'])) {
                                    $voucherToken = generateVoucherDownloadToken($paidVoucherIds);
                                    $voucherIdsParam = implode(',', $paidVoucherIds);
                                    $voucherDownloadLink = $baseUrl . '/api/voucher_download.php?voucher_ids=' . $voucherIdsParam . '&token=' . $voucherToken;
                                }
                            }

                            $emailService->sendVoucherEmail(
                                $savedCustomer['email'],
                                $customerName ?: $savedCustomer['email'],
                                $cartVouchersToBuy,
                                $voucherDownloadLink ?: $voucherLink
                            );
                        } elseif ($hasTickets) {
                            // Ticket email only
                            $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                            $baseUrl = ensurePublicInUrl($baseUrl);
                            $token = generateTicketDownloadToken($paidReservationIds);
                            $reservationIdsParam = implode(',', $paidReservationIds);
                            $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token;
                            
                            $firstReservation = reset($updatedReservations);
                            $eventIdForEmail = $firstReservation['event_id'] ?? null;
                            $emailService->sendTicketEmail(
                                $savedCustomer['email'],
                                $customerName ?: $savedCustomer['email'],
                                $updatedReservations,
                                $ticketLink,
                                $eventIdForEmail
                            );
                        } elseif ($hasVouchers) {
                            // Single voucher email
                            $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                            $baseUrl = ensurePublicInUrl($baseUrl);
                            
                            // Check if vouchers use templates
                            $voucherLink = $baseUrl . '/vouchers.php';
                            $voucherDownloadLink = null;
                            if (!empty($cartVouchersToBuy)) {
                                $firstVoucher = reset($cartVouchersToBuy);
                                $voucherEvent = $eventModel->getById($firstVoucher['event_id']);
                                if ($voucherEvent && !empty($voucherEvent['voucher_use_template'])) {
                                    $voucherToken = generateVoucherDownloadToken($paidVoucherIds);
                                    $voucherIdsParam = implode(',', $paidVoucherIds);
                                    $voucherDownloadLink = $baseUrl . '/api/voucher_download.php?voucher_ids=' . $voucherIdsParam . '&token=' . $voucherToken;
                                }
                            }

                            $emailService->sendVoucherEmail(
                                $savedCustomer['email'],
                                $customerName ?: $savedCustomer['email'],
                                $cartVouchersToBuy,
                                $voucherDownloadLink ?: $voucherLink
                            );
                        }
                    } catch (\Exception $emailException) {
                        error_log("Email error for 0€ order: " . $emailException->getMessage());
                    }
                }
                
                LoggerService::logCustomer(LoggerService::ACTION_PAYMENT_COMPLETED, [
                    'session_id'      => substr($sessionId, 0, 32),
                    'customer_id'     => $customerId,
                    'reservation_ids' => $paidReservationIds,
                    'amount'          => 0,
                    'currency'        => 'EUR',
                    'payment_method'  => 'voucher',
                    'new_status_id'   => 8,
                ]);

                // Build redirect URL with reservation and voucher IDs
                $redirectParams = [];
                if (!empty($paidReservationIds)) {
                    $redirectParams[] = 'reservation_ids=' . implode(',', $paidReservationIds);
                }
                if (!empty($paidVoucherIds)) {
                    $redirectParams[] = 'voucher_ids=' . implode(',', $paidVoucherIds);
                }
                $redirectUrl = url('checkout_success.php');
                if (!empty($redirectParams)) {
                    $redirectUrl .= '?' . implode('&', $redirectParams);
                }

                header('Location: ' . $redirectUrl);
                exit;
            } elseif ($paymentMethod === 'reservation') {
                // Customer reservation (status_id = 6)
                $paidReservationIds = [];
                foreach ($cartItems as $item) {
                    $reservationModel->update($item['id'], [
                        'status_id' => 6 // Reserved online
                    ]);
                    $paidReservationIds[] = $item['id'];
                }

                LoggerService::logCustomer('reservation_online', [
                    'session_id'      => substr($sessionId, 0, 32),
                    'customer_id'     => $customerId,
                    'reservation_ids' => $paidReservationIds,
                    'new_status_id'   => 6,
                ]);

                // Send reservation confirmation email. sendReservationEmail()
                // is the same helper presales uses for status 3 reservations
                // (see presales/sell.php). It composes content from the
                // email_reservation_subject/body config templates and falls
                // back to the event page as the link if none is supplied.
                // The result is passed to the success page (email=sent/failed)
                // so the customer sees when the mail did not go out and can
                // trigger a resend there.
                $emailStatus = null;
                if (Config::get('email_enabled', true) && $customerId) {
                    try {
                        $savedCustomer = $customerModel->getById($customerId);
                        if ($savedCustomer && !empty($savedCustomer['email'])) {
                            $updatedReservations = [];
                            foreach ($paidReservationIds as $resId) {
                                $res = $reservationModel->getById((int)$resId);
                                if ($res) {
                                    $updatedReservations[] = $res;
                                }
                            }
                            if (!empty($updatedReservations)) {
                                $emailService = new EmailService();
                                $customerName = trim(($savedCustomer['first_name'] ?? '') . ' ' . ($savedCustomer['last_name'] ?? ''));
                                $emailSent = $emailService->sendReservationEmail(
                                    $savedCustomer['email'],
                                    $customerName ?: $savedCustomer['email'],
                                    $updatedReservations,
                                    ''
                                );
                                $emailStatus = $emailSent ? 'sent' : 'failed';
                            }
                        }
                    } catch (\Exception $emailException) {
                        $emailStatus = 'failed';
                        error_log("Reservation email error: " . $emailException->getMessage());
                    }
                }

                // Redirect to success page
                $redirectParams = [];
                if (!empty($paidReservationIds)) {
                    $redirectParams[] = 'reservation_ids=' . implode(',', $paidReservationIds);
                }
                if ($emailStatus !== null) {
                    $redirectParams[] = 'email=' . $emailStatus;
                }
                $queryString = !empty($redirectParams) ? '?' . implode('&', $redirectParams) : '';
                header('Location: ' . url('checkout_success.php' . $queryString));
                exit;
            } elseif ($paymentMethod === 'cash') {
                // Cash payment (status_id = 5)
                $paidReservationIds = [];
                foreach ($cartItems as $item) {
                    $reservationModel->update($item['id'], [
                        'status_id' => 5 // Cash payment
                    ]);
                    $paidReservationIds[] = $item['id'];
                }

                LoggerService::logCustomer('payment_cash', [
                    'session_id'      => substr($sessionId, 0, 32),
                    'customer_id'     => $customerId,
                    'reservation_ids' => $paidReservationIds,
                    'new_status_id'   => 5,
                ]);

                // Redirect to success page
                $reservationIdsParam = !empty($paidReservationIds) ? '?reservation_ids=' . implode(',', $paidReservationIds) : '';
                header('Location: ' . url('checkout_success.php' . $reservationIdsParam));
                exit;
            } elseif ($paymentMethod === 'paypal' && $paypalActive && $customerAllowPaypal && $finalTotal > 0) {
                // PayPal payment is now handled by JavaScript SDK on the frontend
                // This code should not be reached - if it is, JavaScript is disabled or blocked
                throw new \Exception('PayPal-Zahlung muss über die PayPal-Buttons erfolgen. Bitte aktivieren Sie JavaScript.');
            } else {
                // No valid payment method available
                $errorMsg = 'Bezahlung nicht möglich. ';
                if ($paymentMethod === 'paypal' && !$paypalActive) {
                    $errorMsg .= 'PayPal ist deaktiviert. ';
                } elseif ($paymentMethod === 'paypal' && !$customerAllowPaypal) {
                    $errorMsg .= 'Online-Bezahlung ist für Kunden nicht verfügbar. ';
                }
                $errorMsg .= 'Bitte wenden Sie sich an den Vorverkauf.';
                throw new \Exception($errorMsg);
            }
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

// Group items by event and date
$groupedItems = [];
$totalPrice = 0;

foreach ($cartItems as $item) {
    $key = $item['event_id'] . '_' . $item['event_date'];
    if (!isset($groupedItems[$key])) {
        $event = $eventModel->getById($item['event_id']);
        $groupedItems[$key] = [
            'event' => $event,
            'date' => $item['event_date'],
            'items' => []
        ];
    }
    
    $category = $item['category_id'] ? $categoryModel->getById($item['category_id']) : null;

    // Same rule as the PayPal charge (App\Helpers\TicketPrice)
    $price = \App\Helpers\TicketPrice::forReservation($item)['price'];
    
    $groupedItems[$key]['items'][] = [
        'reservation' => $item,
        'category' => $category,
        'price' => $price
    ];
    
    $totalPrice += $price;
}

// Add vouchers to BUY to total price
foreach ($cartVouchersToBuy as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $totalPrice += ($price * $quantity);
}

// Calculate voucher discount from REDEEMED vouchers (subtract from total)
$voucherDiscount = 0;
foreach ($cartVouchersToRedeem as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $voucherDiscount += ($price * $quantity);
}
$totalPrice = max(0, $totalPrice - $voucherDiscount); // Ensure total doesn't go negative

$customer = $customerModel->getBySession($sessionId, 'ticket');

// Get PayPal Client ID for JavaScript integration
$paypalClientId = '';
if ($paypalActive && $customerAllowPaypal) {
    $paypalMode = Config::get('paypal_mode', 'sandbox');
    if ($paypalMode === 'live') {
        $paypalClientId = $_ENV['PAYPAL_LIVE_CLIENT_ID'] ?? Config::get('paypal_live_client_id', '');
    } else {
        $paypalClientId = $_ENV['PAYPAL_SANDBOX_CLIENT_ID'] ?? Config::get('paypal_sandbox_client_id', '');
    }
}

$pageTitle = 'Kasse';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Ticket System</title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <?php if ($paypalActive && $customerAllowPaypal && !empty($paypalClientId)): ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($paypalClientId) ?>&currency=EUR&locale=de_DE"></script>
    <?php endif; ?>
</head>
<body>
    <?php
    use App\Core\Auth;
    $isLoggedIn = Auth::check();
    $userRole = Auth::role();
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?= url('') ?>">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </a>
            <div class="ms-auto d-flex gap-2">
                <?php if ($isLoggedIn && $userRole === 'presales'): ?>
                    <a href="<?= url('presales/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Vorverkauf
                    </a>
                <?php elseif ($isLoggedIn && $userRole === 'admin'): ?>
                    <a href="<?= url('admin/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Admin
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <?php $companyLogo = Config::get('company_logo', ''); if ($companyLogo): ?>
    <div style="position: fixed; top: 70px; right: 50px; z-index: 1000; pointer-events: none;">
        <img src="<?= url($companyLogo) ?>" alt="Logo" style="height: 100px; max-width: 250px; object-fit: contain;">
    </div>
    <?php endif; ?>

    <div class="container mt-5 mb-5">
        <div class="row mb-4">
            <div class="col-12">
                <h1>Kasse</h1>
            </div>
        </div>

        <?php if ($success): ?>
            <?php
            $successMessage = Config::get('checkout_success_message', 'Ihre Bestellung wurde erfolgreich aufgenommen!');
            // Get paid reservations for ticket download link
            $paidReservations = array_filter($cartItems, function($item) {
                return (int)($item['status_id'] ?? 0) === 2;
            });
            ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <?php if (!empty($paidReservations)): ?>
                    <?php
                    $reservationIdsParam = implode(',', array_column($paidReservations, 'id'));
                    $token = generateTicketDownloadToken(array_column($paidReservations, 'id'));
                    ?>
                    <div class="mt-3">
                        <a href="<?= url('api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token) ?>" class="btn btn-primary" target="_blank">
                            <i class="bi bi-download"></i> Tickets herunterladen
                        </a>
                    </div>
                <?php endif; ?>
                <div class="mt-2">
                    <a href="<?= url('') ?>" class="alert-link">Zur Startseite</a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($seatConflicts)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= count($seatConflicts) === 1 ? 'Folgender Platz wurde' : 'Folgende Plätze wurden' ?>
                    inzwischen anderweitig vergeben und aus Ihrem Warenkorb entfernt:
                    <strong><?= htmlspecialchars(implode(', ', $seatConflicts)) ?></strong>.
                    Bitte prüfen Sie Ihre Bestellung.
                </div>
            <?php endif; ?>

            <form method="POST">
            <!-- 1. Bestellübersicht -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Bestellübersicht</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($cartItems)): ?>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Anzahl Plätze:</span>
                                <strong><?= count($cartItems) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($cartVouchersToBuy)): ?>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Anzahl Gutscheine (zu kaufen):</span>
                                <strong><?= count($cartVouchersToBuy) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($cartVouchersToRedeem)): ?>
                            <div class="d-flex justify-content-between mb-2 text-success">
                                <span>Gutscheinrabatt:</span>
                                <strong>-<?= number_format($voucherDiscount, 2, ',', '.') ?> €</strong>
                            </div>
                            <div class="mb-2 small text-muted">
                                <?php foreach ($cartVouchersToRedeem as $voucher): ?>
                                    <div><?= htmlspecialchars($voucher['voucher_code']) ?></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span><strong>Gesamtpreis:</strong></span>
                                <strong id="total-price"><?= number_format($totalPrice, 2, ',', '.') ?> €</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Sitze / Events / Gutscheine -->
            <div class="row">
                <div class="col-12">
                    <?php if (!empty($cartVouchersToBuy)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Gutscheine (zu kaufen)</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($cartVouchersToBuy as $voucher): ?>
                                    <div class="mb-3 p-3 border rounded">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong>Gutschein <?= htmlspecialchars($voucher['voucher_code']) ?></strong>
                                                <?php if ($voucher['event_name']): ?>
                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($voucher['event_name']) ?>
                                                        <?php if ($voucher['category_name']): ?>
                                                            - <?= htmlspecialchars($voucher['category_name']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <?php
                                                    $qty = (int)($voucher['quantity'] ?? 1);
                                                    $unitPrice = (float)($voucher['category_price'] ?? 0);
                                                ?>
                                                <strong><?= number_format($unitPrice * $qty, 2, ',', '.') ?> €</strong>
                                                <?php if ($qty > 1): ?>
                                                    <small class="text-muted d-block"><?= $qty ?> × <?= number_format($unitPrice, 2, ',', '.') ?> €</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($groupedItems as $group): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <?= htmlspecialchars($group['event']['name']) ?>
                                    <small class="text-muted">- <?= date('d.m.Y', strtotime($group['date'])) ?></small>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($group['items'] as $item): ?>
                                    <div class="mb-4 p-3 border rounded">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong><?= htmlspecialchars($seatPlanModel->getSeatDisplayName($item['reservation']['event_id'], $item['reservation']['seat_identifier'])) ?></strong>
                                                <?php if ($item['category']): ?>
                                                    <span class="text-muted">- <?= htmlspecialchars($item['category']['name']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong id="price_<?= $item['reservation']['id'] ?>"><?= number_format($item['price'], 2, ',', '.') ?> €</strong>
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small">Preisoption:</label>
                                            <div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="pricetype_<?= $item['reservation']['id'] ?>"
                                                           id="normal_<?= $item['reservation']['id'] ?>" value="normal"
                                                           <?= !$item['reservation']['is_reduced'] && !$item['reservation']['is_fn_card'] ? 'checked' : '' ?>
                                                           onchange="updatePrice(<?= $item['reservation']['id'] ?>, 'normal')">
                                                    <label class="form-check-label" for="normal_<?= $item['reservation']['id'] ?>">
                                                        Normal (<?= number_format($item['category'] ? (float)$item['category']['price'] : 0, 2, ',', '.') ?> €)
                                                    </label>
                                                </div>

                                                <?php if ($reducedPricesActive && ($group['event']['allow_reduced_prices'] ?? false) && $item['category'] && $item['category']['reduced_price']): ?>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="pricetype_<?= $item['reservation']['id'] ?>"
                                                           id="reduced_<?= $item['reservation']['id'] ?>" value="reduced"
                                                           <?= $item['reservation']['is_reduced'] ? 'checked' : '' ?>
                                                           onchange="updatePrice(<?= $item['reservation']['id'] ?>, 'reduced')">
                                                    <label class="form-check-label" for="reduced_<?= $item['reservation']['id'] ?>">
                                                        Ermäßigt (<?= number_format((float)$item['category']['reduced_price'], 2, ',', '.') ?> €)
                                                    </label>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($fnCardActive && ($group['event']['allow_fn_card'] ?? false)): ?>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="pricetype_<?= $item['reservation']['id'] ?>"
                                                           id="fncard_<?= $item['reservation']['id'] ?>" value="fncard"
                                                           <?= $item['reservation']['is_fn_card'] ? 'checked' : '' ?>
                                                           onchange="toggleFnCard(<?= $item['reservation']['id'] ?>)">
                                                    <label class="form-check-label" for="fncard_<?= $item['reservation']['id'] ?>">
                                                        FN-Card
                                                    </label>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($fnCardActive && ($group['event']['allow_fn_card'] ?? false)): ?>
                                        <div id="fncard_input_<?= $item['reservation']['id'] ?>" style="display: <?= $item['reservation']['is_fn_card'] ? 'block' : 'none' ?>;" class="mb-2">
                                            <div class="row g-2">
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control form-control-sm"
                                                           id="fncardnumber_<?= $item['reservation']['id'] ?>"
                                                           placeholder="FN-Card-Nummer (8 Ziffern)"
                                                           maxlength="8"
                                                           value="<?= $item['reservation']['is_fn_card'] ? ($item['reservation']['fn_card_number'] ?? '') : '' ?>"
                                                           data-validated="<?= $item['reservation']['is_fn_card'] ? '1' : '0' ?>"
                                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                                            id="bfncardnumber_<?= $item['reservation']['id'] ?>"
                                                            onclick="validateFnCard(<?= $item['reservation']['id'] ?>)">
                                                        Prüfen
                                                    </button>
                                                </div>
                                            </div>
                                            <div id="errormessage_<?= $item['reservation']['id'] ?>" class="mt-1 small"></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. Kundendaten -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Kundendaten</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label for="salutation" class="form-label">Anrede</label>
                                    <select class="form-select" id="salutation" name="salutation">
                                        <option value="">-</option>
                                        <option value="Herr" <?= ($customer['salutation'] ?? '') === 'Herr' ? 'selected' : '' ?>>Herr</option>
                                        <option value="Frau" <?= ($customer['salutation'] ?? '') === 'Frau' ? 'selected' : '' ?>>Frau</option>
                                    </select>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label for="first_name" class="form-label">Vorname <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required
                                           value="<?= htmlspecialchars($customer['first_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label for="last_name" class="form-label">Nachname <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required
                                           value="<?= htmlspecialchars($customer['last_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="street" class="form-label">Straße <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="street" name="street" required
                                           value="<?= htmlspecialchars($customer['street'] ?? '') ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="house_number" class="form-label">Hausnummer <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="house_number" name="house_number" required
                                           value="<?= htmlspecialchars($customer['house_number'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="postal_code" class="form-label">PLZ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code" required
                                           value="<?= htmlspecialchars($customer['postal_code'] ?? '') ?>">
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label for="city" class="form-label">Ort <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="city" name="city" required
                                           value="<?= htmlspecialchars($customer['city'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">E-Mail <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" required
                                           value="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                           pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                                           oninput="validateEmail()">
                                    <div class="invalid-feedback" id="email-error"></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="email_confirm" class="form-label">E-Mail bestätigen <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email_confirm" name="email_confirm" required
                                           value="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                           pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                                           oninput="validateEmail()">
                                    <div class="invalid-feedback" id="email_confirm-error"></div>
                                    <small class="form-text text-muted">Bitte geben Sie die E-Mail-Adresse zur Bestätigung erneut ein.</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="phone_prefix" class="form-label">Telefon Vorwahl</label>
                                    <input type="text" class="form-control" id="phone_prefix" name="phone_prefix"
                                           value="<?= htmlspecialchars($customer['phone_prefix'] ?? '') ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="phone_number" class="form-label">Telefonnummer</label>
                                    <input type="text" class="form-control" id="phone_number" name="phone_number"
                                           value="<?= htmlspecialchars($customer['phone_number'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Zahlungsmethode -->
            <?php if ($customerAllowReservation || $customerAllowCash || ($customerAllowPaypal && $paypalActive)): ?>
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Zahlungsmethode</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $finalTotal = $totalPriceCalc - $voucherDiscountCalc;
                            $hasMultipleMethods = (int)($customerAllowPaypal && $paypalActive) + (int)$customerAllowReservation + (int)$customerAllowCash > 1;

                            // Determine default payment method based on availability and price
                            $defaultMethod = 'paypal'; // Default
                            if (!$customerAllowPaypal || !$paypalActive || $finalTotal <= 0) {
                                $defaultMethod = $customerAllowReservation ? 'reservation' : ($customerAllowCash ? 'cash' : 'paypal');
                            }
                            ?>
                            <?php if ($customerAllowPaypal && $paypalActive && $finalTotal > 0): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input payment-method-radio" type="radio" name="payment_method" id="payment_paypal" value="paypal"
                                       <?= $defaultMethod === 'paypal' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="payment_paypal">
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-paypal text-primary me-2"></i>
                                        <strong>PayPal</strong>
                                    </div>
                                    <small class="text-muted d-block">Sofortige Bezahlung online</small>
                                </label>
                            </div>
                            <!-- PayPal Buttons Container (shown when PayPal is selected) -->
                            <div id="paypal-validation-error" class="alert alert-danger mt-3" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i> <span id="paypal-validation-error-text"></span>
                            </div>
                            <div id="paypal-button-container" style="display: none; margin-top: 15px;"></div>
                            <?php endif; ?>

                            <?php if ($customerAllowReservation): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input payment-method-radio" type="radio" name="payment_method" id="payment_reservation" value="reservation"
                                       <?= $defaultMethod === 'reservation' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="payment_reservation">
                                    <i class="bi bi-bookmark text-warning"></i> <strong>Reservierung</strong>
                                    <br><small class="text-muted">Plätze reservieren, später bezahlen</small>
                                </label>
                            </div>
                            <?php endif; ?>

                            <?php if ($customerAllowCash): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input payment-method-radio" type="radio" name="payment_method" id="payment_cash" value="cash"
                                       <?= $defaultMethod === 'cash' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="payment_cash">
                                    <i class="bi bi-cash text-success"></i> <strong>Barzahlung bei Abholung</strong>
                                    <br><small class="text-muted">Zahlung vor Ort</small>
                                </label>
                            </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="<?= url('cart.php') ?>" class="btn btn-outline-secondary">Zurück</a>
                                <button type="submit" id="submit-button" class="btn btn-primary btn-lg">
                                    <i class="bi bi-credit-card"></i> Jetzt bestellen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        const fnCardFactor = <?= $fnCardFactor ?>;
        const fnCardActive = <?= $fnCardActive ? 'true' : 'false' ?>;
        const reducedPricesActive = <?= $reducedPricesActive ? 'true' : 'false' ?>;
        
        <?php
        $pricesData = [];
        foreach ($groupedItems as $group) {
            foreach ($group['items'] as $item) {
                $normalPrice = $item['category'] ? (float)$item['category']['price'] : 0;
                $reducedPrice = $item['category'] && $item['category']['reduced_price'] ? (float)$item['category']['reduced_price'] : 0;
                $fnCardPrice = $normalPrice - ($normalPrice * $fnCardFactor);
                $pricesData[$item['reservation']['id']] = [
                    'normal' => $normalPrice,
                    'reduced' => $reducedPrice,
                    'fncard' => $fnCardPrice,
                ];
            }
        }
        ?>
        const prices = <?= json_encode($pricesData, JSON_NUMERIC_CHECK) ?>;
        
        function updatePrice(reservationId, type) {
            const priceEl = document.getElementById("price_" + reservationId);
            if (!priceEl || !prices[reservationId]) return;
            
            let price = 0;
            if (type === 'normal') {
                price = prices[reservationId].normal;
            } else if (type === 'reduced') {
                price = prices[reservationId].reduced;
            } else if (type === 'fncard') {
                price = prices[reservationId].fncard;
            }
            
            priceEl.textContent = price.toFixed(2).replace('.', ',') + " €";
            updateTotal();
        }
        
        function toggleFnCard(reservationId) {
            const inputDiv = document.getElementById("fncard_input_" + reservationId);
            const checkbox = document.getElementById("fncard_" + reservationId);
            
            if (!inputDiv || !checkbox) return;
            
            if (checkbox.checked) {
                inputDiv.style.display = "block";
                updatePrice(reservationId, 'fncard');
            } else {
                inputDiv.style.display = "none";
                // Reset to normal price
                document.getElementById("normal_" + reservationId).checked = true;
                updatePrice(reservationId, 'normal');
                
                // Remove FN Card from reservation
                fetch('<?= url("api/fncard_validate.php") ?>?action=delete_fncard&reservation_id=' + reservationId)
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            console.error('Error removing FN Card:', data.error);
                        }
                    });
            }
        }
        
        function validateFnCard(reservationId) {
            const input = document.getElementById("fncardnumber_" + reservationId);
            const errorDiv = document.getElementById("errormessage_" + reservationId);
            const btn = document.getElementById("bfncardnumber_" + reservationId);
            
            if (!input || !errorDiv) return;
            
            const fnCardNumber = input.value.trim();
            
            if (fnCardNumber.length !== 8) {
                errorDiv.innerHTML = '<span class="text-danger">Die FN-Card-Nummer muss 8 Ziffern lang sein.</span>';
                return;
            }
            
            btn.disabled = true;
            btn.textContent = "Prüfe...";
            errorDiv.innerHTML = "";
            
            fetch('<?= url("api/fncard_validate.php") ?>?action=set_fncard&reservation_id=' + reservationId + '&fncard=' + fnCardNumber)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        errorDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> FN-Card-Nummer erfolgreich validiert</span>';
                        input.disabled = true;
                        input.dataset.validated = "1";
                        input.style.color = "green";
                        btn.style.display = "none";
                        updateTotal();
                    } else {
                        errorDiv.innerHTML = '<span class="text-danger">' + (data.error || 'Fehler bei der Validierung') + '</span>';
                    }
                })
                .catch(error => {
                    errorDiv.innerHTML = '<span class="text-danger">Fehler: ' + error.message + '</span>';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = "Prüfen";
                });
        }
        
        function validateEmail() {
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
        
        // Add form validation on submit
        document.addEventListener("DOMContentLoaded", function() {
            const form = document.querySelector("form");
            if (form) {
                form.addEventListener("submit", function(e) {
                    // Prevent submit if PayPal is selected (PayPal handles its own flow)
                    const paypalRadio = document.getElementById('payment_paypal');
                    if (paypalRadio && paypalRadio.checked) {
                        e.preventDefault();
                        e.stopPropagation();
                        alert('Bitte nutzen Sie die PayPal-Buttons um fortzufahren.');
                        return false;
                    }

                    if (!validateEmail()) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });
            }
        });
        
        function updateTotal() {
            let total = 0;
            <?php foreach ($groupedItems as $group): ?>
                <?php foreach ($group['items'] as $item): ?>
                    const priceEl<?= $item['reservation']['id'] ?> = document.getElementById("price_<?= $item['reservation']['id'] ?>");
                    if (priceEl<?= $item['reservation']['id'] ?>) {
                        const priceText = priceEl<?= $item['reservation']['id'] ?>.textContent.replace(' €', '').replace(',', '.');
                        total += parseFloat(priceText) || 0;
                    }
                <?php endforeach; ?>
            <?php endforeach; ?>

            const totalEl = document.getElementById("total-price");
            if (totalEl) {
                totalEl.textContent = total.toFixed(2).replace('.', ',') + " €";
            }
        }

        <?php if ($paypalActive && $customerAllowPaypal && !empty($paypalClientId)): ?>
        let paypalButtonsRendered = false;

        function togglePaymentMethod() {
            const paypalRadio = document.getElementById('payment_paypal');
            const paypalContainer = document.getElementById('paypal-button-container');
            const submitButton = document.getElementById('submit-button');

            if (!paypalRadio || !paypalContainer || !submitButton) {
                return;
            }

            if (paypalRadio.checked) {
                paypalContainer.style.display = 'block';
                submitButton.style.display = 'none';

                if (!paypalButtonsRendered && typeof paypal !== 'undefined') {
                    renderPayPalButtons();
                }
            } else {
                paypalContainer.style.display = 'none';
                submitButton.style.display = 'inline-block';
            }
        }

        function showPaypalError(msg) {
            const errEl = document.getElementById('paypal-validation-error');
            const errText = document.getElementById('paypal-validation-error-text');
            if (errEl && errText) {
                errText.textContent = msg;
                errEl.style.display = 'block';
                errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function hidePaypalError() {
            const errEl = document.getElementById('paypal-validation-error');
            if (errEl) errEl.style.display = 'none';
        }

        // Price type per seat (normal / reduced / fncard) chosen via the radio buttons
        // FN card chosen for a seat but its number not yet confirmed with "Prüfen"
        function unvalidatedFnCardMessage() {
            const open = Array.from(document.querySelectorAll('input[type="radio"][name^="pricetype_"][value="fncard"]:checked'))
                .filter(function(radio) {
                    const input = document.getElementById("fncardnumber_" + radio.name.replace("pricetype_", ""));
                    return !input || input.dataset.validated !== "1";
                });
            return open.length ? 'Bitte die FN-Card-Nummer mit „Prüfen“ bestätigen – sonst kann der FN-Card-Preis nicht berechnet werden.' : '';
        }

        function getSelectedPriceTypes() {
            const types = {};
            document.querySelectorAll('input[type="radio"][name^="pricetype_"]:checked').forEach(function(radio) {
                types[radio.name.replace("pricetype_", "")] = radio.value;
            });
            return types;
        }

        function getCheckoutFormData() {
            return {
                salutation: (document.getElementById('salutation')?.value || '').trim(),
                first_name: (document.getElementById('first_name')?.value || '').trim(),
                last_name: (document.getElementById('last_name')?.value || '').trim(),
                email: (document.getElementById('email')?.value || '').trim(),
                email_confirm: (document.getElementById('email_confirm')?.value || '').trim(),
                street: (document.getElementById('street')?.value || '').trim(),
                house_number: (document.getElementById('house_number')?.value || '').trim(),
                postal_code: (document.getElementById('postal_code')?.value || '').trim(),
                city: (document.getElementById('city')?.value || '').trim(),
                phone_prefix: (document.getElementById('phone_prefix')?.value || '').trim(),
                phone_number: (document.getElementById('phone_number')?.value || '').trim()
            };
        }

        function validateCheckoutForm(customerData) {
            const requiredFields = ['first_name', 'last_name', 'email', 'street', 'house_number', 'postal_code', 'city'];
            for (const field of requiredFields) {
                if (!customerData[field]) {
                    return 'Bitte füllen Sie alle Pflichtfelder aus.';
                }
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerData.email)) {
                return 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
            if (customerData.email !== customerData.email_confirm) {
                return 'Die E-Mail-Adressen stimmen nicht überein. Bitte überprüfen Sie Ihre Eingabe.';
            }
            return null;
        }

        function logPayPalError(type, orderId, errorMsg) {
            fetch('<?= url("api/paypal_log_error.php") ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: type,
                    order_id: orderId,
                    error: errorMsg
                })
            }).catch(function() { /* silent */ });
        }

        function renderPayPalButtons() {
            const container = document.getElementById('paypal-button-container');
            if (!container) return;

            // Clear container
            container.innerHTML = '';

            paypal.Buttons({
                style: {
                    layout: 'vertical',
                    color: 'gold',
                    shape: 'rect',
                    label: 'paypal'
                },

                // Validate BEFORE the PayPal popup opens
                onClick: function(data, actions) {
                    hidePaypalError();
                    const customerData = getCheckoutFormData();
                    const errorMsg = validateCheckoutForm(customerData) || unvalidatedFnCardMessage();
                    if (errorMsg) {
                        showPaypalError(errorMsg);
                        return actions.reject();
                    }
                    return actions.resolve();
                },

                // Create order
                createOrder: function(data, actions) {
                    const customerData = getCheckoutFormData();

                    // Show loading state
                    container.style.opacity = '0.5';

                    // Call backend to create PayPal order (includes customer data to save)
                    return fetch('<?= url("api/paypal_create_order.php") ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ customerData: customerData, priceTypes: getSelectedPriceTypes() })
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        container.style.opacity = '1';
                        if (data.success && data.orderID) {
                            return data.orderID;
                        } else {
                            throw new Error(data.error || 'Failed to create order');
                        }
                    })
                    .catch(function(error) {
                        container.style.opacity = '1';
                        logPayPalError('create_failed', null, error.message);
                        alert('Fehler beim Erstellen der Bestellung: ' + error.message);
                        throw error;
                    });
                },

                // Approve handler
                onApprove: function(data, actions) {
                    // Show loading state
                    container.style.opacity = '0.5';

                    // Call backend to capture order
                    return fetch('<?= url("api/paypal_capture_order.php") ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            orderID: data.orderID
                        })
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(captureData) {
                        if (captureData.success && captureData.redirectUrl) {
                            // Redirect to success page
                            window.location.href = captureData.redirectUrl;
                        } else {
                            container.style.opacity = '1';
                            logPayPalError('capture_failed', data.orderID, captureData.error || 'Unbekannter Fehler');
                            alert('Fehler bei der Zahlung: ' + (captureData.error || 'Unbekannter Fehler'));
                        }
                    })
                    .catch(function(error) {
                        container.style.opacity = '1';
                        logPayPalError('capture_failed', data.orderID, error.message);
                        alert('Fehler bei der Zahlung: ' + error.message);
                    });
                },

                // Error handler
                onError: function(err) {
                    console.error('PayPal error:', err);
                    logPayPalError('error', null, err?.message || String(err));
                    alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.');
                },

                // Cancel handler
                onCancel: function(data) {
                    console.log('PayPal payment cancelled');
                    logPayPalError('cancel', data?.orderID || null, 'Zahlung vom Kunden abgebrochen');
                }
            }).render('#paypal-button-container');

            paypalButtonsRendered = true;
        }

        // Listen to payment method changes
        document.addEventListener('DOMContentLoaded', function() {
            const paymentRadios = document.querySelectorAll('.payment-method-radio');
            paymentRadios.forEach(function(radio) {
                radio.addEventListener('change', togglePaymentMethod);
            });

            // Initial check
            togglePaymentMethod();
        });
        <?php endif; ?>
    </script>
    <script src="<?= url('libs/js/cart-heartbeat.js') ?>" data-endpoint="<?= url('api/cart_update_timestamp.php') ?>"></script>
</body>
</html>


