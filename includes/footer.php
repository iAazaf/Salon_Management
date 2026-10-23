<!-- Footer -->
<footer class="footer-elegant mt-5">
    <div class="container">
        <div class="row g-4">

            <!-- Salon Info -->
            <div class="col-lg-4 col-md-6">
                <h5>Elegance Salon</h5>

                <p class="mt-3">
                    Premium salon &amp; spa experience.
                    Beauty, Care &amp; Confidence in every visit.
                </p>

                <!-- Social Media Icons -->
                <div class="d-flex gap-3 mt-3 footer-social">
                    <a href="https://www.facebook.com/"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Facebook"
                       title="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>

                    <a href="https://www.instagram.com/"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Instagram"
                       title="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>

                    <a href="https://wa.me/923309066929"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="WhatsApp"
                       title="WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="col-lg-2 col-md-6">
                <h5>Quick Links</h5>
                <ul class="list-unstyled mt-3">
                    <li class="mb-2"><a href="index.php">Home</a></li>
                    <li class="mb-2"><a href="about.php">About Us</a></li>
                    <li class="mb-2"><a href="services.php">Services</a></li>
                    <li class="mb-2"><a href="book.php">Book Appointment</a></li>
                    <li class="mb-2"><a href="contact.php">Contact Us</a></li>
                    <li class="mb-2"><a href="feedback.php">Feedback</a></li>
                </ul>
            </div>

            <!-- Services -->
            <div class="col-lg-3 col-md-6">
                <h5>Our Services</h5>
                <ul class="list-unstyled mt-3">
                    <li class="mb-2">
                        <a href="services.php">Hair Styling &amp; Color</a>
                    </li>
                    <li class="mb-2">
                        <a href="services.php">Facial &amp; Skin Therapy</a>
                    </li>
                    <li class="mb-2">
                        <a href="services.php">Massage &amp; Wellness</a>
                    </li>
                    <li class="mb-2">
                        <a href="services.php">Nails &amp; Beauty</a>
                    </li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="col-lg-3 col-md-6">
                <h5>Contact Us</h5>

                <ul class="list-unstyled mt-3">

                    <li class="mb-3">
                        <i class="fas fa-map-marker-alt me-2 text-gold"></i>
                        <a href="https://www.google.com/maps/search/?api=1&amp;query=123+Luxury+Lane%2C+Karachi%2C+Pakistan"
                           target="_blank"
                           rel="noopener noreferrer">
                            123 Luxury Lane, Karachi
                        </a>
                    </li>

                    <li class="mb-3">
                        <i class="fas fa-phone me-2 text-gold"></i>
                        <a href="tel:+923309066929">
                            +92 330 9066929
                        </a>
                    </li>

                    <li class="mb-3">
                        <i class="fas fa-envelope me-2 text-gold"></i>
                        <a href="mailto:aazafyousuf32a@gmail.com">
                            info@elegancesalon.com
                        </a>
                    </li>

                    <li class="mb-3">
                        <i class="fas fa-clock me-2 text-gold"></i>
                        Monday – Saturday: 10 AM – 8 PM
                        <br>
                        <span class="ms-4">Sunday: Closed</span>
                    </li>

                </ul>
            </div>

        </div>

        <hr class="my-4"
            style="border-color: rgba(255,255,255,0.1);">

        <div class="text-center pb-2">
            <small>
                &copy; <?php echo date('Y'); ?>
                Elegance Salon. All Rights Reserved
            </small>
        </div>
    </div>
</footer>

<!-- Footer Social Icons Styling -->
<style>
.footer-social a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: 1px solid rgba(232, 197, 71, 0.3);
    border-radius: 50%;
    color: var(--champagne, #E8C547);
    text-decoration: none;
    transition: all 0.25s ease;
}

.footer-social a i {
    font-size: 16px;
}

.footer-social a:hover {
    background: var(--champagne, #E8C547);
    color: var(--black, #0C0F14);
    transform: translateY(-3px);
}

.footer-elegant a {
    transition: color 0.2s ease;
}

.footer-elegant a:hover {
    color: var(--champagne-light, #F5E6A3);
}
</style>

<!-- Global WhatsApp Floating Button -->
<a href="https://wa.me/923309066929"
   class="whatsapp-float"
   target="_blank"
   rel="noopener noreferrer"
   aria-label="Chat with Elegance Salon on WhatsApp"
   title="Chat with us on WhatsApp">
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12 2a9.8 9.8 0 0 0-8.45 14.77L2.3 22l5.4-1.23A10 10 0 1 0 12 2Zm0 17.9a8 8 0 0 1-4.08-1.12l-.29-.17-3.21.73.75-3.13-.19-.31A7.9 7.9 0 1 1 12 19.9Zm4.34-5.93c-.24-.12-1.42-.7-1.64-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.95-1.2-.72-.64-1.2-1.43-1.34-1.67-.14-.24-.02-.37.1-.49.11-.1.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.2-.47-.4-.4-.54-.41h-.46c-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2s.86 2.32.98 2.48c.12.16 1.69 2.58 4.09 3.62.57.25 1.02.4 1.37.51.58.18 1.1.15 1.52.09.46-.07 1.42-.58 1.62-1.14.2-.56.2-1.04.14-1.14-.06-.1-.22-.16-.46-.28Z"/>
    </svg>
</a>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo htmlspecialchars($appBasePath ?? '', ENT_QUOTES, 'UTF-8'); ?>/assets/js/main.js"></script>
<script src="<?php echo htmlspecialchars($appBasePath ?? '', ENT_QUOTES, 'UTF-8'); ?>/assets/js/notifications.js"></script>
</body>
</html>