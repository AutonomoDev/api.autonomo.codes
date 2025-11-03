<?php declare(strict_types=1);
// ==== src/WAHA/Services/FirebaseTicketService.php ====

namespace Autonomo\API\WAHA\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Database;
use DateTimeInterface;
use Exception;
use RuntimeException;

class FirebaseTicketService
{
    private Database $database;

    public function __construct(string $serviceAccountPath, string $databaseUrl)
    {
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withDatabaseUri($databaseUrl);

        $this->database = $factory->createDatabase();
    }

    /**
     * Creates or updates a support ticket in Firebase.
     *
     * @param array $ticket
     * @return string The Firebase reference key.
     * @throws Exception
     */
    public function saveTicket(array $ticket): string
    {
        if (empty($ticket['ticketId'])) {
            throw new Exception('ticketId is required.');
        }

        // Force ISO 8601 timestamps
        $ticket['timestamp'] ??= (new \DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $reference = $this->database->getReference('tickets/' . $ticket['ticketId']);
        $reference->set($ticket);

        return $reference->getKey();
    }

    /**
     * Retrieves a ticket by its ID.
     *
     * @param string $ticketId
     * @return array|null
     */
    public function getTicket(string $ticketId): ?array
    {
        $snapshot = $this->database->getReference('tickets/' . $ticketId)->getSnapshot();
        return $snapshot->exists() ? $snapshot->getValue() : null;
    }

    /**
     * Lists all tickets (optionally filtered by status or priority).
     *
     * @param string|null $status
     * @param string|null $priority
     * @return array
     */
    public function listTickets(?string $status = null, ?string $priority = null): array
    {
        $tickets = $this->database->getReference('tickets')->getValue() ?? [];

        if ($status !== null || $priority !== null) {
            $tickets = array_filter($tickets, static function ($t) use ($status, $priority) {
                return (!$status || ($t['status'] ?? null) === $status)
                    && (!$priority || ($t['priority'] ?? null) === $priority);
            });
        }

        return $tickets;
    }

    /**
     * Deletes a ticket by its ID.
     *
     * @param string $ticketId
     * @param bool $throwIfNotExists Whether to throw an exception if ticket doesn't exist
     * @return bool True if deleted, false if ticket didn't exist (when $throwIfNotExists is false)
     * @throws RuntimeException If ticket doesn't exist and $throwIfNotExists is true
     * @throws \Kreait\Firebase\Exception\DatabaseException
     */
    public function deleteTicket(string $ticketId, bool $throwIfNotExists = false): bool
    {
        $ticketRef = $this->database->getReference('tickets/' . $ticketId);

        $snapshot = $ticketRef->getSnapshot();
        if (!$snapshot->exists()) {
            if ($throwIfNotExists) {
                throw new RuntimeException("Ticket {$ticketId} does not exist.");
            }
            return false;
        }

        $ticketRef->remove();
        return true;
    }

    /**
     * Appends a new message to a ticket conversation.
     *
     * @param string $ticketId
     * @param string $role
     * @param string $content
     * @return void
     * @throws RuntimeException|\Kreait\Firebase\Exception\DatabaseException
     */
    public function addConversationMessage(string $ticketId, string $role, string $content): void
    {
        $ticketRef = $this->database->getReference('tickets/' . $ticketId);

        $snapshot = $ticketRef->getSnapshot();
        if (!$snapshot->exists()) {
            throw new RuntimeException("Ticket {$ticketId} does not exist.");
        }

        $ticket = $snapshot->getValue();
        $ticket['conversation'][] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => (new \DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        $ticketRef->set($ticket);
    }
}