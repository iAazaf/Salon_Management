// Elegance Salon - Main JS

document.addEventListener('DOMContentLoaded', function () {

  

    // ===== Fade-in Animation for cards =====
    const cards = document.querySelectorAll(
        '.sig-card, .home-service-card, .stylist-card, .benefit-card, .testimonial-card, .gallery-item, .experience-card'
    );
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(24px)';
        setTimeout(() => {
            card.style.transition = 'opacity 0.65s ease, transform 0.65s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 80 + index * 90);
    });

    // ===== Testimonial 3D Tilt (mouse follow) =====
    const tiltCards = document.querySelectorAll('.testimonial-card');

    tiltCards.forEach((card) => {
        card.addEventListener('mouseenter', () => {
            card.classList.add('tilt-active');
        });

        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;

            const maxTilt = 12;
            const rotateY = ((x - centerX) / centerX) * maxTilt;
            const rotateX = -((y - centerY) / centerY) * maxTilt;

            card.style.transform =
                'perspective(900px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-8px) scale(1.03)';
        });

        card.addEventListener('mouseleave', () => {
            card.classList.remove('tilt-active');
            card.style.transform = '';
            card.style.transition = 'transform 0.5s cubic-bezier(0.22, 1, 0.36, 1)';
            setTimeout(() => {
                if (!card.classList.contains('tilt-active')) {
                    card.style.transition = '';
                }
            }, 500);
        });
    });

    // ===== Auto-close mobile navbar on link click =====
    const navLinks = document.querySelectorAll('.navbar-elegant .nav-link, .navbar-elegant .btn-nav, .navbar-elegant .btn-nav-outline');
    const navCollapse = document.getElementById('navbarNav');
    if (navCollapse) {
        navLinks.forEach((link) => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992 && navCollapse.classList.contains('show')) {
                    const bsCollapse = bootstrap.Collapse.getInstance(navCollapse);
                    if (bsCollapse) {
                        bsCollapse.hide();
                    }
                }
            });
        });
    }

});
