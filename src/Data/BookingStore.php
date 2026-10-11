<?php

declare(strict_types=1);

namespace App\Data;

use App\Domain\Booking;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * SQLite-backed booking storage. Seeded once from data.json, then new bookings are added here
 * (data.json itself is never modified). Times are stored as UTC ISO strings so that plain
 * string comparison in SQL is chronological, whatever offset the input used.
 */
final class BookingStore
{
    private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    public static function open(string $path): self
    {
        return new self(new PDO('sqlite:' . $path));
    }

    /** Imports the data.json bookings the first time the database is created. */
    public function seedOnce(array $bookings): void
    {
        $this->transaction(function () use ($bookings): void {
            if ($this->pdo->query("SELECT 1 FROM meta WHERE key = 'seeded'")->fetchColumn()) {
                return;
            }
            foreach ($bookings as $booking) {
                $this->insert($booking);
            }
            $this->pdo->exec("INSERT INTO meta (key, value) VALUES ('seeded', datetime('now'))");
        });
    }

    /** @return Booking[] confirmed bookings overlapping [$from, $to) */
    public function confirmedBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bookings WHERE status = 'confirmed' AND start_utc < :to AND end_utc > :from ORDER BY start_utc"
        );
        $stmt->execute(['from' => self::utc($from), 'to' => self::utc($to)]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Inserts the booking unless the staff member already has an overlapping confirmed booking.
     * BEGIN IMMEDIATE takes the write lock before the check, so two concurrent requests for the
     * same slot cannot both pass it.
     */
    public function insertIfFree(Booking $booking): bool
    {
        return $this->transaction(function () use ($booking): bool {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM bookings WHERE staff_id = :staff AND status = 'confirmed' AND start_utc < :end AND end_utc > :start LIMIT 1"
            );
            $stmt->execute(['staff' => $booking->staffId, 'start' => self::utc($booking->start), 'end' => self::utc($booking->end)]);
            if ($stmt->fetchColumn()) {
                return false;
            }
            $this->insert($booking);

            return true;
        });
    }

    private function insert(Booking $booking): void
    {
        $this->pdo->prepare(
            'INSERT INTO bookings (id, staff_id, service_id, start_utc, end_utc, status, customer_name, created_at)
             VALUES (:id, :staff, :service, :start, :end, :status, :customer, :created)'
        )->execute([
            'id' => $booking->id,
            'staff' => $booking->staffId,
            'service' => $booking->serviceId,
            'start' => self::utc($booking->start),
            'end' => self::utc($booking->end),
            'status' => $booking->status,
            'customer' => $booking->customerName,
            'created' => self::utc(new DateTimeImmutable()),
        ]);
    }

    private function transaction(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookings (
                id TEXT PRIMARY KEY,
                staff_id INTEGER NOT NULL,
                service_id INTEGER NOT NULL,
                start_utc TEXT NOT NULL,
                end_utc TEXT NOT NULL,
                status TEXT NOT NULL,
                customer_name TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS bookings_staff_start ON bookings (staff_id, start_utc)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
    }

    private static function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT);
    }

    private static function hydrate(array $row): Booking
    {
        return new Booking(
            id: $row['id'],
            staffId: (int) $row['staff_id'],
            serviceId: (int) $row['service_id'],
            start: new DateTimeImmutable($row['start_utc']),
            end: new DateTimeImmutable($row['end_utc']),
            status: $row['status'],
            customerName: $row['customer_name'],
        );
    }
}
