
<?php

$pageTitle = "Services";
$activePage = "services";

require_once 'config/database.php';
require_once 'includes/header.php';

/* =====================================================
   ELEGANCE SALON — SERVICES MENU
   4 categories | 7 services per category
   ===================================================== */

$categories = [

    'Hair' => [
        ['name' => 'Precision Haircut', 'description' => 'Face-framing cut tailored to your features and lifestyle.', 'duration' => 45, 'price' => 65, 'img' => 'assets\img\hair1.jfif'],
        ['name' => 'Balayage & Highlights', 'description' => 'Hand-painted dimension with soft, sun-kissed blend.', 'duration' => 120, 'price' => 165, 'img' => 'assets\img\hair2.jfif'],
        ['name' => 'Full Color Transformation', 'description' => 'Complete color change with premium products and gloss.', 'duration' => 90, 'price' => 140, 'img' => 'assets\img\hair3.jfif'],
        ['name' => 'Blowout & Styling', 'description' => 'Salon-smooth finish for events, shoots, or everyday glam.', 'duration' => 40, 'price' => 55, 'img' => 'assets\img\hair4.jfif'],
        ['name' => 'Keratin Smoothing', 'description' => 'Frizz control and silk-soft movement that lasts weeks.', 'duration' => 150, 'price' => 220, 'img' => 'assets\img\hair5.jfif'],
        ['name' => 'Bridal Hair Styling', 'description' => 'Romantic updos and soft waves designed for your day.', 'duration' => 90, 'price' => 180, 'img' => 'assets\img\hair6.jfif'],
    ],

    'Skin' => [
        ['name' => 'Signature Facial', 'description' => 'Deep cleanse, extract, and glow — customized to your skin.', 'duration' => 60, 'price' => 95, 'img' => 'assets\img\skin1.jfif'],
        ['name' => 'Anti-Aging Treatment', 'description' => 'Firming, lifting care for refined texture and radiance.', 'duration' => 75, 'price' => 140, 'img' => 'assets\img\skin2.jfif'],
        ['name' => 'Hydra Glow Facial', 'description' => 'Intense hydration for dry, dull, or travel-tired skin.', 'duration' => 50, 'price' => 110, 'img' => 'assets\img\skin3.jfif'],
        ['name' => 'Acne Clarity Facial', 'description' => 'Purifying treatment to calm breakouts and refine pores.', 'duration' => 60, 'price' => 105, 'img' => 'assets\img\skin4.jfif'],
        ['name' => 'Microdermabrasion', 'description' => 'Gentle resurfacing for smoother, brighter skin.', 'duration' => 45, 'price' => 125, 'img' => 'assets\img\skin5.jfif'],
        ['name' => 'LED Light Therapy', 'description' => 'Targeted light treatment for calm, even complexion.', 'duration' => 30, 'price' => 70, 'img' => 'assets\img\skin6.jfif'],
     ],

    'Beauty' => [
        ['name' => 'Gel Manicure', 'description' => 'Chip-resistant color with a glass-smooth finish.', 'duration' => 45, 'price' => 45, 'img' => 'assets\img\beauty1.jfif'],
        ['name' => 'Luxury Pedicure', 'description' => 'Soak, sculpt, and polish — pure foot indulgence.', 'duration' => 55, 'price' => 55, 'img' => 'assets\img\beauty2.jfif'],
        ['name' => 'Nail Art Detail', 'description' => 'Minimal chrome, soft florals, or full statement design.', 'duration' => 30, 'price' => 35, 'img' => 'assets\img\beauty3.jfif'],
        ['name' => 'Classic Lash Lift', 'description' => 'Lifted, open eyes without extensions.', 'duration' => 50, 'price' => 75, 'img' => 'assets\img\beauty4.jfif'],
        ['name' => 'Brow Sculpt & Tint', 'description' => 'Shape, tint, and define for a polished frame.', 'duration' => 35, 'price' => 40, 'img' => 'assets\img\beauty5.jfif'],
        ['name' => 'Soft Glam Makeup', 'description' => 'Camera-ready glow for dinners, dates, and events.', 'duration' => 60, 'price' => 95, 'img' => 'assets\img\beaty6.jfif'],
    ],

    'Wellness' => [
        ['name' => 'Relaxation Massage', 'description' => 'Full-body ease to melt tension and quiet the mind.', 'duration' => 60, 'price' => 110, 'img' => 'assets\img\wellness1.jfif'],
        ['name' => 'Deep Tissue Massage', 'description' => 'Focused pressure for tight muscles and posture stress.', 'duration' => 75, 'price' => 135, 'img' => 'assets\img\wellness2.jfif'],
        ['name' => 'Hot Stone Therapy', 'description' => 'Warm stones + skilled hands for deep release.', 'duration' => 75, 'price' => 145, 'img' => 'assets\img\wellness3.jfif'],
        ['name' => 'Aromatherapy Session', 'description' => 'Scented oils chosen for calm, energy, or balance.', 'duration' => 60, 'price' => 120, 'img' => 'assets\img\wellness4.jfif'],
        ['name' => 'Scalp & Neck Ritual', 'description' => 'Tension release for screen-heavy days.', 'duration' => 30, 'price' => 55, 'img' => 'assets\img\wellness5.jfif'],
        ['name' => 'Couples Massage', 'description' => 'Side-by-side relaxation in a private suite feel.', 'duration' => 60, 'price' => 210, 'img' => 'assets\img\wellness6.jfif'],
    ],

];

$catMeta = [
    'Hair'     => ['icon' => 'fa-scissors', 'label' => 'Style & color'],
    'Skin'     => ['icon' => 'fa-spa', 'label' => 'Glow & restore'],
    'Beauty'   => ['icon' => 'fa-hand-sparkles', 'label' => 'Polish & glam'],
    'Wellness' => ['icon' => 'fa-hot-tub-person', 'label' => 'Calm & release'],
];

?>

<link rel="stylesheet" href="assets/css/services.css">

<!-- ONE CONTINUOUS BACKGROUND FOR HERO AND MENU -->
<section class="sp-main-section">

    <!-- HERO -->
    <section class="sp-hero">

        <div class="sp-hero-bg"></div>

        <div class="container position-relative">

            <h1>THE <em>MENU</em></h1>
            <p>
                Six to seven signature treatments in every category —
                crafted to feel premium from the first touch.
            </p>
        </div>

    </section>

    <!-- SERVICE MENU -->
    <div class="sp-menu" id="menu">

        <div class="container py-lg-4">

            <?php
            $serviceIdCounter = 1;

            foreach ($categories as $catName => $items):
                $meta = $catMeta[$catName] ?? [
                    'icon' => 'fa-star',
                    'label' => 'Featured'
                ];
            ?>

                <div class="sp-category">

                    <!-- CATEGORY HEADER -->
                    <div class="sp-category-head">

                        <div class="sp-category-icon">
                            <?php if ($meta['icon'] === 'salon-mark'): ?>
                                <img src="assets/img/logo.png" class="salon-mark-icon" alt="" aria-hidden="true">
                            <?php else: ?>
                                <i class="fas <?= htmlspecialchars($meta['icon']); ?>"></i>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h2><?= htmlspecialchars($catName); ?></h2>

                            <span>
                                <?= count($items); ?> treatments
                                · <?= htmlspecialchars($meta['label']); ?>
                            </span>
                        </div>

                    </div>

                    <!-- SERVICE CARDS -->
                    <div class="sp-grid">

                        <?php foreach ($items as $service): ?>

                            <article class="sp-card">

                                <div class="sp-card-media">

                                    <img
                                        src="<?= htmlspecialchars($service['img']); ?>"
                                        alt="<?= htmlspecialchars($service['name']); ?>"
                                        loading="lazy"
                                        referrerpolicy="no-referrer"
                                        onerror="this.onerror=null;this.src='assets/img/hero.jpeg';"
                                    >

                                    <div class="sp-card-media-shade"></div>

                                    <span class="sp-card-price">
                                        $<?= number_format((float) $service['price'], 0); ?>
                                    </span>

                                </div>

                                <div class="sc-card-body sp-card-body">

                                    <span class="sp-duration">
                                        <i class="far fa-clock"></i>
                                        <?= (int) $service['duration']; ?> min
                                    </span>

                                    <h3><?= htmlspecialchars($service['name']); ?></h3>

                                    <p><?= htmlspecialchars($service['description']); ?></p>

                                    <a
                                        href="book.php?service_id=<?= $serviceIdCounter; ?>"
                                        class="sp-book"
                                    >
                                        Reserve
                                        <i class="fas fa-arrow-right"></i>
                                    </a>

                                </div>

                            </article>

                            <?php $serviceIdCounter++; ?>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>
<!-- END CONTINUOUS HERO + MENU SECTION -->


<?php require_once 'includes/footer.php'; ?>
