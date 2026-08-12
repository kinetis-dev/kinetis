<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Exception\InvalidCommandException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reflects a class for #[Command] methods — the same shape
 * Router::register()/McpRegistry::register() already use for their own
 * attributes. Validates each method's signature at registration time
 * (zero parameters, or exactly one CommandArguments-typed one) rather
 * than at dispatch time, the same fail-fast discipline
 * EventListenerRegistry::register() already applies to #[Listener].
 */
final class CommandRegistry
{
    /** @var list<CommandDefinition> */
    private array $commands = [];

    /**
     * @param class-string $class
     * @throws InvalidCommandException
     */
    public function register(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Command::class) as $attribute) {
                $command = $attribute->newInstance();
                $takesArguments = $this->validateSignature($class, $method);

                $existing = $this->findCommand($command->name);

                if ($existing !== null) {
                    throw InvalidCommandException::duplicateName($command->name, $class, $method->getName());
                }

                $this->commands[] = new CommandDefinition(
                    name: $command->name,
                    description: $command->description,
                    controllerClass: $class,
                    controllerMethod: $method->getName(),
                    takesArguments: $takesArguments,
                );
            }
        }
    }

    /**
     * @return list<CommandDefinition>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function findCommand(string $name): ?CommandDefinition
    {
        foreach ($this->commands as $command) {
            if ($command->name === $name) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Dumps every registered command's fields verbatim — all already
     * plain scalars, so nothing here needs reflection to reverse. Used by
     * Kinetis\Cache\Compiler.
     *
     * @return list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (CommandDefinition $command): array => [
                'name' => $command->name,
                'description' => $command->description,
                'controllerClass' => $command->controllerClass,
                'controllerMethod' => $command->controllerMethod,
                'takesArguments' => $command->takesArguments,
            ],
            $this->commands,
        );
    }

    /**
     * Reconstructs a CommandRegistry from toArray()'s output with zero
     * reflection — the compiled-cache load path's counterpart to
     * register(). Duplicate-name checking doesn't need to run again here:
     * toArray() only ever dumps a registry that already passed it once.
     *
     * @param list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool}> $data
     */
    public static function fromArray(array $data): self
    {
        $registry = new self();

        foreach ($data as $command) {
            $registry->commands[] = new CommandDefinition(
                name: $command['name'],
                description: $command['description'],
                controllerClass: $command['controllerClass'],
                controllerMethod: $command['controllerMethod'],
                takesArguments: $command['takesArguments'],
            );
        }

        return $registry;
    }

    /**
     * @throws InvalidCommandException
     */
    private function validateSignature(string $class, ReflectionMethod $method): bool
    {
        $parameters = $method->getParameters();

        if ($parameters === []) {
            return false;
        }

        $type = $parameters[0]->getType();

        if (count($parameters) !== 1 || !$type instanceof ReflectionNamedType || $type->getName() !== CommandArguments::class) {
            throw InvalidCommandException::forMethod($class, $method->getName());
        }

        return true;
    }
}
