<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection;

use Kinetis\Reflection\AttributeScope;
use Kinetis\Reflection\Exception\AttributeScopeException;
use Kinetis\Tests\Reflection\Fixtures\AbstractRouted;
use Kinetis\Tests\Reflection\Fixtures\InheritsRoute;
use Kinetis\Tests\Reflection\Fixtures\PlainConcrete;
use Kinetis\Tests\Reflection\Fixtures\RoutedEnum;
use Kinetis\Tests\Reflection\Fixtures\RoutedInterface;
use Kinetis\Tests\Reflection\Fixtures\RoutedTrait;
use Kinetis\Tests\Reflection\Fixtures\UsesRoutedTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AttributeScopeTest extends TestCase
{
    public function test_reflects_a_concrete_class(): void
    {
        $this->assertSame(
            PlainConcrete::class,
            AttributeScope::reflect(PlainConcrete::class)->getName(),
        );
    }

    /**
     * @return list<array{class-string, string}>
     */
    public static function unregistrableClasses(): array
    {
        return [
            [AbstractRouted::class, 'abstract'],
            [RoutedInterface::class, 'an interface'],
            [RoutedTrait::class, 'a trait'],
            [RoutedEnum::class, 'an enum'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unregistrableClasses')]
    public function test_rejects_a_class_that_cannot_be_registered(string $class, string $kind): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage("\"{$class}\" is {$kind} and cannot be registered.");

        AttributeScope::reflect($class);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unregistrableClasses')]
    public function test_is_not_registrable_for_the_same_classes(string $class, string $kind): void
    {
        $this->assertFalse(AttributeScope::isRegistrable($class), $kind);
    }

    public function test_is_registrable_for_a_concrete_class(): void
    {
        $this->assertTrue(AttributeScope::isRegistrable(PlainConcrete::class));
    }

    public function test_is_not_registrable_for_a_class_that_does_not_exist(): void
    {
        $this->assertFalse(AttributeScope::isRegistrable('Kinetis\Tests\Reflection\Fixtures\NoSuchClass'));
    }

    public function test_a_class_declares_its_own_method(): void
    {
        $method = new ReflectionMethod(PlainConcrete::class, 'plain');

        $this->assertTrue(AttributeScope::declares($method, PlainConcrete::class));
    }

    /**
     * PHP reports a trait method's declaring class as the class that uses
     * the trait, which is what makes a trait the supported way to share a
     * routed method.
     */
    public function test_a_trait_method_counts_as_the_using_class_own(): void
    {
        $method = new ReflectionMethod(UsesRoutedTrait::class, 'fromTrait');

        $this->assertSame(UsesRoutedTrait::class, $method->getDeclaringClass()->getName());
        $this->assertTrue(AttributeScope::declares($method, UsesRoutedTrait::class));
    }

    public function test_an_inherited_method_is_not_the_child_own(): void
    {
        $method = new ReflectionMethod(InheritsRoute::class, 'fromParent');

        $this->assertFalse(AttributeScope::declares($method, InheritsRoute::class));
    }

    public function test_assert_declares_passes_for_an_own_method(): void
    {
        AttributeScope::assertDeclares(
            new ReflectionMethod(PlainConcrete::class, 'plain'),
            PlainConcrete::class,
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_declares_names_both_classes_for_an_inherited_method(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(sprintf(
            '"%s::fromParent()" carries an attribute but is declared by "%s", not by "%s".',
            InheritsRoute::class,
            AbstractRouted::class,
            InheritsRoute::class,
        ));

        AttributeScope::assertDeclares(
            new ReflectionMethod(InheritsRoute::class, 'fromParent'),
            InheritsRoute::class,
        );
    }
}
