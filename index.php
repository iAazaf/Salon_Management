<?php
$pageTitle = "Home";
$activePage = "home";
require_once 'includes/header.php';
?>

<!-- ================= HERO ================= -->
<section class="home-hero">
    <div class="home-hero-overlay"></div>

<!-- ELEGANCE CINEMATIC HERO -->
<section class="salon-video-hero">

<video
    class="salon-hero-video"
    autoplay
    muted
    loop
    playsinline
    preload="auto"
>
    <source src="assets/img/hero video.mp4" type="video/mp4">
</video>


   <div class="container position-relative">
        <div class="row min-vh-75 align-items-center">
            <div class="col-lg-7">
                <div class="hero-copy">
                    <span class="hero-eyebrow">WELCOME TO ELEGANCE SALON</span>

                    <h1>
                        Beauty, <span>Refined.</span><br>
                        Confidence, Elevated.
                    </h1>

                    <p class="hero-description">
                        A quiet, luxurious salon experience — expert hands, premium products,
                        and care that feels personal from the moment you arrive.
                    </p>

                    <div class="hero-actions">
                        <a href="book.php" class="btn btn-gold btn-lg">
                            <i class="fas fa-calendar-check"></i> Book Appointment
                        </a>

                        <a href="services.php" class="btn btn-hero-outline btn-lg">
                            Explore Services <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

</section>


<!-- ================= INTRO ================= -->
<section class="intro-section py-5">
    <div class="container py-lg-4">

        <div class="row align-items-center g-3 g-xl-5">

            <div class="col-lg-6">
                <span class="section-kicker">THE ELEGANCE EXPERIENCE</span>

                <h2 class="display-heading">
                    Where beauty meets <em>personal care.</em>
                </h2>

                <div class="gold-line"></div>

                <p class="section-text">
                    Every appointment is designed around you — from precision hair work
                    to restorative skin and wellness. Calm space. Refined technique. Lasting results.
                </p>

                <div class="intro-stats-row">
                    <div>
                        <strong>12+</strong>
                        <span>Years</span>
                    </div>

                    <div>
                        <strong>8k+</strong>
                        <span>Clients</span>
                    </div>

                    <div>
                        <strong>25+</strong>
                        <span>Experts</span>
                    </div>
                </div>

                <a href="about.php" class="text-link">
                    Discover our story
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>


            <div class="col-lg-6">
                <div class="intro-visual">

                    <div class="intro-img-main">
                        <img
                            src="https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=1000&q=85"
                            alt="Luxury Elegance Salon Interior"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        >
                    </div>

                    <div class="intro-img-float">
                        <img
                            src="https://images.unsplash.com/photo-1521590832167-7bcbfaa6381f?auto=format&fit=crop&w=700&q=85"
                            alt="Professional Salon Styling"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        >
                    </div>

                    <div class="intro-badge">
                        <i class="fas fa-award"></i>

                        <div>
                            <strong>Premium Rated</strong>
                            <span>Client favorite</span>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>


<!-- ================= SERVICES ================= -->
<section class="services-home py-5">
    <div class="container py-lg-4">

        <div class="svc-premium-header text-center">
            <span class="section-kicker">WHAT WE OFFER</span>

            <h2>
                Signature <em>Services</em>
            </h2>

            <div class="divider mx-auto"></div>

            <p>
                Quiet luxury. Precise technique. Treatments designed to feel exceptional.
            </p>
        </div>


        <div class="svc-bento">

            <?php

            $services = [

                [
                    'img' => 'https://images.unsplash.com/photo-1562322140-8baeececf3df?auto=format&fit=crop&w=1200&q=85',
                    'tag' => 'Hair',
                    'title' => 'Hair Styling & Color',
                    'desc' => 'Precision cuts, balayage, and color crafted to your face and lifestyle.',
                    'price' => 'From $85',
                    'time' => '45–90 min',
                    'large' => true
                ],

                [
                    'img' => 'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&fit=crop&w=1000&q=85',
                    'tag' => 'Skin',
                    'title' => 'Facial & Skin Therapy',
                    'desc' => 'Restorative facials for clear, luminous skin.',
                    'price' => 'From $120',
                    'time' => '60 min',
                    'large' => false
                ],

                [
                    'img' => 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=1000&q=85',
                    'tag' => 'Beauty',
                    'title' => 'Nails & Beauty',
                    'desc' => 'Refined manicures, gel finishes, and polished detail.',
                    'price' => 'From $70',
                    'time' => '40–55 min',
                    'large' => false
                ],

                [
                    'img' => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=1000&q=85',
                    'tag' => 'Wellness',
                    'title' => 'Massage & Wellness',
                    'desc' => 'Slow, intentional bodywork to release tension and restore calm.',
                    'price' => 'From $130',
                    'time' => '60–90 min',
                    'large' => false
                ]

            ];

            foreach ($services as $s):

                $cls = !empty($s['large']) ? ' svc-tile--large' : '';

            ?>

                <article class="svc-tile<?php echo $cls; ?>">

                    <div class="svc-tile-bg">

                        <img
                            src="<?php echo htmlspecialchars($s['img']); ?>"
                            alt="<?php echo htmlspecialchars($s['title']); ?>"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        >

                        <div class="svc-tile-shade"></div>

                    </div>


                    <div class="svc-tile-content">

                        <span class="svc-tile-tag">
                            <?php echo htmlspecialchars($s['tag']); ?>
                        </span>

                        <h3>
                            <?php echo htmlspecialchars($s['title']); ?>
                        </h3>

                        <p>
                            <?php echo htmlspecialchars($s['desc']); ?>
                        </p>

                        <div class="svc-tile-meta">

                            <span>
                                <i class="far fa-clock"></i>
                                <?php echo htmlspecialchars($s['time']); ?>
                            </span>

                            <strong>
                                <?php echo htmlspecialchars($s['price']); ?>
                            </strong>

                        </div>

                        <a href="book.php" class="svc-tile-btn">
                            Book now
                            <i class="fas fa-arrow-right"></i>
                        </a>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>


        <div class="text-center mt-5">

            <a href="services.php" class="btn btn-outline-gold">
                View full menu
                <i class="fas fa-arrow-right ms-1"></i>
            </a>

        </div>

    </div>
</section>


<!-- ================= STATS ================= -->
<section class="stats-section">

    <div class="container">

        <div class="row g-0">

            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <strong>12+</strong>
                    <span>Years of Excellence</span>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <strong>8,500+</strong>
                    <span>Happy Clients</span>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <strong>25+</strong>
                    <span>Expert Stylists</span>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <strong>40+</strong>
                    <span>Premium Services</span>
                </div>
            </div>

        </div>

    </div>

</section>


<!-- ================= STYLISTS ================= -->
<section class="stylists-section py-5">

    <div class="container py-lg-4">

        <div class="section-title">

            <span class="section-kicker">OUR TEAM</span>

            <h2>
                Featured <em>Stylists</em>
            </h2>

            <div class="divider"></div>

            <p>
                Meet the experts behind every refined look.
            </p>

        </div>


        <div class="row g-4">

            <?php

            $stylists = [

                [
                    'name' => 'Shoaib Bashir',
                    'role' => 'Master Stylist',
                    'specialty' => 'Hair Color & Balayage',
                    'image' => 'assets\img\makeup-artist-02.jpeg'
                ],

                [
                    'name' => 'Meryam Asghar',
                    'role' => 'Senior Stylist',
                    'specialty' => 'Bridal & Events',
                    'image' => 'assets\img\makeup-artist-01.jpeg'
                ],

                [
                    'name' => 'Aqsa Danish',
                    'role' => 'Skin Specialist',
                    'specialty' => 'Facials & Skincare',
                    'image' => 'assets\img\makeup-artist-04.jpeg'
                ],

                [
                    'name' => 'Seema Khan',
                    'role' => 'Barber & Stylist',
                    'specialty' => "Men's Grooming",
                    'image' => 'assets\img\makeup-artist-03.jpeg'
                ]

            ];


            foreach ($stylists as $st):

            ?>

                <div class="col-md-6 col-lg-3">

                    <div class="hm-stylist">

                        <div class="hm-stylist-photo">

                            <img
                                src="<?php echo htmlspecialchars($st['image']); ?>"
                                alt="<?php echo htmlspecialchars($st['name']); ?>"
                                loading="lazy"
                                referrerpolicy="no-referrer"
                            >

                            <div class="hm-stylist-overlay"></div>

                            <a href="book.php" class="hm-stylist-book">
                                Book
                            </a>

                        </div>


                        <div class="hm-stylist-info">

                            <h4>
                                <?php echo htmlspecialchars($st['name']); ?>
                            </h4>

                            <span>
                                <?php echo htmlspecialchars($st['role']); ?>
                            </span>

                            <p>
                                <?php echo htmlspecialchars($st['specialty']); ?>
                            </p>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>


<!-- ================= WHY US ================= -->
<section class="why-section py-5">

    <div class="container py-lg-4">

        <div class="row align-items-center g-3 g-xl-5">

            <div class="col-lg-5">

                <span class="section-kicker">
                    WHY ELEGANCE
                </span>

                <h2 class="display-heading">
                    Care that goes beyond <em>the ordinary.</em>
                </h2>

                <div class="gold-line"></div>

                <p class="section-text">
                    The details that turn a salon visit into a full beauty ritual.
                </p>

                <a href="contact.php" class="btn btn-gold">
                    Visit Elegance
                    <i class="fas fa-arrow-right"></i>
                </a>

            </div>


            <div class="col-lg-7">

                <div class="row g-3">

                    <?php
$benefits = [
    [
        'image' => 'assets/img/makeup-artist-01.jpeg',
        'title' => 'Expert Stylists',
        'text' => 'Skilled professionals focused on your individual look.'
    ],
    [
        'image' => 'assets/img/equipment.jpeg',
        'title' => 'Premium Products',
        'text' => 'Trusted brands for hair, skin, and beauty.'
    ],
    [
        'image' => 'assets/img/hero.jpeg',
        'title' => 'Calm Atmosphere',
        'text' => 'A serene space for comfort and privacy.'
    ],
    [
        'image' => 'assets/img/makeup-artist-03.jpeg',
        'title' => 'Personalized Care',
        'text' => 'Every service tailored to your preferences.'
    ]
];
                    foreach ($benefits as $b):

                    ?>

<div class="col-md-6">
    <div class="hm-benefit">

        <div class="hm-benefit-icon">
            <img
                src="<?= htmlspecialchars($b['image']) ?>"
                alt="<?= htmlspecialchars($b['title']) ?>"
                class="benefit-image"
                loading="lazy"
            >
        </div>

        <h4>
            <?= htmlspecialchars($b['title']) ?>
        </h4>

        <p>
            <?= htmlspecialchars($b['text']) ?>
        </p>

    </div>
</div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ================= GALLERY ================= -->
<section class="gallery-section py-5">

    <div class="container py-lg-4">

        <div class="section-title">

            <span class="section-kicker">
                MOMENTS OF ELEGANCE
            </span>

            <h2>
                Salon <em>Gallery</em>
            </h2>

            <div class="divider"></div>

        </div>


        <div class="hm-gallery">

            <div class="hm-gal tall">

                <img
                    src="assets/img/artist.avif"
                    alt="Luxury salon styling"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                >

                <span>Artistry</span>

            </div>


            <div class="hm-gal">

                <img
                    src="assets/img/interior.avif"
                    alt="Elegant salon interior"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                >

                <span>Interior</span>

            </div>


            <div class="hm-gal">

                <img
                    src="assets/img/styling.avif"
                    alt="Professional hair styling"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                >

                <span>Details</span>

            </div>


            <div class="hm-gal wide">

                <img
                    src="assets/img/massage.jpeg"
                    alt="Relaxing wellness treatment"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                >

                <span>Wellness</span>

            </div>


            <div class="hm-gal">

                <img
                    src="assets/img/nail.avif"
                    alt="Luxury manicure and nail care"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                >

                <span>Finish</span>

            </div>

        </div>

    </div>

</section>


<!-- ================= TESTIMONIALS ================= -->
<section class="testimonials-section py-5">

    <div class="container py-lg-4">

        <div class="section-title">

            <span class="section-kicker">
                CLIENT LOVE
            </span>

            <h2>
                What clients <em>say</em>
            </h2>

            <div class="divider"></div>

        </div>


        <div class="row g-4">

            <?php

            $testimonials = [

                [
                    'name' => 'Olivia M.',
                    'text' => 'Elegance Salon is my sanctuary. Every visit feels luxurious and truly relaxing.',
                    'role' => 'Regular Client',
                          'image' => 'assets/img/client.jpg'
                ],

                [
                    'name' => 'Ayesha K.',
                    'text' => 'The stylists truly understand what you want. My hair has never looked better.',
                    'role' => 'Verified Client',
                          'image' => 'assets/img/client2.jpeg'
                ],

                [
                    'name' => 'Sara R.',
                    'text' => 'Beautiful atmosphere, professional staff, exceptional service. Highly recommend.',
                    'role' => 'Bridal Client',
                          'image' => 'assets/img/client3.jpeg'
                ]

            ];

            foreach ($testimonials as $t):

            ?>

                <div class="col-md-4">

                    <div class="hm-quote">

                        <div class="hm-quote-stars">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                        </div>

                        <p>
                            “<?php echo htmlspecialchars($t['text']); ?>”
                        </p>

                        <div class="hm-quote-author">

                              <div class="hm-benefit-icon">
            <img
                src="<?= htmlspecialchars($b['image']) ?>"
                alt="<?= htmlspecialchars($b['title']) ?>"
                class="benefit-image"
                loading="lazy"
            >
        </div>

                            <div>

                                <strong>
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </strong>

                                <span>
                                    <?php echo htmlspecialchars($t['role']); ?>
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>


<!-- ================= CTA ================= -->
<section class="home-cta py-5">

    <div class="container py-lg-4">

        <div class="cta-box cta-vip">

            <span class="section-kicker">
                YOUR NEXT MOMENT OF SELF-CARE
            </span>

            <h2>
                Ready for your<br>
                <em>Elegance experience?</em>
            </h2>

            <p>
                Reserve your appointment — we’ll take care of the rest.
            </p>

            <a href="book.php" class="btn btn-gold btn-lg">
                <i class="fas fa-calendar-check"></i>
                Book Your Appointment
            </a>

        </div>

    </div>

</section>


<?php require_once 'includes/footer.php'; ?>