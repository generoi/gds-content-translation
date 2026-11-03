<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\MachineTranslation;
use GeneroWP\ContentTranslation\Tests\PolylangTestCase;
use ReflectionMethod;

/**
 * The lock that keeps two requests from creating the same translation
 * (MachineTranslation): only its holder renews or releases it.
 */
class CreationLockTest extends PolylangTestCase
{
    private const name = 'gds_ct_lock_1_fi';

    protected function tearDown(): void
    {
        delete_option(self::name);

        parent::tearDown();
    }

    private function call(MachineTranslation $creator, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($creator, $method))->invoke($creator, ...$args);
    }

    private function value(): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::name));
    }

    public function test_only_the_holder_releases_the_lock(): void
    {
        $first = new MachineTranslation;
        $second = new MachineTranslation;

        $this->assertTrue($this->call($first, 'acquireLock', 1, 'fi'));
        $this->assertFalse($this->call($second, 'acquireLock', 1, 'fi'));

        // Given up for dead and taken over: the first one's release leaves the new holder's lock.
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => (time() - HOUR_IN_SECONDS).':abc'], ['option_name' => self::name]);
        $this->assertTrue($this->call($second, 'acquireLock', 1, 'fi'));
        $held = $this->value();

        $this->call($first, 'releaseLock');
        $this->assertSame($held, $this->value());

        $this->call($second, 'releaseLock');
        $this->assertSame('', $this->value());
    }

    public function test_a_long_translation_keeps_its_lock(): void
    {
        $creator = new MachineTranslation;
        $this->assertTrue($this->call($creator, 'acquireLock', 1, 'fi'));

        // Renewed by its requests to DeepL, at most every half a minute.
        $old = (time() - 4 * MINUTE_IN_SECONDS).substr($this->value(), strpos($this->value(), ':'));
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => $old], ['option_name' => self::name]);
        (new \ReflectionProperty($creator, 'lock'))->setValue($creator, [self::name, $old]);

        $creator->refreshLock();

        $this->assertSame(time(), (int) $this->value());
        $this->assertFalse($this->call(new MachineTranslation, 'acquireLock', 1, 'fi'), 'Not taken over.');
    }
}
