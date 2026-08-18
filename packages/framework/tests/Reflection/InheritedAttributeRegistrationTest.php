<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection;

use Kinetis\Console\CommandRegistry;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Reflection\Exception\AttributeScopeException;
use Kinetis\Tests\Reflection\Fixtures\AbstractCommandBase;
use Kinetis\Tests\Reflection\Fixtures\AbstractListenerBase;
use Kinetis\Tests\Reflection\Fixtures\AbstractToolBase;
use Kinetis\Tests\Reflection\Fixtures\InheritsCommand;
use Kinetis\Tests\Reflection\Fixtures\InheritsListener;
use Kinetis\Tests\Reflection\Fixtures\InheritsTool;
use PHPUnit\Framework\TestCase;

/**
 * The rule is the same in every registry that reflects a class for
 * attributes, not just the router — otherwise #[Command] and #[Listener]
 * would keep honouring a parent's attributes while the registered class's
 * own went unread.
 */
final class InheritedAttributeRegistrationTest extends TestCase
{
    public function test_command_registry_rejects_an_inherited_command(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(AbstractCommandBase::class);

        new CommandRegistry()->register(InheritsCommand::class);
    }

    public function test_command_registry_rejects_an_abstract_class(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage('is abstract and cannot be registered');

        new CommandRegistry()->register(AbstractCommandBase::class);
    }

    public function test_mcp_registry_rejects_an_inherited_tool(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(AbstractToolBase::class);

        new McpRegistry()->register(InheritsTool::class);
    }

    public function test_mcp_registry_rejects_an_abstract_class(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage('is abstract and cannot be registered');

        new McpRegistry()->register(AbstractToolBase::class);
    }

    public function test_event_listener_registry_rejects_an_inherited_listener(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(AbstractListenerBase::class);

        new EventListenerRegistry()->register(InheritsListener::class);
    }

    public function test_event_listener_registry_rejects_an_abstract_class(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage('is abstract and cannot be registered');

        new EventListenerRegistry()->register(AbstractListenerBase::class);
    }
}
