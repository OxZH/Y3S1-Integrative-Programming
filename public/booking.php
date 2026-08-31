<?php
/**
 * Stand-in for the Venue Booking & Payment module's booking screen.
 * Author: Goh Jian Yu
 *
 * DELETE THIS FILE once that module is ready, and point
 * EventController::store() at their screen instead. It exists only so the
 * event creation flow can be walked end to end before their part lands.
 *
 * It writes the Booking and Payment rows that this module then reads back over
 * the web service, and returns to event&a=finalise. It has no reservation
 * calendar, no Stripe, no refunds - all of that is theirs.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AuthorizationException;
use App\Core\Database;
use App\Core\View;
use App\Domain\EventManagementFacade;
use App\NotFoundException;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\EventFacilitySecurity;

/**
 * This is an entry point of its own, so it needs the same error handling the
 * front controller has. Without it an ownership failure escapes as an uncaught
 * exception, which answers 200 and prints the stack trace - file paths, class
 * names and all - straight to the browser.
 */
function bookingError(int $status, string $heading, string $message): never
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    echo View::render('error', [
        'title'   => $heading,
        'status'  => $status,
        'heading' => $heading,
        'message' => $message,
        'detail'  => null,
        'flash'   => [],
    ]);

    exit;
}

try {
    $eventId = $_GET['eventId'] ?? $_POST['eventId'] ?? '';

    if (!is_string($eventId) || $eventId === '') {
        header('Location: index.php?c=event&a=mine');
        exit;
    }

    $facade = new EventManagementFacade();
    $event  = $facade->viewEvent($eventId);

    // The organiser is the only person who can pay for their own event.
    EventFacilitySecurity::assertHostsEvent($event);

    $facility = $event->getLocation();
    $amount   = $event->quoteVenueCost();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::check($_POST);

        $pdo = Database::getConnection();

        // One booking per event, so a refresh must not create a second.
        $existing = $pdo->prepare('SELECT `bookingId` FROM `Booking` WHERE `eventId` = :eventId LIMIT 1');
        $existing->execute([':eventId' => $eventId]);
        $bookingId = $existing->fetchColumn();

        if ($bookingId === false) {
            $bookingId = uuid();

            $pdo->prepare(
                'INSERT INTO `Booking` (`bookingId`, `eventId`, `madeById`, `bookingStatus`, `bookingAmount`)
                 VALUES (:bookingId, :eventId, :madeById, :status, :amount)'
            )->execute([
                ':bookingId' => $bookingId,
                ':eventId'   => $eventId,
                ':madeById'  => Auth::id(),
                ':status'    => 'CONFIRMED',
                ':amount'    => number_format($amount, 2, '.', ''),
            ]);
        } else {
            $pdo->prepare('UPDATE `Booking` SET `bookingStatus` = :s WHERE `bookingId` = :id')
                ->execute([':s' => 'CONFIRMED', ':id' => $bookingId]);
        }

        $payment = $pdo->prepare('SELECT `paymentId` FROM `Payment` WHERE `bookingId` = :bookingId LIMIT 1');
        $payment->execute([':bookingId' => $bookingId]);

        if ($payment->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO `Payment` (`paymentId`, `bookingId`, `amount`, `paymentDateTime`,
                                        `paymentMethod`, `paymentStatus`)
                 VALUES (:paymentId, :bookingId, :amount, NOW(), :method, :status)'
            )->execute([
                ':paymentId' => uuid(),
                ':bookingId' => $bookingId,
                ':amount'    => number_format($amount, 2, '.', ''),
                ':method'    => 'card',
                ':status'    => 'PAID',
            ]);
        } else {
            $pdo->prepare('UPDATE `Payment` SET `paymentStatus` = :s WHERE `bookingId` = :id')
                ->execute([':s' => 'PAID', ':id' => $bookingId]);
        }

        header('Location: index.php?c=event&a=finalise&id=' . urlencode($eventId));
        exit;
    }

    $body = '
<h1>Book the venue</h1>
<p class="lede">Pay the venue owner to confirm the booking.</p>

<div class="banner">
    Placeholder for the Venue Booking &amp; Payment module. It records the booking and payment so the
    rest of the flow can be tested, and does not take a real payment.
</div>

<div class="card">
    <h2>' . e($event->getName()) . '</h2>
    <div class="stat">
        <div><span>Venue</span><strong>' . e($facility?->getName() ?? 'unknown') . '</strong></div>
        <div><span>Date</span><strong>' . e($event->getEventDate()->format('D, d M Y')) . '</strong></div>
        <div><span>Time</span><strong>' . e(hhmm($event->getStartTime())) . '&ndash;' . e(hhmm($event->getEndTime())) . '</strong></div>
        <div><span>Duration</span><strong>' . e(number_format($event->getDurationHours(), 1)) . ' h</strong></div>
    </div>
    <div class="stat">
        <div><span>Amount due</span><strong>' . e(money($amount)) . '</strong></div>
    </div>

    <form method="post" action="booking.php">
        ' . Csrf::field() . '
        <input type="hidden" name="eventId" value="' . e($eventId) . '">
        <button class="btn" type="submit">Pay ' . e(money($amount)) . ' and confirm</button>
        <a class="btn ghost" href="index.php?c=event&amp;a=show&amp;id=' . e($eventId) . '">Not now</a>
    </form>
</div>';

    echo View::render('layout', [
        'title'     => 'Book the venue',
        'content'   => $body,
        'flash'     => [],
        'csrfField' => Csrf::field(),
    ]);
} catch (AuthorizationException $e) {
    bookingError(403, 'Not allowed', $e->getMessage());
} catch (NotFoundException $e) {
    bookingError(404, 'Not found', $e->getMessage());
} catch (Throwable $e) {
    error_log('booking.php: ' . $e->getMessage());

    bookingError(500, 'Something went wrong', 'The booking screen could not be loaded.');
}
