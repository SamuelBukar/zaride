<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$customerId = requireAccount('customer');
$bookingId = (int) ($_GET['booking_id'] ?? ($_POST['booking_id'] ?? 0));

if ($bookingId <= 0) {
    setFlash('error', 'Booking ID is required.');
    header('Location: dashboard.php?type=customer');
    exit;
}

// Fetch booking
$bStmt = db()->prepare(
    "SELECT b.*, r.full_name as rider_name, r.id as rider_id, r.verification_status
     FROM bookings b
     JOIN riders r ON b.rider_id = r.id
     WHERE b.id = ? AND b.customer_id = ?"
);
$bStmt->execute([$bookingId, $customerId]);
$booking = $bStmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found or does not belong to your account.');
    header('Location: dashboard.php?type=customer');
    exit;
}

if ($booking['status'] !== 'completed') {
    setFlash('error', 'You can leave a review only after the trip or delivery has been completed.');
    header('Location: dashboard.php?type=customer');
    exit;
}

// Check existing review
$checkStmt = db()->prepare("SELECT id FROM reviews WHERE booking_id = ? AND reviewer_type = 'customer'");
$checkStmt->execute([$bookingId]);
if ($checkStmt->fetch()) {
    setFlash('error', 'You have already submitted a review for this trip.');
    header('Location: dashboard.php?type=customer');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        setFlash('error', 'Session verification failed. Please try again.');
        header('Location: review.php?booking_id=' . $bookingId);
        exit;
    }

    $rating = (int) ($_POST['rating'] ?? 5);
    $rating = max(1, min(5, $rating));
    $comment = trim($_POST['comment'] ?? '');

    try {
        $rIns = db()->prepare(
            "INSERT INTO reviews (booking_id, reviewer_type, customer_id, rider_id, rating, comment)
             VALUES (?, 'customer', ?, ?, ?, ?)"
        );
        $rIns->execute([$bookingId, $customerId, $booking['rider_id'], $rating, $comment ?: null]);

        updateRiderRatingStats((int) $booking['rider_id']);

        setFlash('success', 'Thank you! Your rating and feedback have been published.');
        header('Location: dashboard.php?type=customer');
        exit;
    } catch (PDOException $e) {
        error_log('Failed to submit review: ' . $e->getMessage());
        setFlash('error', 'Could not save review. Please try again.');
        header('Location: review.php?booking_id=' . $bookingId);
        exit;
    }
}

$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Rate Your Experience with <?= e($booking['rider_name']) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Reviews</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="dashboard.php?type=customer">← Back to Dashboard</a>
        </nav>
    </header>

    <main class="container" style="max-width: 580px; padding-top: 48px; padding-bottom: 70px;">
        <?php if ($flash): ?>
            <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 36px 30px; box-shadow: var(--shadow-md);">
            <div style="text-align: center; margin-bottom: 24px;">
                <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary);">Trip Completed</span>
                <h1 style="font-size: 24px; font-weight: 800; margin-bottom: 6px;">How was your journey?</h1>
                <p style="color: var(--muted); font-size: 14px;">Leave peer feedback for <strong><?= e($booking['rider_name']) ?></strong> <?= renderVerifiedTag($booking) ?></p>
            </div>

            <div style="background: var(--surface-alt); border-radius: var(--radius-md); padding: 14px; margin-bottom: 22px; font-size: 13px; color: var(--muted);">
                <div>📍 <strong>Route:</strong> <?= e($booking['pickup_location']) ?> → <?= e($booking['dropoff_location']) ?></div>
                <div>📅 <strong>Date:</strong> <?= formatDateTime($booking['scheduled_at']) ?></div>
            </div>

            <form method="post" action="review.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

                <div class="form-group" style="text-align: center;">
                    <label class="form-label" style="margin-bottom: 10px;">Select Rating</label>
                    <div style="display: flex; justify-content: center; gap: 14px; font-size: 32px;" id="star-selector">
                        <label style="cursor: pointer;"><input type="radio" name="rating" value="1" style="display: none;"><span class="star-icon">★</span></label>
                        <label style="cursor: pointer;"><input type="radio" name="rating" value="2" style="display: none;"><span class="star-icon">★</span></label>
                        <label style="cursor: pointer;"><input type="radio" name="rating" value="3" style="display: none;"><span class="star-icon">★</span></label>
                        <label style="cursor: pointer;"><input type="radio" name="rating" value="4" style="display: none;"><span class="star-icon">★</span></label>
                        <label style="cursor: pointer;"><input type="radio" name="rating" value="5" checked style="display: none;"><span class="star-icon">★</span></label>
                    </div>
                    <div id="rating-desc" style="font-size: 13px; font-weight: 700; color: var(--primary); margin-top: 6px;">5.0 — Excellent Driver & Safe Journey</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="comment">Your Feedback & Review (Optional)</label>
                    <textarea class="form-control" id="comment" name="comment" placeholder="Punctual, friendly, clean car, smooth highway driving, handled parcel with great care..."></textarea>
                </div>

                <button class="button button-primary button-full button-lg" type="submit">Submit Peer Review →</button>
            </form>
        </div>
    </main>

    <script>
    const stars = document.querySelectorAll('#star-selector label');
    const desc = document.getElementById('rating-desc');
    const descs = {
        1: '1.0 — Poor Experience',
        2: '2.0 — Below Average',
        3: '3.0 — Good Journey',
        4: '4.0 — Very Good Driver',
        5: '5.0 — Excellent Driver & Safe Journey'
    };

    function updateStars(val) {
        stars.forEach((l, idx) => {
            const star = l.querySelector('.star-icon');
            if (idx < val) {
                star.style.color = '#f59e0b';
            } else {
                star.style.color = '#cbd5e1';
            }
        });
        desc.innerText = descs[val] || '';
    }

    stars.forEach((label, idx) => {
        label.addEventListener('click', () => {
            const input = label.querySelector('input');
            input.checked = true;
            updateStars(idx + 1);
        });
    });

    updateStars(5);
    </script>
    <?= renderMobileBottomNav('account') ?>
</body>
</html>
