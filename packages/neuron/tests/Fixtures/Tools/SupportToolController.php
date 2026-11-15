<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Tools;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\McpProtocol\ToolResult;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\ObjectMap;
use RuntimeException;

final readonly class SupportToolController
{
    public function __construct(private Caller $caller) {}

    /** @return array<string, mixed> */
    #[McpTool(name: 'find_orders', description: 'Find a customer\'s orders')]
    public function findOrders(
        string $customer,
        #[GreaterThan(0)]
        int $limit = 10,
        ?string $note = null,
        float $minTotal = 0.0,
        bool $openOnly = false,
    ): array {
        return ['customer' => $customer, 'limit' => $limit, 'note' => $note, 'minTotal' => $minTotal, 'openOnly' => $openOnly];
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'file_ticket', description: 'File a support ticket')]
    public function fileTicket(TicketRequest $ticket): array
    {
        return [
            'title' => $ticket->title,
            'email' => $ticket->contact->email,
            'tags' => $ticket->tags,
            'priority' => $ticket->priority->value,
            'note' => $ticket->note,
        ];
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'update_contact', description: 'Replace the contact, or clear it with null')]
    public function updateContact(?Contact $contact): array
    {
        return ['email' => $contact?->email];
    }

    /**
     * @param array<string, mixed> $labels
     * @return array<string, mixed>
     */
    #[McpTool(name: 'label_order', description: 'Attach free-form labels to an order')]
    public function labelOrder(#[ObjectMap] array $labels): array
    {
        return $labels;
    }

    #[McpTool(name: 'who_am_i', description: 'Name the caller')]
    public function whoAmI(): string
    {
        return $this->caller->name;
    }

    #[McpTool(name: 'refuse', description: 'Refuse with a reason')]
    public function refuse(): ToolResult
    {
        return ToolResult::error('Refunds need a manager.');
    }

    #[McpTool(name: 'explode', description: 'Fail unexpectedly')]
    public function explode(): never
    {
        throw new RuntimeException('database unavailable');
    }
}
