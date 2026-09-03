<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Exception\InvalidCommandException;
use Kinetis\Reflection\AttributeScope;
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
    private const array COMMAND_ENTRY_KEYS = [
        'name', 'description', 'controllerClass', 'controllerMethod', 'takesArguments', 'bootstrap',
    ];

    private const string ARTIFACT_COMPONENT = 'CommandRegistry command';

    /** @var list<CommandDefinition> */
    private array $commands = [];

    /**
     * @param class-string $class
     * @throws InvalidCommandException
     */
    public function register(string $class): void
    {
        $reflection = AttributeScope::reflect($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Command::class) as $attribute) {
                AttributeScope::assertDeclares($method, $class);

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
                    bootstrap: $command->bootstrap,
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
     * @return list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}>
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
                'bootstrap' => $command->bootstrap,
            ],
            $this->commands,
        );
    }

    /**
     * Reconstructs a CommandRegistry from toArray()'s output with zero
     * reflection — the compiled-cache load path's counterpart to
     * register(). Re-checks the same duplicate-name invariant register()
     * enforces rather than trusting a compiled artifact still holds it —
     * a hand-edited or otherwise corrupt file could carry two commands
     * sharing a name even though `toArray()` itself never would.
     *
     * @param list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        if (!array_is_list($data)) {
            throw InvalidCacheArtifactException::wrongFieldType('CommandRegistry', 'commands', 'a list');
        }

        $registry = new self();

        foreach ($data as $command) {
            if (!is_array($command)) {
                throw InvalidCacheArtifactException::malformedEntry(self::ARTIFACT_COMPONENT, 'a non-array entry');
            }

            ArtifactValidation::exactKeys($command, self::ARTIFACT_COMPONENT, self::COMMAND_ENTRY_KEYS);

            $name = ArtifactValidation::string($command, self::ARTIFACT_COMPONENT, 'name');

            // The same duplicate-name check register() applies at
            // registration time — a compiled artifact carrying two
            // commands sharing a name bypasses that live invariant
            // otherwise.
            if ($registry->findCommand($name) !== null) {
                throw InvalidCacheArtifactException::malformedEntry('CommandRegistry', "duplicate command name \"{$name}\"");
            }

            $registry->commands[] = new CommandDefinition(
                name: $name,
                description: ArtifactValidation::string($command, self::ARTIFACT_COMPONENT, 'description'),
                controllerClass: ArtifactValidation::string($command, self::ARTIFACT_COMPONENT, 'controllerClass'),
                controllerMethod: ArtifactValidation::string($command, self::ARTIFACT_COMPONENT, 'controllerMethod'),
                takesArguments: ArtifactValidation::bool($command, self::ARTIFACT_COMPONENT, 'takesArguments'),
                bootstrap: ArtifactValidation::bool($command, self::ARTIFACT_COMPONENT, 'bootstrap'),
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
