<?php
$pageTitle = "Contact Us";
$activePage = "contact";
require_once 'config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$sent = false;

if (!empty($_SESSION['contact_success'])) {
    $sent = true;
    $successName = $_SESSION['contact_success_name'] ?? '';
    unset($_SESSION['contact_success'], $_SESSION['contact_success_name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $subject = trim($_POST['subject'] ?? 'General');
        $message = trim($_POST['message'] ?? '');

        if ($name === '' || $email === '' || $message === '') {
            $error = 'Please fill in name, email and message.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO contact_messages (name, email, phone, subject, message)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $subject,
                    $message
                ]);

                // Redirect so refresh does NOT resubmit
                $_SESSION['contact_success'] = true;
                $_SESSION['contact_success_name'] = $name;
                header('Location: contact.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Could not save message. Make sure contact_messages table exists.';
            }
        }
    }
}

require_once 'includes/header.php';
?>

<style>
.ct-hero {
    position: relative;
    padding: 5rem 0 3.5rem;
    text-align: center;
    overflow: hidden;
    background: #0C0F14;
}
.ct-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse at 50% 0%, rgba(13,148,136,0.35) 0%, transparent 55%),
        radial-gradient(ellipse at 90% 100%, rgba(232,197,71,0.06) 0%, transparent 40%);
}
.ct-hero .container { position: relative; z-index: 2; }
.ct-hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(2.5rem, 5vw, 3.6rem);
    color: #F7F5F0;
    margin: 0.7rem 0 0.8rem;
    line-height: 1.1;
}
.ct-hero h1 em { font-style: italic; color: #E8C547; }
.ct-hero p {
    color: #94A3B8;
    max-width: 480px;
    margin: 0 auto;
    font-size: 1.02rem;
    line-height: 1.7;
}
.ct-body {
    background:
        radial-gradient(ellipse at 15% 0%, rgba(13,148,136,0.12) 0%, transparent 45%),
        #0C0F14;
    padding: 3.5rem 0 4.5rem;
}
.ct-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2.5rem;
}
.ct-info-card {
    background: linear-gradient(160deg, #1C232C, #151A21);
    border: 1px solid rgba(232,197,71,0.14);
    border-radius: 18px;
    padding: 1.4rem 1.2rem;
    text-align: center;
    transition: 0.35s ease;
}
.ct-info-card:hover {
    transform: translateY(-5px);
    border-color: rgba(232,197,71,0.38);
    box-shadow: 0 18px 40px rgba(0,0,0,0.4);
}
.ct-info-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 0.9rem;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: linear-gradient(145deg, rgba(232,197,71,0.15), rgba(13,148,136,0.25));
    color: #E8C547;
    font-size: 1.1rem;
}
.ct-info-card h4 {
    font-size: 0.95rem;
    color: #F7F5F0;
    margin: 0 0 0.35rem;
}
.ct-info-card p {
    font-size: 0.84rem;
    color: #94A3B8;
    margin: 0;
    line-height: 1.55;
}
.ct-info-card a {
    color: #E8C547;
    text-decoration: none;
}
.ct-info-card a:hover { color: #F5E6A3; }
.ct-main {
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: 1.5rem;
    align-items: stretch;
}
.ct-form-card,
.ct-side-card {
    background: linear-gradient(165deg, #1C232C 0%, #151A21 100%);
    border: 1px solid rgba(232,197,71,0.14);
    border-radius: 22px;
    padding: 2rem 1.75rem;
}
.ct-form-card h2,
.ct-side-card h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.55rem;
    color: #F7F5F0;
    margin: 0 0 0.35rem;
}
.ct-form-card .sub,
.ct-side-card .sub {
    color: #94A3B8;
    font-size: 0.9rem;
    margin-bottom: 1.5rem;
}
.ct-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.ct-field { margin-bottom: 1rem; }
.ct-field label {
    display: block;
    font-size: 0.82rem;
    font-weight: 500;
    color: #F7F5F0;
    margin-bottom: 0.35rem;
}
.ct-field input,
.ct-field textarea,
.ct-field select {
    width: 100%;
    background: rgba(0,0,0,0.35);
    border: 1px solid rgba(232,197,71,0.2);
    border-radius: 12px;
    padding: 0.8rem 1rem;
    color: #F7F5F0;
    font-size: 0.92rem;
    outline: none;
    transition: border-color 0.25s, box-shadow 0.25s;
}
.ct-field input:focus,
.ct-field textarea:focus,
.ct-field select:focus {
    border-color: #E8C547;
    box-shadow: 0 0 0 3px rgba(232,197,71,0.12);
}
.ct-field input::placeholder,
.ct-field textarea::placeholder { color: #6a6560; }
.ct-field textarea { resize: vertical; min-height: 120px; }
.ct-submit {
    width: 100%;
    border: none;
    border-radius: 999px;
    padding: 0.9rem 1.25rem;
    font-weight: 600;
    font-size: 0.95rem;
    background: linear-gradient(135deg, #E8C547, #D4A017);
    color: #0C0F14;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: 0.3s ease;
    margin-top: 0.35rem;
}
.ct-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(232,197,71,0.3);
}
.ct-alert {
    border-radius: 12px;
    padding: 0.85rem 1rem;
    font-size: 0.9rem;
    margin-bottom: 1.15rem;
}
.ct-alert-ok {
    background: rgba(13,148,136,0.25);
    border: 1px solid rgba(232,197,71,0.35);
    color: #F5E6A3;
}
.ct-alert-err {
    background: rgba(180,40,55,0.15);
    border: 1px solid rgba(226,138,149,0.35);
    color: #E28A95;
}
.ct-hours {
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem;
}
.ct-hours li {
    display: flex;
    justify-content: space-between;
    padding: 0.65rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    font-size: 0.9rem;
}
.ct-hours li span:first-child { color: #94A3B8; }
.ct-hours li span:last-child { color: #F7F5F0; font-weight: 500; }
.ct-hours li.closed span:last-child { color: #E28A95; }
.ct-map {
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid rgba(232,197,71,0.15);
    height: 200px;
    background:
        linear-gradient(135deg, rgba(13,148,136,0.4), rgba(12,15,20,0.85)),
        url('assets/img/hero.jpeg') center/cover;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 1rem;
}
.ct-map i {
    font-size: 1.8rem;
    color: #E8C547;
    margin-bottom: 0.5rem;
}
.ct-map strong {
    display: block;
    color: #F7F5F0;
    font-size: 0.95rem;
}
.ct-map span {
    font-size: 0.82rem;
    color: rgba(247,245,240,0.7);
}
.ct-social {
    display: flex;
    gap: 0.65rem;
    margin-top: 1.25rem;
}
.ct-social a {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    border: 1px solid rgba(232,197,71,0.25);
    color: #E8C547;
    text-decoration: none;
    transition: 0.3s ease;
}
.ct-social a:hover {
    background: #E8C547;
    color: #0C0F14;
}
@media (max-width: 992px) {
    .ct-info-grid { grid-template-columns: 1fr 1fr; }
    .ct-main { grid-template-columns: 1fr; }
}
@media (max-width: 576px) {
    .ct-info-grid { grid-template-columns: 1fr; }
    .ct-row { grid-template-columns: 1fr; }
    .ct-form-card, .ct-side-card { padding: 1.5rem 1.2rem; }
}
</style>
<!-- HERO -->
<section class="ct-hero">
    <div class="container">
        <span class="section-kicker">GET IN TOUCH</span>
        <h1>Contact <em>Elegance</em></h1>
        <p>Questions, bookings, or special requests — we’re here to help you feel looked after.</p>
    </div>
</section>

<!-- BODY -->
<section class="ct-body">
    <div class="container">
        <!-- Info strip -->
        <div class="ct-info-grid">
            <div class="ct-info-card">
                <div class="ct-info-icon"><i class="fas fa-map-marker-alt"></i></div>
                <h4>Visit Us</h4>
                <p>123 Luxury Lane<br>Clifton, Karachi</p>
            </div>
            <div class="ct-info-card">
                <div class="ct-info-icon"><i class="fas fa-phone"></i></div>
                <h4>Call Us</h4>
                <p><a href="tel:0330-9066929">0330-9066929</a></p>
            </div>
            <div class="ct-info-card">
                <div class="ct-info-icon"><i class="fas fa-envelope"></i></div>
                <h4>Email</h4>
                <p><a href="mailto:info@elegancesalon.com">info@elegancesalon.com</a></p>
            </div>
            <div class="ct-info-card">
                <div class="ct-info-icon"><i class="fas fa-clock"></i></div>
                <h4>Hours</h4>
                <p>Mon–Sat 10am–8pm<br>Sunday Closed</p>
            </div>
        </div>

        <!-- Form + side -->
        <div class="ct-main">
            <div class="ct-form-card">
                <h2>Send a message</h2>
                <p class="sub">We’ll get back to you within one business day.</p>

                <?php if ($sent): ?>
                    <div class="ct-alert ct-alert-ok">
                        <i class="fas fa-check-circle me-1"></i>
                        Thank you<?php echo !empty($successName) ? ', ' . htmlspecialchars($successName) : ''; ?>!
                        Your message has been received.
                    </div>
                <?php elseif ($error): ?>
                    <div class="ct-alert ct-alert-err"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (!$sent): ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="ct-row">
                        <div class="ct-field">
                            <label>Full Name *</label>
                            <input type="text" name="name" placeholder="Your name" required
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="ct-field">
                            <label>Email *</label>
                            <input type="email" name="email" placeholder="you@example.com" required
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="ct-row">
                        <div class="ct-field">
                            <label>Phone</label>
                            <input type="tel" name="phone" placeholder="03XX-XXXXXXX"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="ct-field">
                            <label>Subject</label>
                            <select name="subject">
                                <?php
                                $subjects = [
                                    'General'     => 'General inquiry',
                                    'Booking'     => 'Booking help',
                                    'Feedback'    => 'Feedback',
                                    'Partnership' => 'Partnership'
                                ];
                                $currentSubject = $_POST['subject'] ?? 'General';
                                foreach ($subjects as $value => $label) {
                                    $selected = ($currentSubject === $value) ? 'selected' : '';
                                    echo "<option value=\"$value\" $selected>$label</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="ct-field">
                        <label>Message *</label>
                        <textarea name="message" placeholder="How can we help you?" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="ct-submit">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="ct-side-card">
                <h2>Visit the salon</h2>
                <p class="sub">Find us in the heart of Clifton.</p>
                <ul class="ct-hours">
                    <li><span>Monday – Friday</span><span>10:00 AM – 8:00 PM</span></li>
                    <li><span>Saturday</span><span>10:00 AM – 8:00 PM</span></li>
                    <li class="closed"><span>Sunday</span><span>Closed</span></li>
                </ul>
                <div class="ct-map">
                    <div>
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>Elegance Salon</strong>
                        <span>123 Luxury Lane, Clifton, Karachi</span>
                    </div>
                </div>
                <div class="ct-social">
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                    <a href="#" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>