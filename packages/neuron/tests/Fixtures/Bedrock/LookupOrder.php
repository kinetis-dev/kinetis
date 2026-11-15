<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Bedrock;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class LookupOrder extends Tool
{
    protected string $name = 'lookup_order';

    protected ?string $description = 'Look up an order by its ID.';

    protected function properties(): array
    {
        return [new ToolProperty('id', PropertyType::STRING, 'The order ID.', true)];
    }

    public function __invoke(string $id): string
    {
        return "Order {$id} has shipped.";
    }
}
