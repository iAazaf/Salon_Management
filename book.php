<?php

$pageTitle = "Book Appointment";

$activePage = "book";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// Booking is available only to signed-in users. Keep the requested page as the login destination.
if (empty($_SESSION['user_id'])) {
    $returnTo = 'book.php';
    if (isset($_GET['service_id']) && filter_var($_GET['service_id'], FILTER_VALIDATE_INT)) {
        $returnTo .= '?service_id=' . (int) $_GET['service_id'];
    }
    header('Location: login.php?return_to=' . rawurlencode($returnTo));
    exit;
}



/*

|--------------------------------------------------------------------------

| Services

|--------------------------------------------------------------------------

*/

$categories = [

    'Hair' => [

        1 => 'Precision Haircut ($65)',

        2 => 'Balayage & Highlights ($165)',

        3 => 'Full Color Transformation ($140)',

        4 => 'Blowout & Styling ($55)',

        5 => 'Keratin Smoothing ($220)',

        6 => 'Bridal Hair Styling ($180)',

        7 => 'Deep Conditioning Ritual ($45)',

    ],

    'Skin' => [

        8 => 'Signature Facial ($95)',

        9 => 'Anti-Aging Treatment ($140)',

        10 => 'Hydra Glow Facial ($110)',

        11 => 'Acne Clarity Facial ($105)',

        12 => 'Microdermabrasion ($125)',

        13 => 'LED Light Therapy ($70)',

        14 => 'Men’s Skin Ritual ($85)',

    ],

    'Beauty' => [

        15 => 'Gel Manicure ($45)',

        16 => 'Luxury Pedicure ($55)',

        17 => 'Nail Art Detail ($35)',

        18 => 'Classic Lash Lift ($75)',

        19 => 'Brow Sculpt & Tint ($40)',

        20 => 'Soft Glam Makeup ($95)',

        21 => 'Bridal Makeup ($180)',

    ],

    'Wellness' => [

        22 => 'Relaxation Massage ($110)',

        23 => 'Deep Tissue Massage ($135)',

        24 => 'Hot Stone Therapy ($145)',

        25 => 'Aromatherapy Session ($120)',

        26 => 'Scalp & Neck Ritual ($55)',

        27 => 'Couples Massage ($210)',

        28 => 'Express Wellness Escape ($45)',

    ],

];


$categoriesById = [];
foreach ($categories as $categoryName => $items) {
    foreach ($items as $id => $label) {
        preg_match('/^(.*) \(\$([0-9]+)\)$/', $label, $match);
        $categoriesById[(int)$id] = ['name' => $match[1] ?? $label, 'price' => (float)($match[2] ?? 0), 'category' => $categoryName];
    }
}


/* Save booking requests; payment is handled later by the salon admin. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['booking_action'] ?? '') === 'create_booking') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Please log in before booking an appointment.']);
        exit;
    }
    $submittedCsrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Security validation failed (CSRF). Please refresh the page and try again.']);
        exit;
    }
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new RuntimeException('Database connection is not available.');
        }
        $name = trim((string)($_POST['client_name'] ?? ''));
        $phone = trim((string)($_POST['client_phone'] ?? ''));
        $branch = trim((string)($_POST['branch'] ?? ''));
        $bookingType = trim((string)($_POST['booking_type'] ?? ''));
        $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string)($_POST['appointment_date'] ?? ''));
        $timeRaw = trim((string)($_POST['appointment_time'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $allowedBranches = ['Main Branch (DHA Phase 5)', 'Gulberg Heights Branch'];
        $allowedTypes = ['A regular service', 'Bridal/VIP Package'];
        $time = date('H:i:s', strtotime($timeRaw));
        if ($name === '' || mb_strlen($name) > 100 || $phone === '' || mb_strlen($phone) > 20 ||
            !in_array($branch, $allowedBranches, true) || !in_array($bookingType, $allowedTypes, true) ||
            !$serviceId || !isset($categoriesById[$serviceId]) || $date === '' ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d') ||
            !$timeRaw || $time === false) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Please check all booking details and try again.']);
            exit;
        }
        if (strtotime($date . ' ' . $time) <= time()) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Please choose a future date and time.']);
            exit;
        }
        $serviceName = $categoriesById[$serviceId]['name'];
        $servicePrice = $categoriesById[$serviceId]['price'];
        $durations = [1=>45,2=>120,3=>90,4=>40,5=>150,6=>90,7=>30,8=>60,9=>75,10=>50,11=>60,12=>45,13=>30,14=>45,15=>45,16=>55,17=>30,18=>50,19=>35,20=>60,21=>90,22=>60,23=>75,24=>75,25=>60,26=>30,27=>60,28=>25];
        $accountStmt = $pdo->prepare("SELECT email FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $accountStmt->execute([(int) $_SESSION['user_id']]);
        $accountEmail = trim((string) $accountStmt->fetchColumn());
        if ($accountEmail === '') {
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => 'Your account could not be verified. Please sign in again.']);
            exit;
        }
        $pdo->beginTransaction();
        $serviceStmt = $pdo->prepare('SELECT id FROM services WHERE name = ? AND status = \'active\' LIMIT 1');
        $serviceStmt->execute([$serviceName]);
        $dbServiceId = $serviceStmt->fetchColumn();
        if (!$dbServiceId) {
            $category = '';
            foreach ($categories as $cat => $items) { if (isset($items[$serviceId])) { $category = $cat; break; } }
            $insertService = $pdo->prepare('INSERT INTO services (name, description, duration, price, category, status) VALUES (?, ?, ?, ?, ?, \'active\')');
            $insertService->execute([$serviceName, 'Elegance Salon professional service', $durations[$serviceId] ?? 60, $servicePrice, $category]);
            $dbServiceId = (int)$pdo->lastInsertId();
        }
        // Prevent overlapping bookings at the same requested start time.
        $conflict = $pdo->prepare("SELECT id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status IN ('pending','confirmed') LIMIT 1");
        $conflict->execute([$date, $time]);
        if ($conflict->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'This time slot has already been requested. Please choose another time.']);
            exit;
        }
        $normalizedClientPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
        $normalizedBookingPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(?), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
        $clientStmt = $pdo->prepare("
            SELECT id
            FROM clients
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
            ORDER BY CASE WHEN $normalizedClientPhone = $normalizedBookingPhone THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ");
        $clientStmt->execute([$accountEmail, $phone]);
        $clientId = $clientStmt->fetchColumn();
        if (!$clientId) {
            $clientStmt = $pdo->prepare("
                SELECT id
                FROM clients
                WHERE $normalizedClientPhone = $normalizedBookingPhone
                  AND (email IS NULL OR TRIM(email) = '')
                ORDER BY id ASC
                LIMIT 1
            ");
            $clientStmt->execute([$phone]);
            $clientId = $clientStmt->fetchColumn();
        }
        if (!$clientId) {
            $clientStmt = $pdo->prepare('INSERT INTO clients (name, email, phone, notes) VALUES (?, ?, ?, ?)');
            $clientStmt->execute([$name, $accountEmail, $phone, 'Created from online booking request']);
            $clientId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE clients SET name = ?, email = ?, phone = ? WHERE id = ?')
                ->execute([$name, $accountEmail, $phone, $clientId]);
        }
        $fullNotes = 'Branch: ' . $branch . ' | Booking type: ' . $bookingType;
        if ($notes !== '') $fullNotes .= ' | Customer notes: ' . $notes;
        $appointmentStmt = $pdo->prepare("INSERT INTO appointments (client_id, service_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, 'pending', ?)");
        $appointmentStmt->execute([$clientId, $dbServiceId, $date, $time, $fullNotes]);
        $appointmentId = (int)$pdo->lastInsertId();
        $pdo->commit();

        require_once __DIR__ . '/includes/notifications.php';
        notify_appointment_event($pdo, $appointmentId, 'booked');

        echo json_encode(['ok' => true, 'appointment_id' => $appointmentId, 'message' => 'Your appointment request has been saved successfully. The salon will contact you to confirm your booking.']);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Elegance booking error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'We could not save your booking. Please check that the Elegance database is imported and try again.']);
    }
    exit;
}



$selectedServiceId = filter_input(

    INPUT_GET,

    'service_id',

    FILTER_VALIDATE_INT

);



$selectedServiceId = (

    $selectedServiceId && $selectedServiceId > 0

) ? $selectedServiceId : 0;



require_once 'includes/header.php';

?>



<style>

/* =========================================

   BOOKING PAGE - ALL CSS IN ONE PLACE

\========================================= */

.book-hero {

    background: #0C0F14;

    text-align: center;

    padding: 65px 0 40px;

}



.book-kicker {

    color: #E8C547;

    font-size: .8rem;

    font-weight: 700;

    letter-spacing: 2px;

    text-transform: uppercase;

}



.book-heading {

    font-family: 'Playfair Display', serif;

    color: #fff;

    font-size: clamp(2rem, 5vw, 3.2rem);

    font-weight: 700;

    margin: 12px 0;

}



.book-heading em {

    color: #E8C547;

}



.book-description {

    max-width: 620px;

    margin: 0 auto;

    color: #94A3B8;

    line-height: 1.8;

}



.book-body {

    background: #0C0F14;

    padding: 55px 0;

    min-height: 80vh;

}



.booking-card,

.booking-sidebar {

    background: #1C232C;

    border: 1px solid rgba(232,197,71,.16);

    border-radius: 20px;

}



.booking-card {

    padding: 35px;

}



.booking-step {

    padding-bottom: 28px;

    margin-bottom: 28px;

    border-bottom: 1px solid rgba(232,197,71,.13);

}



.booking-step:last-of-type {

    border-bottom: 0;

}



.step-label {

    display: block;

    color: #E8C547;

    font-size: .75rem;

    font-weight: 700;

    letter-spacing: 1.5px;

    margin-bottom: 6px;

}



.step-heading {

    color: #fff;

    font-family: 'Playfair Display', serif;

    font-size: 1.4rem;

    margin-bottom: 22px;

}



.book-label {

    display: block;

    color: #F7F5F0;

    font-size: .88rem;

    font-weight: 500;

    margin-bottom: 8px;

}



.custom-input {

    width: 100%;

    min-height: 48px;

    background-color: #151A21 !important;

    border: 1px solid rgba(232,197,71,.28) !important;

    border-radius: 10px !important;

    color: #fff !important;

    padding: 12px 14px !important;

}



.custom-input::placeholder {

    color: #94A3B8 !important;

    opacity: 1;

}



.custom-input:focus {

    border-color: #E8C547 !important;

    box-shadow: 0 0 0 3px rgba(232,197,71,.12) !important;

}



.custom-input option {

    background: #1C232C;

    color: #fff;

}



textarea.custom-input {

    min-height: 100px;

    resize: vertical;

}



input[type="date"]::-webkit-calendar-picker-indicator {

    filter: invert(1) sepia(1) saturate(2)

            hue-rotate(5deg);

    cursor: pointer;

}



.book-check {

    background-color: #151A21 !important;

    border-color: rgba(232,197,71,.5) !important;

}



.book-check:checked {

    background-color: #E8C547 !important;

    border-color: #E8C547 !important;

}



.book-terms {

    color: #C9C2BB;

    font-size: .84rem;

    line-height: 1.6;

}



/* Standard booking submit button */



.booking-submit {

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 12px;

    width: 100%;

    min-height: 62px;

    padding: 16px 22px;

    border: 1px solid #F5E6A3;

    border-radius: 13px;

    background: linear-gradient(135deg, #F5E6A3, #E8C547);

    color: #0C0F14;

    font-size: 1rem;

    font-weight: 800;

    letter-spacing: .3px;

    cursor: pointer;

    box-shadow: 0 8px 24px rgba(232,197,71,.13);

    transition: transform .25s ease,

                box-shadow .25s ease,

                filter .25s ease;

}



.booking-submit:hover {

    transform: translateY(-3px);

    box-shadow: 0 12px 30px rgba(232,197,71,.24);

    filter: brightness(1.06);

}



.booking-submit:active {

    transform: translateY(0);

}



.whatsapp-icon {

    width: 27px;

    height: 27px;

    flex-shrink: 0;

}



.book-submit-wrap {

    text-align: center;

    padding-top: 5px;

}



.book-note {

    margin: 14px 0 0;

    color: #94A3B8;

    font-size: .82rem;

    line-height: 1.6;

}



.booking-sidebar {

    padding: 25px;

    margin-bottom: 20px;

}



.sidebar-kicker {

    display: block;

    color: #E8C547;

    font-size: .72rem;

    font-weight: 700;

    letter-spacing: 1.4px;

    margin-bottom: 7px;

}



.sidebar-heading {

    color: #fff;

    font-family: 'Playfair Display', serif;

    font-size: 1.25rem;

    margin-bottom: 18px;

}



.sidebar-text {

    color: #94A3B8;

    font-size: .9rem;

    line-height: 1.8;

}



.whatsapp-contact {

    display: block;

    color: #E8C547;

    font-size: 1rem;

    font-weight: 700;

    text-decoration: none;

    margin-top: 12px;

}



.whatsapp-contact:hover {

    color: #F5E6A3;

}



.book-list {

    padding-left: 19px;

    margin: 0;

    color: #C9C2BB;

    font-size: .87rem;

    line-height: 1.9;

}



@media (max-width: 767px) {

    .book-hero {

        padding: 45px 15px 30px;

    }



    .book-body {

        padding: 30px 0;

    }



    .booking-card {

        padding: 22px 17px;

        border-radius: 16px;

    }



    .booking-sidebar {

        padding: 21px;

    }



    .step-heading {

        font-size: 1.25rem;

    }



    .booking-submit {

        font-size: .94rem;

        padding: 14px;

    }

}



/* ===== BOOK NOW BUTTON ===== */




.booking-submit svg {

    width: 23px;

    height: 23px;

    flex-shrink: 0;

}



/* ===== FLOATING WHATSAPP CHAT BUTTON ===== */



.whatsapp-float {

    position: fixed;

    right: 24px;

    bottom: 24px;

    z-index: 9999;

    display: flex;

    width: 58px;

    height: 58px;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: #25D366;

    color: #fff;

    box-shadow: 0 5px 20px rgba(0, 0, 0, .28);

    text-decoration: none;

    transition: transform .25s ease, box-shadow .25s ease;

}



.whatsapp-float:hover {

    color: #fff;

    transform: translateY(-4px) scale(1.04);

    box-shadow: 0 8px 25px rgba(37, 211, 102, .3);

}



.whatsapp-float svg {

    width: 31px;

    height: 31px;

}



.whatsapp-float:focus-visible {

    outline: 3px solid #fff;

    outline-offset: 4px;

}



@media (max-width: 576px) {

    .whatsapp-float {

        right: 16px;

        bottom: 16px;

        width: 54px;

        height: 54px;

    }

}



</style>



<!-- =========================================

     HERO

\========================================= -->



<section class="book-hero">

    <div class="container">

        <span class="book-kicker">

            APPOINTMENT BOOKING

        </span>



        <h1 class="book-heading">

            Book Your <em>Appointment</em>

        </h1>



        <p class="book-description">

            Complete the form below to request your appointment. The salon will confirm your booking; payment is handled at the salon.

        </p>

    </div>

</section>



<!-- =========================================

     BOOKING FORM

\========================================= -->



<section class="book-body">

    <div class="container">

        <div class="row g-4 justify-content-center">



            <div class="col-lg-8">

                <div class="booking-card">

<form id="whatsappBookingForm" method="post" action="book.php">



                        <!-- STEP 1 -->



                        <div class="booking-step">

                            <span class="step-label">STEP 01</span>



                            <h3 class="step-heading">

                                Your Details

                            </h3>



                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label for="clientName"

                                           class="book-label">

                                        Full Name *

                                    </label>



                                    <input type="text"

                                           id="clientName"

                                           class="form-control custom-input"

                                           placeholder="Enter your full name"

                                           maxlength="100"

                                           autocomplete="name"

                                           required>

                                </div>



                                <div class="col-md-6">

                                    <label for="clientPhone"

                                           class="book-label">

                                        Mobile Number *

                                    </label>



                                    <input type="tel"

                                           id="clientPhone"

                                           class="form-control custom-input"

                                           placeholder="03xx-xxxxxxx"

                                           maxlength="20"

                                           autocomplete="tel"

                                           required>

                                </div>

                            </div>

                        </div>



                        <!-- STEP 2 -->



                        <div class="booking-step">

                            <span class="step-label">STEP 02</span>



                            <h3 class="step-heading">

                                Branch & Service

                            </h3>



                            <div class="row g-3">



                                <div class="col-md-6">

                                    <label for="branchName"

                                           class="book-label">

                                        Preferred Branch *

                                    </label>



                                    <select id="branchName"

                                            class="form-select custom-input"

                                            required>

                                        <option value="">

                                            Select a branch

                                        </option>



                                        <option value="Main Branch (DHA Phase 5)">

                                            Main Branch (DHA Phase 5)

                                        </option>



                                        <option value="Gulberg Heights Branch">

                                            Gulberg Heights Branch

                                        </option>

                                    </select>

                                </div>



                                <div class="col-md-6">

                                    <label for="bookingType"

                                           class="book-label">

                                        Booking Type *

                                    </label>



                                    <select id="bookingType"

                                            class="form-select custom-input"

                                            required>

                                        <option value="A regular service">

                                            A regular service

                                        </option>



                                        <option value="Bridal/VIP Package">

                                            Bridal/VIP Package

                                        </option>

                                    </select>

                                </div>



                                <div class="col-12">

                                    <label for="service_id"

                                           class="book-label">

                                        Select Service *

                                    </label>



                                    <select name="service_id"

                                            id="service_id"

                                            class="form-select custom-input"

                                            required>

                                        <option value="">

                                            Select a service

                                        </option>



                                        <?php foreach ($categories as $category => $items): ?>

                                            <optgroup label="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">



                                                <?php foreach ($items as $id => $serviceName): ?>

                                                    <option

                                                        value="<?= (int)$id ?>"

                                                        data-service-name="<?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?>"

                                                        <?= $selectedServiceId === (int)$id ? 'selected' : '' ?>

                                                    >

                                                        <?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?>

                                                    </option>

                                                <?php endforeach; ?>



                                            </optgroup>

                                        <?php endforeach; ?>



                                    </select>

                                </div>

                            </div>

                        </div>



                        <!-- STEP 3 -->



                        <div class="booking-step">

                            <span class="step-label">STEP 03</span>



                            <h3 class="step-heading">

                                Preferred Schedule

                            </h3>



                            <div class="row g-3">



                                <div class="col-md-6">

                                    <label for="appDate"

                                           class="book-label">

                                        Preferred Date *

                                    </label>



                                    <input type="date"

                                           id="appDate"

                                           class="form-control custom-input"

                                           min="<?= date('Y-m-d') ?>"

                                           required>

                                </div>



                                <div class="col-md-6">

                                    <label for="appTime"

                                           class="book-label">

                                        Preferred Time *

                                    </label>



                                    <select id="appTime"

                                            class="form-select custom-input"

                                            required>

                                        <option value="">

                                            Select time slot

                                        </option>



                                        <option>11:00 AM</option>

                                        <option>12:00 PM</option>

                                        <option>01:00 PM</option>

                                        <option>02:00 PM</option>

                                        <option>03:00 PM</option>

                                        <option>04:00 PM</option>

                                        <option>05:00 PM</option>

                                        <option>06:00 PM</option>

                                        <option>07:00 PM</option>

                                        <option>08:00 PM</option>

                                        <option>09:00 PM</option>

                                    </select>

                                </div>



                                <div class="col-12">

                                    <label for="appNotes"

                                           class="book-label">

                                        Notes or Special Requirements

                                    </label>



                                    <textarea id="appNotes"

                                              class="form-control custom-input"

                                              rows="3"

                                              maxlength="1000"

                                              placeholder="Tell us about your preferences..."></textarea>

                                </div>

                            </div>



                            <div class="form-check mt-4">

                                <input class="form-check-input book-check"

                                       type="checkbox"

                                       id="termsCheck"

                                       required>



                                <label class="form-check-label book-terms"

                                       for="termsCheck">

                                    I understand this is an appointment request and the salon will confirm availability.

                                </label>

                            </div>

                        </div>



                        <!-- STANDARD BOOKING SUBMIT BUTTON -->



                        <div class="book-submit-wrap">





<button type="submit" class="booking-submit">Book Now <i class="fas fa-arrow-right ms-2" aria-hidden="true"></i></button>







                            <p class="book-note">

                                Your appointment request will be saved to the database. Payment is recorded by the salon after your visit.

                            </p>

                        </div>



                    </form>

                </div>

            </div>



            <!-- SIDEBAR -->



            <div class="col-lg-4">



                <div class="booking-sidebar">

                    <span class="sidebar-kicker">

                        OPENING HOURS

                    </span>



                    <h4 class="sidebar-heading">

                        Salon Timings

                    </h4>



                    <p class="sidebar-text mb-1">

                        <strong class="text-light">

                            Monday–Thursday

                        </strong><br>

                        11:00 AM–10:30 PM

                    </p>



                    <p class="sidebar-text mb-0">

                        <strong class="text-light">

                            Friday–Sunday

                        </strong><br>

                        11:00 AM–11:00 PM

                    </p>

                </div>



                <div class="booking-sidebar">

                    <span class="sidebar-kicker">

                        NEED HELP?

                    </span>



                    <h4 class="sidebar-heading">

                        Booking Support

                    </h4>



                    <p class="sidebar-text">

                        Contact our team directly for assistance

                        with your booking.

                    </p>




<a href="https://wa.me/923309066929"

   target="_blank"

   rel="noopener noreferrer"

   class="whatsapp-contact">

    <i class="fab fa-whatsapp"></i>

    0330 9066929

</a>







                </div>



                <div class="booking-sidebar">

                    <span class="sidebar-kicker">

                        PLEASE NOTE

                    </span>



                    <h4 class="sidebar-heading">

                        Important Information

                    </h4>



                    <ul class="book-list">

                        <li>Choose your preferred branch.</li>

                        <li>Select the service you want.</li>

                        <li>Choose your preferred date and time.</li>

                        <li>Final availability is confirmed by the branch.</li>

                    </ul>

                </div>



            </div>



        </div>

    </div>

</section>



<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('whatsappBookingForm');
    if (!form) return;
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.textContent = 'Saving appointment...';
        try {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const payload = new URLSearchParams({
                booking_action: 'create_booking',
                csrf_token: csrfMeta,
                client_name: document.getElementById('clientName').value.trim(),
                client_phone: document.getElementById('clientPhone').value.trim(),
                branch: document.getElementById('branchName').value,
                booking_type: document.getElementById('bookingType').value,
                service_id: document.getElementById('service_id').value,
                appointment_date: document.getElementById('appDate').value,
                appointment_time: document.getElementById('appTime').value,
                notes: document.getElementById('appNotes').value.trim()
            });
            const response = await fetch('book.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: payload.toString(),
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                alert(result.message || 'Booking could not be saved. Please try again.');
                return;
            }
            alert(result.message + '\nBooking reference: #' + result.appointment_id);
            form.reset();
        } catch (error) {
            alert('Unable to save the appointment. Check that XAMPP Apache and MySQL are running.');
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        }
    });
});
</script>
<?php require_once 'includes/footer.php'; ?>
 